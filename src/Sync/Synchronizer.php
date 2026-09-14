<?php

namespace Plusest\Site\Sync;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Db;
use Plusest\Site\Exception\FeedRefusedException;
use Plusest\Site\Exception\SiteException;
use Plusest\Site\Feed\Client;
use Plusest\Site\Feed\Feed;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Photo\PhotoSync;
use Plusest\Site\Repository\MetroRepository;
use Plusest\Site\Repository\ObjectRepository;
use Plusest\Site\Repository\StaffRepository;
use Plusest\Site\Support\Arr;

/**
 * Синхронізація локальної бази з фідом CRM.
 *
 * Що робить один прогін
 * ---------------------
 *  1. Перевіряє, чи не закрила CRM доступ агентству назавжди (клас Ban), і
 *     бере блокування, щоб не накластися на попередній прогін.
 *  2. Оновлює моделі даних, якщо їхня копія застаріла (ModelStore::TTL).
 *  3. Забирає фід із CRM — якщо цього разу настав час за розкладом
 *     (див. клас Schedule).
 *  4. Оновлює співробітників.
 *  5. Проходить публікації обʼєктів: нові вставляє, змінені оновлює,
 *     незмінені пропускає, торкнувшись лише seenAt. Разом із публікацією
 *     переписуються її станції метро в таблиці objectMetro.
 *  6. Розбирається з публікаціями, які зникли з фіда — за правилом із
 *     налаштування sync.missingMode.
 *  7. Видаляє публікації звільнених співробітників.
 *  8. Завантажує та масштабує фотографії.
 *  9. Пише підсумки в таблицю syncState і в лог.
 *
 * Головна економія — на кроці 4. Для кожної публікації рахується хеш її
 * даних; якщо він збігся з тим, що вже в базі, рядок не перезаписується.
 * На базі з кількох тисяч обʼєктів, де за годину змінилось три, прогін
 * робить три записи замість трьох тисяч.
 *
 * Чому фід і фотографії розділені
 * -------------------------------
 * CRM обмежує частоту звернень до фіда, щоб її сервер не перевантажували.
 * Фотографії ж лежать у хмарному сховищі й таких обмежень не мають, а
 * завантаження кількох тисяч файлів на першому запуску може не вміститись
 * в один прогін.
 *
 * Тому завдання cron ставиться часто — раз на 15 хвилин, — крок 2 виконується
 * за розкладом (уночі й у вихідні рідше, після відмови CRM — з великою
 * паузою), а крок 7 — кожним прогоном. Прогін, що на розклад не потрапив,
 * не марний: він дозавантажує фотографії, не турбуючи CRM.
 *
 * Стовпці hashPhotosDisk і photosCount цей клас не торкає: вони описують
 * стан файлів на диску, і їх заповнює лише PhotoSync — після того, як файли
 * реально записані.
 */
class Synchronizer
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Db
     */
    private $db;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ObjectRepository
     */
    private $objects;

    /**
     * @var MetroRepository
     */
    private $metro;

    /**
     * @var StaffRepository
     */
    private $staff;

    /**
     * @var StateStore
     */
    private $state;

    /**
     * @var Lock
     */
    private $lock;

    /**
     * @var Ban
     */
    private $ban;

    /**
     * @param Config      $config Налаштування
     * @param Db|null     $db     Підключення до бази; створюється з конфігу, якщо не передане
     * @param Logger|null $logger Куди писати хід роботи
     */
    public function __construct(Config $config, Db $db = null, Logger $logger = null)
    {
        $this->config = $config;
        $this->db = $db === null ? new Db((array) $config->get('db', [])) : $db;

        if ($logger === null) {
            $logger = new Logger(
                $config->get('sync.log', true) ? $config->path('paths.data', 'sync.log') : null,
                $config->get('sync.logLines', 5000)
            );
        }

        $this->logger = $logger;
        $this->objects = new ObjectRepository($this->db);
        $this->metro = new MetroRepository($this->db);
        $this->staff = new StaffRepository($this->db);
        $this->state = new StateStore($this->db);
        $this->lock = new Lock($config->path('paths.data', 'sync.lock'));
        $this->ban = Ban::fromConfig($config);
    }

    /**
     * Виконує повний прогін синхронізації.
     *
     * @param bool $force Не звертати уваги на розклад і запитати фід негайно.
     *                    Потрібно при налагодженні: інакше після виправлення
     *                    налаштувань довелось би чекати години
     *
     * @return Result Підсумки
     *
     * @throws SiteException Якщо прогін не вдалося виконати зовсім:
     *                       немає доступу до CRM, немає таблиць у базі
     */
    public function run($force = false)
    {
        $result = new Result();

        // --- Доступ закрито назавжди -----------------------------------------
        // Найперша перевірка прогону: ні до бази, ні до CRM звертатись не
        // треба. Позначку ставить syncFeed(), коли CRM повідомляє, що
        // агентство заблоковане остаточно; знімається вона лише вручну.
        if ($this->ban->exists()) {
            $reason = $this->ban->reason();

            $this->logger->warn(
                'Синхронізацію вимкнено: CRM закрила доступ агентству назавжди'
                . ($reason === null ? '' : ' (' . $reason . ')') . '.'
                . ' Щоб увімкнути її знову, видаліть файл ' . $this->ban->file() . '.'
            );
            $this->logger->close();

            return $result;
        }

        // --- Блокування -----------------------------------------------------
        if (!$this->lock->acquire()) {
            // Хто саме тримає блокування, дізнатись вдається не завжди:
            // на Windows файл під блокуванням не читається.
            $owner = $this->lock->owner();

            $this->logger->warn(
                'Синхронізація вже виконується' . ($owner === null ? '' : ' (' . $owner . ')')
                . ', пропускаю прогін.'
            );
            $this->logger->close();

            return $result;
        }

        try {
            $this->logger->write('=== Початок синхронізації ===');

            $this->assertTables();

            // Моделі даних живуть за власним розкладом (ModelStore::TTL)
            // і від розкладу звернень до фіда не залежать.
            $this->syncModels($result);

            // Фід запитуємо не щоразу: CRM обмежує частоту звернень.
            if ($this->shouldFetchFeed($force)) {
                $this->syncFeed($result);
            }

            // А фотографії — щоразу: вони качаються зі сховища, і незавершена
            // з минулого разу робота має продовжитись.
            $this->syncPhotos($result);

            $this->saveState($result);

            foreach ($result->lines() as $line) {
                $this->logger->write($line);
            }

            $this->logger->write('=== Синхронізація завершена ===');
        } catch (Exception $e) {
            // Записуємо помилку в базу, щоб її було видно на сторінці стану,
            // а не лише в логу, який користувач може не знайти.
            //
            // Сам запис теж може впасти — наприклад, коли таблиць у базі ще
            // немає або зникло підключення. У такому разі просто мовчимо:
            // головне не втратити початкову помилку, підмінивши її вторинною.
            try {
                $this->state->set(StateStore::LAST_ERROR, $e->getMessage());
                $this->state->set(StateStore::LAST_ERROR_AT, date('Y-m-d H:i:s'));
            } catch (Exception $ignored) {
                // Нічого не робимо: повідомлення про справжню причину
                // піде в лог і буде передане далі.
            }

            $this->logger->error($e->getMessage());
            $this->logger->close();
            $this->lock->release();

            throw $e;
        }

        $this->logger->close();
        $this->lock->release();

        return $result;
    }

    /**
     * Логер прогону — щоб зовнішній код міг підписатися на вивід.
     *
     * @return Logger
     */
    public function logger()
    {
        return $this->logger;
    }

    /**
     * Підключення до бази — знадобиться скрипту, що показує стан.
     *
     * @return Db
     */
    public function db()
    {
        return $this->db;
    }

    /**
     * Перевіряє, що база готова приймати дані.
     *
     * Робиться до запиту в CRM: немає сенсу тягнути мегабайти даних, щоб
     * потім впасти на першому ж INSERT.
     *
     * @return void
     *
     * @throws SiteException Якщо таблиць немає
     */
    private function assertTables()
    {
        if ($this->db->tableExists('objects')) {
            return;
        }

        throw new SiteException(
            'У базі немає таблиці ' . $this->db->table('objects') . '.' . "\n"
            . 'Виконайте: php vendor/bin/plusestSite migrate --config=...'
        );
    }

    /**
     * Чи час знову звертатися до CRM.
     *
     * Саме тут вирішується, що робити цим прогоном: повну синхронізацію чи
     * лише дозавантаження фотографій. Рішення приймає клас Schedule — за
     * часом останнього звернення, часом доби і тим, чи не відмовляла CRM
     * минулого разу.
     *
     * @param bool $force Ігнорувати розклад
     *
     * @return bool
     */
    private function shouldFetchFeed($force)
    {
        if ($force) {
            return true;
        }

        $decision = Schedule::fromConfig($this->config)->due(
            $this->stateTime(StateStore::LAST_FEED_AT),
            $this->stateTime(StateStore::FEED_RETRY_AFTER)
        );

        if ($decision['due']) {
            return true;
        }

        $wait = Schedule::formatDuration($decision['wait']);

        if ($decision['reason'] === Schedule::REASON_ERROR) {
            $this->logger->write(
                'Минулого разу CRM відмовила у видачі даних, тому наступна спроба —'
                . ' через ' . $wait . '. Продовжую роботу з фотографіями'
                . ' (щоб спробувати негайно, додайте --force).'
            );
        } else {
            $this->logger->write(
                'За розкладом наступний запит фіда через ' . $wait
                . ' (інтервал зараз — ' . Schedule::formatDuration($decision['interval'])
                . '). Продовжую роботу з фотографіями.'
            );
        }

        return false;
    }

    /**
     * Читає з syncState значення-час і повертає його як unix-час.
     *
     * @param string $name Назва значення
     *
     * @return int|null null, якщо значення немає або воно нечитабельне
     */
    private function stateTime($name)
    {
        $value = $this->state->get($name);

        if ($value === null) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * Забирає фід і оновлює за ним обʼєкти та співробітників.
     *
     * @param Result $result Лічильники
     *
     * @return void
     *
     * @throws SiteException Якщо фід не отримано
     */
    private function syncFeed(Result $result)
    {
        try {
            $feed = $this->fetchFeed();
        } catch (FeedRefusedException $e) {
            // Агентство заблоковане назавжди — відкладати наступну спробу
            // немає сенсу, її не буде взагалі.
            if ($e->isPermanent()) {
                $this->banForever($e->reason());

                throw $e;
            }

            // CRM відповіла і відмовила: невірне посилання, не оплачений
            // доступ, забагато запитів. Далі не працюємо, а наступне
            // звернення відкладаємо надовго — щоб не довбати сервер щочверть
            // години, поки людина не розбереться з причиною.
            $until = time() + Schedule::ERROR_INTERVAL;

            $this->state->set(StateStore::FEED_RETRY_AFTER, date('Y-m-d H:i:s', $until));

            $this->logger->warn(
                'Наступна спроба звернутись до CRM — не раніше '
                . date('Y-m-d H:i', $until) . ' (через '
                . Schedule::formatDuration(Schedule::ERROR_INTERVAL) . ').'
            );

            throw $e;
        }

        // Успішний запит знімає паузу, накладену попередньою відмовою.
        $this->state->forget(StateStore::FEED_RETRY_AFTER);

        $result->markFeedFetched();

        $now = date('Y-m-d H:i:s');

        $staff = $this->syncStaff($feed, $result, $now);

        // syncObjects повертає публікації, які є в базі, але зникли з фіда.
        $missing = $this->syncObjects($feed, $result, $now, $staff['roster']);

        $this->handleMissing($missing, $result, $now);
        $this->removeFiredStaff($staff, $result);
        $this->syncMetro($result);
    }

    /**
     * Вимикає синхронізацію назовсім.
     *
     * Викликається один раз — коли CRM повідомила, що агентство заблоковане
     * остаточно. Ставить позначку в службовому каталозі, після якої кожен
     * наступний прогін завершується на першому ж рядку run(), не звертаючись
     * ні до бази, ні до CRM.
     *
     * Саме виключення після цього летить далі: прогін має завершитися з
     * помилкою, щоб cron надіслав листа і людина дізналася про блокування
     * не з логу, який ніхто не читає.
     *
     * @param string|null $reason Причина від CRM
     *
     * @return void
     */
    private function banForever($reason)
    {
        $reason = $reason === null ? FeedRefusedException::BANNED_FOREVER : $reason;

        if (!$this->ban->set($reason)) {
            // Каталог недоступний для запису. Це погано: без позначки прогони
            // й далі битимуться в CRM, а вона вже сказала все, що мала.
            $this->logger->error(
                'CRM закрила доступ агентству назавжди (' . $reason . '), але створити файл '
                . $this->ban->file() . ' не вдалося. Перевірте права на службовий каталог:'
                . ' поки файлу немає, кожен прогін і далі звертатиметься до CRM.'
            );

            return;
        }

        $this->logger->error(
            'CRM закрила доступ агентству назавжди (' . $reason . ').'
            . ' Синхронізацію вимкнено: створено файл ' . $this->ban->file() . '.'
            . ' Наступні прогони завершуватимуться одразу, запити до CRM більше не надсилатимуться.'
            . ' Якщо доступ відновили — видаліть цей файл.'
        );
    }

    /**
     * Виконує запит фіда.
     *
     * @return Feed
     *
     * @throws SiteException Якщо фід не отримано
     */
    private function fetchFeed()
    {
        $logger = $this->logger;

        $client = Client::fromConfig($this->config)->setLogger(function ($message) use ($logger) {
            $logger->write($message);
        });

        $this->logger->write('Запит фіда з CRM...');

        // Час звернення записуємо ДО запиту: CRM рахує в ліміті і невдалі
        // спроби теж, тому пауза мусить починатись від самої спроби.
        $this->state->set(StateStore::LAST_FEED_AT, date('Y-m-d H:i:s'));

        $feed = $client->fetch();

        $items = count($feed->items());

        $this->logger->write('У фіді публікацій: ' . $items . ', співробітників: ' . count($feed->staff()));

        // Розбіжність означає, що частина елементів фіда не пройшла перевірку
        // (напр. прийшла без objectPublicationId) — про це варто знати.
        if ($feed->total() !== $items) {
            $this->logger->warn(
                'CRM повідомила про ' . $feed->total() . ' публікацій, а придатних до обробки — ' . $items . '.'
            );
        }

        // Адреса сховища та ознака водяного знака потрібні синхронізації
        // фотографій і шаблонам. Зберігаємо їх одразу: вони приходять у фіді
        // й можуть змінитись, а фотографії качаються й на тих прогонах, де
        // фід не запитували.
        $this->state->set('cloudUrl', $feed->cloud());
        $this->state->set('watermark', $feed->watermark());

        // Час першого успішного отримання фіда. Записуємо один раз: за ним
        // сторінки сайту відрізняють обʼєкт, що справді щойно зʼявився, від
        // тисячі публікацій, які разом упали в базу при встановленні.
        if ($this->state->get(StateStore::FIRST_SYNC_AT) === null) {
            $this->state->set(StateStore::FIRST_SYNC_AT, date('Y-m-d H:i:s'));
        }

        return $feed;
    }

    /**
     * Оновлює співробітників агентства.
     *
     * @param Feed   $feed   Фід
     * @param Result $result Куди писати лічильники
     * @param string $now    Поточний час
     *
     * @return array{roster: array, fired: string[]}
     *               roster — склад агентства з фіда у вигляді «agentId => true»;
     *               fired  — ті, кого в базі є, а у фіді вже немає
     */
    private function syncStaff(Feed $feed, Result $result, $now)
    {
        $people = $feed->staff();
        $result->add('staffInFeed', count($people));

        $index = $this->staff->syncIndex();
        $roster = [];

        foreach ($people as $person) {
            try {
                $row = StaffMapper::toRow($person, $now);
                $agentId = $row['agentId'];
                $roster[$agentId] = true;

                // Дані не змінились — торкаємось лише seenAt.
                if (isset($index[$agentId]) && $index[$agentId]['hashData'] === $row['hashData']) {
                    $this->staff->touch($agentId, $now);
                    $result->add('staffSkipped');

                    continue;
                }

                $result->add($this->staff->save($row) ? 'staffCreated' : 'staffUpdated');
            } catch (Exception $e) {
                $result->addError('Співробітник ' . Arr::str($person, 'agentId', 24) . ': ' . $e->getMessage());
                $this->logger->warn('Помилка на співробітнику: ' . $e->getMessage());
            }
        }

        return [
            'roster' => $roster,
            'fired'  => array_values(array_diff(array_keys($index), array_keys($roster))),
        ];
    }

    /**
     * Видаляє звільнених співробітників і всі їхні публікації.
     *
     * Співробітник зник із фіда — значить його звільнили. Обʼєкт, у контактах
     * якого стоїть звільнений агент, показувати не можна: відвідувач сайту
     * зателефонує в нікуди. Тому такі публікації видаляються безповоротно,
     * незалежно від налаштування sync.missingMode.
     *
     * Разом із ними прибираються й публікації, чий agentId узагалі не
     * зустрічається у складі агентства — це той самий випадок.
     *
     * Захист від помилки: якщо у фіді не прийшло жодного співробітника, не
     * видаляємо нічого. У відповіді status = success CRM завжди передає масив
     * staff — публікацій може не бути, а штат буде. Тому порожній список
     * означає збій, і будувати на ньому видалення даних не можна.
     *
     * @param array  $staff  Результат syncStaff()
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function removeFiredStaff(array $staff, Result $result)
    {
        if ($staff['roster'] === []) {
            $this->logger->warn(
                'У фіді немає жодного співробітника — видалення пропускаю.'
                . ' Перевірте публікацію в кабінеті CRM.'
            );

            return;
        }

        if ($staff['fired'] !== []) {
            $deleted = $this->staff->delete($staff['fired']);
            $result->add('staffDeleted', $deleted);

            $this->logger->write('Немає у фіді (звільнені) співробітників: ' . $deleted);
        }

        // Виконуємо навіть коли звільнених цього разу не було: так само
        // прибираються обʼєкти, що посилаються на агента, якого в складі
        // агентства ніколи й не було.
        $orphans = $this->objects->deleteWithoutStaff();

        if ($orphans > 0) {
            $result->add('objectsFired', $orphans);

            $this->logger->write(
                'Видалено публікацій, у яких автор більше не працює в агентстві: ' . $orphans
            );
        }
    }

    /**
     * Основний крок: проходить публікації обʼєктів.
     *
     * @param Feed   $feed   Фід
     * @param Result $result Лічильники
     * @param string $now    Поточний час
     * @param array  $roster Склад агентства «agentId => true»
     *
     * @return string[] Публікації, які є в базі, але зникли з фіда
     */
    private function syncObjects(Feed $feed, Result $result, $now, array $roster)
    {
        $items = $feed->items();
        $result->add('objectsInFeed', count($items));

        // Один запит на всю таблицю замість запиту на кожну публікацію.
        $index = $this->objects->syncIndex();

        foreach ($items as $item) {
            try {
                $row = ObjectMapper::toRow($item, $now);
                $publicationId = $row['objectPublicationId'];

                // Автора публікації немає у складі агентства — його звільнили.
                // Не записуємо такий обʼєкт узагалі: інакше кожен прогін
                // додавав би рядок, який тут же видаляє removeFiredStaff().
                if ($roster !== [] && $row['agentId'] !== null && !isset($roster[$row['agentId']])) {
                    continue;
                }

                $existing = isset($index[$publicationId]) ? $index[$publicationId] : null;

                // Дані не змінились — не перезаписуємо рядок.
                if ($existing !== null && $existing['hashData'] === $row['hashData']) {
                    // Але якщо публікація раніше зникала з фіда і була
                    // позначена реалізованою чи прихованою, статус треба
                    // повернути: вона знову актуальна.
                    $restore = $existing['status'] !== ObjectRepository::STATUS_ACTIVE;

                    $this->objects->touch($publicationId, $now, $restore);
                    $result->add('objectsSkipped');

                    if ($restore) {
                        $this->logger->write('Публікація ' . $publicationId . ' знову у фіді, статус повернуто в active.');
                    }

                    continue;
                }

                $result->add($this->objects->save($row) ? 'objectsCreated' : 'objectsUpdated');

                // Станції метро дублюємо в окрему таблицю: фільтр «поруч із
                // метро» по JSON у стовпці metro працював би перебором.
                $this->metro->replace(
                    $publicationId,
                    Arr::toArray($item, 'location.metroStations'),
                    $row['cityId']
                );
            } catch (Exception $e) {
                // Один зіпсований обʼєкт не має ламати весь прогін.
                $result->addError('Публікація ' . Arr::str($item, 'objectPublicationId', 24) . ': ' . $e->getMessage());
                $this->logger->warn('Помилка на публікації: ' . $e->getMessage());
            }
        }

        // Зниклими вважаємо ті публікації, що були в базі до прогону, але
        // яких немає у фіді. Порівнюємо саме списки ідентифікаторів, а не
        // час seenAt: публікація, на якій сталася помилка обробки, у фіді
        // все ж присутня, і позначати її реалізованою чи видаляти не можна.
        return array_values(array_diff(array_keys($index), $feed->publicationIds()));
    }

    /**
     * Розбирається з публікаціями, яких більше немає у фіді.
     *
     * Поведінка визначається налаштуванням sync.missingMode:
     *
     *   sold   — позначити реалізованими (обʼєкт залишається на сайті);
     *   hidden — прибрати з пошуку, але зберегти за прямим посиланням;
     *   delete — видалити рядок.
     *
     * @param string[] $missing Публікації, яких немає у фіді
     * @param Result   $result  Лічильники
     * @param string   $now     Поточний час
     *
     * @return void
     */
    private function handleMissing(array $missing, Result $result, $now)
    {
        if ($missing === []) {
            return;
        }

        $mode = (string) $this->config->get('sync.missingMode', ObjectRepository::STATUS_SOLD);

        // Захист від помилки в конфізі: краще позначити реалізованими, ніж
        // видалити дані через опечатку в назві режиму.
        if (!in_array($mode, ['sold', 'hidden', 'delete'], true)) {
            $this->logger->warn(
                'Невідоме значення sync.missingMode = "' . $mode . '", використовую "sold".'
            );

            $mode = ObjectRepository::STATUS_SOLD;
        }

        $this->logger->write('Публікацій зникло з фіда: ' . count($missing) . ', режим обробки: ' . $mode);

        if ($mode === 'delete') {
            // Каталоги з фотографіями видалених публікацій прибирає
            // синхронізація фотографій: вона порівнює вміст каталогу зі
            // списком публікацій у базі, тому окремий перелік їй не потрібен.
            $result->add('objectsDeleted', $this->objects->delete($missing));

            return;
        }

        $status = $mode === 'hidden' ? ObjectRepository::STATUS_HIDDEN : ObjectRepository::STATUS_SOLD;
        $changed = $this->objects->setStatus($missing, $status, $now);

        $result->add($mode === 'hidden' ? 'objectsHidden' : 'objectsSold', $changed);
    }

    /**
     * Завантажує фотографії.
     *
     * Помилки цього кроку не скасовують уже виконану синхронізацію даних:
     * обʼєкти в базі оновлені, і сайт працює — просто частина картинок
     * зʼявиться наступним прогоном. Тому помилку записуємо в підсумки
     * (прогін завершиться з кодом 1, і cron надішле листа), але роботу
     * не перериваємо.
     *
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function syncPhotos(Result $result)
    {
        try {
            $photos = new PhotoSync($this->config, $this->db, $this->logger);
            $photos->run($result);
        } catch (Exception $e) {
            $result->addError('Фотографії: ' . $e->getMessage());
            $this->logger->error('Синхронізація фотографій не вдалася: ' . $e->getMessage());
        }
    }

    /**
     * Оновлює моделі даних, за якими шаблони підписують поля обʼєкта.
     *
     * Моделі змінюються рідко — коли в CRM додають нове поле або нове
     * значення, — тому власний розклад у них простий: раз на добу
     * (константа ModelStore::TTL). Від розкладу звернень до фіда це не
     * залежить:
     * моделі лежать статичними файлами на plusest.app, а не в CRM.
     *
     * Невдале завантаження прогін не зупиняє. Якщо на диску вже є копія
     * моделі, сайт працює далі з нею; якщо копії немає — шаблони покажуть
     * машинні ключі замість підписів, і ось про це вже треба сказати гучно.
     *
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function syncModels(Result $result)
    {
        $models = new ModelStore($this->config);

        foreach ($models->refresh() as $name => $status) {
            if ($status === 'fresh') {
                continue;
            }

            if ($status === 'downloaded') {
                $result->add('modelsUpdated');
                $this->logger->write('Оновлено модель даних: ' . $name);

                continue;
            }

            // Тут $status — це текст помилки.
            if ($models->get($name) === []) {
                $message = 'Не вдалося завантажити модель "' . $name . '": ' . $status
                    . ' Без неї сторінки обʼєктів показуватимуть службові назви полів.';

                $result->addError($message);
                $this->logger->error($message);

                continue;
            }

            $this->logger->warn(
                'Не вдалося оновити модель "' . $name . '": ' . $status
                . ' Працюємо з копією від ' . $models->updatedAt($name) . '.'
            );
        }
    }

    /**
     * Приводить до ладу таблицю станцій метро.
     *
     * Таблиця objectMetro — похідна від objects: її заповнює replace() при
     * кожному записі публікації. Але два випадки replace() не покриває:
     *
     *   * публікацію видалили — рядки станцій треба прибрати;
     *   * заготовку щойно оновили на версію з цією таблицею, а публікації в
     *     базі вже лежали. Їхні хеші збігаються з фідом, тому рядки не
     *     перезаписуються, і replace() для них не викликається ніколи.
     *
     * Обидва запити дешеві й виконуються по індексах, тому робимо це щоразу.
     *
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function syncMetro(Result $result)
    {
        $result->add('metroRemoved', $this->metro->deleteOrphans());

        $filled = $this->metro->backfill();

        if ($filled > 0) {
            $result->add('metroFilled', $filled);
            $this->logger->write('Заповнено станції метро для публікацій: ' . $filled);
        }
    }

    /**
     * Записує підсумки прогону в таблицю syncState.
     *
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function saveState(Result $result)
    {
        $this->state->set(StateStore::LAST_SYNC_AT, date('Y-m-d H:i:s'));
        $this->state->set(StateStore::LAST_SYNC_RESULT, $result->toArray());

        // Успішний прогін знімає позначку попередньої помилки.
        if (!$result->hasErrors()) {
            $this->state->forget(StateStore::LAST_ERROR);
            $this->state->forget(StateStore::LAST_ERROR_AT);
        } else {
            $this->state->set(StateStore::LAST_ERROR, implode("\n", $result->errors()));
            $this->state->set(StateStore::LAST_ERROR_AT, date('Y-m-d H:i:s'));
        }
    }
}
