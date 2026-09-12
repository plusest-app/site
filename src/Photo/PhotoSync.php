<?php

namespace Plusest\Site\Photo;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Db;
use Plusest\Site\Exception\SiteException;
use Plusest\Site\Repository\ObjectRepository;
use Plusest\Site\Repository\StaffRepository;
use Plusest\Site\Support\Arr;
use Plusest\Site\Support\Fs;
use Plusest\Site\Sync\Logger;
use Plusest\Site\Sync\Result;
use Plusest\Site\Sync\StateStore;

/**
 * Завантаження та масштабування фотографій обʼєктів і співробітників.
 *
 * Чому це окремий крок, а не частина Synchronizer
 * -----------------------------------------------
 * Забір фіда — це один запит на кілька секунд. Завантаження фотографій —
 * сотні запитів, які на першому прогоні можуть тривати годинами. Тому кроки
 * розділені: CRM обмежує частоту звернень до фіда, а фотографії качаються з
 * хмарного сховища й таких обмежень не мають. Синхронізація фотографій
 * виконується кожним прогоном cron, навіть коли фід цього разу не запитували.
 *
 * Що вважається «фотографії актуальні»
 * ------------------------------------
 * У таблиці objects два стовпці:
 *
 *   hashPhotos     — md5 списку фотографій із фіда. Пише синхронізація даних;
 *   hashPhotosDisk — md5 того самого списку, але записаний лише ПІСЛЯ того,
 *                    як усі файли справді лягли на диск.
 *
 * Значить, «є що робити» — це рядки, де hashPhotosDisk відрізняється від
 * hashPhotos. Один запит, ніякого розбору JSON, і робота продовжується з того
 * місця, де її перервали: обмеженням sync.photoLimit, збоєм сховища або
 * таймаутом хостингу.
 *
 * Куди зберігаються файли
 * -----------------------
 * Кожна публікація отримує власний підкаталог, названий її ідентифікатором,
 * а в ньому лежить по одному файлу на кожен розмір із конфігу:
 *
 *     {paths.photos}/objects/{objectPublicationId}/{idФото}-{назваРозміру}.jpg
 *
 * Наприклад:
 *
 *     public/photos/objects/6899c00963679e68c44d93c7/1754841958-TjwOj-large.jpg
 *     public/photos/objects/6899c00963679e68c44d93c7/1754841958-TjwOj-medium.jpg
 *     public/photos/objects/6899c00963679e68c44d93c7/1754841958-TjwOj-preview.jpg
 *
 * Фотографія співробітника — виняток: розмірів у неї немає, вона зберігається
 * одним файлом такою, як прийшла зі сховища:
 *
 *     {paths.photos}/staff/{agentId}/{photo}.jpg
 *
 * Один каталог на публікацію — не лише для порядку: коли публікацію
 * видаляють, достатньо видалити каталог цілком, не розбираючись, які саме
 * файли їй належали.
 *
 * Адреси у сховищі CRM
 * --------------------
 *     {cloud}{source}/{id}-{publish|2500x2500}.jpg   — фотографія обʼєкта
 *     {cloud}agent/{agentId}/{photo}.jpg              — фотографія співробітника
 *
 * cloud і ознака водяного знака приходять у фіді (їх зберігає у syncState
 * синхронізація даних); source та id — у кожній фотографії обʼєкта, а photo —
 * у співробітнику.
 */
class PhotoSync
{
    /**
     * Каталог для фотографій обʼєктів усередині paths.photos.
     */
    const DIR_OBJECTS = 'objects';

    /**
     * Каталог для фотографій співробітників усередині paths.photos.
     */
    const DIR_STAFF = 'staff';

    /**
     * Скільки збоїв мережі підряд означають «сховище недоступне».
     *
     * Після цього синхронізація фотографій припиняється до наступного
     * прогону: якщо сховище лежить, немає сенсу вичікувати таймаут на
     * кожній з тисячі фотографій.
     */
    const MAX_NETWORK_ERRORS = 5;

    /**
     * Підсумок завантаження однієї фотографії: файли записані.
     */
    const PHOTO_OK = 'ok';

    /**
     * Підсумок: фотографії у сховищі немає. Повторювати марно — її видалили
     * в CRM, а список у фіді ще не оновили.
     */
    const PHOTO_GONE = 'gone';

    /**
     * Підсумок: збій, який може минути. Фотографія лишається на наступний
     * прогін cron, а публікація не позначається як завершена.
     */
    const PHOTO_RETRY = 'retry';

    /**
     * Шлях у сховищі, за яким лежать фотографії співробітників.
     *
     * У фіді співробітник має лише поле photo з ідентифікатором файлу —
     * source, на відміну від фотографій обʼєктів, не передається, бо він
     * завжди однаковий і складається з agentId.
     *
     * Варіанта в адресі теж немає: у обʼєктів він залежить від водяного
     * знака, а фотографія співробітника лежить у сховищі одна:
     *
     *     https://…/agent/{agentId}/{photo}.jpg
     */
    const STAFF_SOURCE = 'agent/{agentId}';

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ObjectRepository
     */
    private $objects;

    /**
     * @var StaffRepository
     */
    private $staff;

    /**
     * @var StateStore
     */
    private $state;

    /**
     * @var Resizer
     */
    private $resizer;

    /**
     * @var Downloader
     */
    private $downloader;

    /**
     * Каталог із фотографіями (абсолютний шлях).
     *
     * @var string
     */
    private $photosDir;

    /**
     * Скільки фотографій дозволено завантажити за прогін. 0 — без обмеження.
     *
     * @var int
     */
    private $limit;

    /**
     * Скільки фотографій уже завантажено за цей прогін.
     *
     * @var int
     */
    private $downloaded = 0;

    /**
     * Скільки збоїв мережі підряд трапилось.
     *
     * @var int
     */
    private $networkErrors = 0;

    /**
     * Чи припинено обробку: скінчився ліміт або сховище недоступне.
     *
     * @var bool
     */
    private $stopped = false;

    /**
     * @param Config $config Налаштування
     * @param Db     $db     Підключення до бази
     * @param Logger $logger Куди писати хід роботи
     */
    public function __construct(Config $config, Db $db, Logger $logger)
    {
        $this->logger = $logger;
        $this->objects = new ObjectRepository($db);
        $this->staff = new StaffRepository($db);
        $this->state = new StateStore($db);
        $this->resizer = Resizer::fromConfig($config);
        $this->downloader = Downloader::fromConfig($config);
        $this->photosDir = $config->path('paths.photos');
        $this->limit = max(0, (int) $config->get('sync.photoLimit', 0));
    }

    /**
     * Виконує повний крок роботи з фотографіями.
     *
     * @param Result $result Лічильники прогону
     *
     * @return void
     */
    public function run(Result $result)
    {
        // Базова адреса сховища приходить у фіді. Якщо її ще немає, значить
        // жодного успішного прогону фіда не було — качати нізвідки.
        $cloud = (string) $this->state->get('cloudUrl', '');

        if ($cloud === '') {
            $this->logger->warn(
                'Адреса сховища фотографій невідома — спочатку потрібен успішний прогін фіда.'
            );

            return;
        }

        $watermark = (bool) $this->state->get('watermark', '1');

        Fs::ensureDir($this->photosDir);

        $this->syncObjectPhotos($result, $cloud, $watermark);
        $this->syncStaffPhotos($result, $cloud);
        $this->cleanupOrphans($result);
    }

    /**
     * Обробляє публікації, у яких фотографії на диску не відповідають фіду.
     *
     * @param Result $result    Лічильники
     * @param string $cloud     Базова адреса сховища
     * @param bool   $watermark Чи брати варіант із водяним знаком
     *
     * @return void
     */
    private function syncObjectPhotos(Result $result, $cloud, $watermark)
    {
        // Беремо лише ідентифікатори та хеші — без стовпця data, який на
        // кількох тисячах публікацій важив би десятки мегабайтів. Самі дані
        // читаються по одній публікації, і тільки для тих, де є що робити.
        $pending = $this->objects->photoCandidates();

        if ($pending === []) {
            return;
        }

        $this->logger->write('Публікацій, де потрібні фотографії: ' . count($pending));

        foreach ($pending as $publication) {
            if ($this->stopped) {
                $result->add('photosPublicationsPending');

                continue;
            }

            try {
                $this->syncPublication($publication, $result, $cloud, $watermark);
            } catch (Exception $e) {
                $result->addError('Фотографії публікації ' . $publication['objectPublicationId']
                    . ': ' . $e->getMessage());
                $this->logger->warn('Помилка на фотографіях публікації: ' . $e->getMessage());
                $result->add('photosPublicationsPending');
            }
        }

        if ($this->stopped) {
            $this->logger->write(
                'Решта фотографій завантажиться наступними прогонами cron.'
            );
        }
    }

    /**
     * Приводить у порядок каталог фотографій однієї публікації.
     *
     * @param array  $publication Рядок із photoCandidates()
     * @param Result $result      Лічильники
     * @param string $cloud       Базова адреса сховища
     * @param bool   $watermark   Чи брати варіант із водяним знаком
     *
     * @return void
     */
    private function syncPublication(array $publication, Result $result, $cloud, $watermark)
    {
        $publicationId = $publication['objectPublicationId'];
        $data = $this->objects->photoData($publicationId);

        // Рядок могли видалити між двома запитами — нічого страшного.
        if ($data === null) {
            return;
        }

        $photos = Arr::toArray(json_decode($data, true), 'photos');
        $dir = Fs::join($this->photosDir, self::DIR_OBJECTS, $publicationId);

        // Публікація без фотографій: прибираємо каталог і закриваємо питання.
        if ($photos === []) {
            $result->add('photosRemoved', $this->cleanupDir($dir, []));
            $this->objects->markPhotosDone($publicationId, $publication['hashPhotos'], 0);
            $result->add('photosPublicationsDone');

            return;
        }

        $expected = [];
        $stored = 0;
        $complete = true;

        foreach ($photos as $photo) {
            $id = Resizer::safeName(Arr::str($photo, 'id', 64));
            $source = Arr::str($photo, 'source', 190);

            if ($id === '' || $source === null) {
                $this->logger->warn(
                    'Публікація ' . $publicationId . ': фотографія без id або source — пропускаю.'
                );

                continue;
            }

            // Файли цієї фотографії мають залишитись у каталозі навіть якщо
            // завантажити її зараз не вдалося: інакше прибирання зайвого
            // видалило б те, що вже є.
            $expected = array_merge($expected, $this->resizer->fileNames($id));

            if ($this->resizer->hasAll($dir, $id)) {
                $stored++;

                continue;
            }

            if ($this->limitReached()) {
                $this->stopped = true;
                $complete = false;

                break;
            }

            // Варіант '-publish' у сховищі вже містить нанесений водяний
            // знак, '-2500x2500' — оригінал без нього.
            $url = $this->photoUrl($cloud, $source, $id, $watermark ? 'publish' : '2500x2500');

            $status = $this->download($dir, $id, $url, $result);

            if ($status === self::PHOTO_OK) {
                $stored++;

                continue;
            }

            // Збій, який може минути, не дає закрити публікацію: спробуємо
            // наступним прогоном. А от відсутність файлу у сховищі сама не
            // виправиться, тому публікацію вона незавершеною не робить.
            if ($status === self::PHOTO_RETRY) {
                $complete = false;
            }

            if ($this->stopped) {
                break;
            }
        }

        // Прибирати зайве можна лише коли публікація оброблена повністю.
        //
        // Інакше вийшло б так: у CRM замінили фотографії, сховище цієї
        // хвилини недоступне — і ми видалили старі файли, не отримавши
        // нових. Обʼєкт на сайті залишився б зовсім без картинок. Застаріла
        // фотографія — менша біда, ніж порожня галерея, тому прибирання
        // відкладаємо до прогону, який дійде до кінця.
        if (!$complete) {
            $result->add('photosPublicationsPending');

            return;
        }

        // Прибираємо файли фотографій, яких у фіді вже немає, файли розмірів,
        // що зникли з конфігу, і випадковий сміттєвий залишок.
        $result->add('photosRemoved', $this->cleanupDir($dir, $expected));

        // Позначку «фотографії на диску відповідають фіду» ставимо лише тут —
        // після того, як усі файли справді записані.
        $this->objects->markPhotosDone($publicationId, $publication['hashPhotos'], $stored);
        $result->add('photosPublicationsDone');
    }

    /**
     * Завантажує одну фотографію й, якщо потрібно, робить із неї всі розміри.
     *
     * @param string $dir    Каталог, куди складати файли
     * @param string $id     Ідентифікатор фотографії
     * @param string $url    Посилання у сховищі
     * @param Result $result Лічильники
     * @param bool   $resize Масштабувати у розміри з конфігу. false означає
     *                       «зберегти файл таким, як прийшов» — так
     *                       зберігаються фотографії співробітників
     *
     * @return string PHOTO_OK, PHOTO_GONE або PHOTO_RETRY
     */
    private function download($dir, $id, $url, Result $result, $resize = true)
    {
        Fs::ensureDir($dir);

        // Тимчасовий файл кладемо в той самий каталог: якщо прогін перервуть
        // посередині, залишок приберемо прибиранням зайвого, а не залишимо
        // в системному temp, до якого може не бути доступу.
        $temp = Fs::join($dir, $id . '.tmp' . getmypid());

        $downloaded = $this->downloader->toFile($url, $temp);

        if (!$downloaded['ok']) {
            @unlink($temp);
            $result->add('photosFailed');

            if (!$downloaded['retryable']) {
                // Фотографії за цією адресою немає й не буде. Публікацію
                // вважаємо обробленою, інакше вона вічно висіла б у черзі.
                $this->networkErrors = 0;

                $this->logger->warn(
                    'Фотографія ' . $id . ' недоступна (' . $downloaded['error'] . ') — пропускаю.'
                );

                return self::PHOTO_GONE;
            }

            $this->networkErrors++;

            $this->logger->warn(
                'Не вдалося завантажити фотографію ' . $id . ': ' . $downloaded['error']
            );

            if ($this->networkErrors >= self::MAX_NETWORK_ERRORS) {
                $this->stopped = true;

                $message = 'Підряд ' . $this->networkErrors . ' збоїв завантаження — сховище'
                    . ' фотографій схоже недоступне. Припиняю до наступного прогону.';

                // Це вже не дрібниця, а подія, про яку користувач мусить
                // дізнатися: прогін завершиться з кодом 1, і cron надішле
                // листа. Окремі невдалі фотографії так не позначаємо —
                // інакше листи приходили б через кожну дрібну помилку.
                $result->addError($message);
                $this->logger->error($message);
            }

            return self::PHOTO_RETRY;
        }

        if ($resize) {
            try {
                $this->resizer->process($temp, $dir, $id);
            } catch (Exception $e) {
                @unlink($temp);
                $result->add('photosFailed');

                throw $e;
            }

            @unlink($temp);
        } else {
            $target = Fs::join($dir, $id . '.jpg');

            // Обірваний попередній прогін міг залишити тут порожній файл, а
            // rename() перезаписує наявний не на всіх системах.
            @unlink($target);

            // Зберігаємо як прийшло. Що це справді зображення, а не сторінка
            // помилки, перевірив завантажувач за Content-Type — тут читати
            // файл бібліотекою обробки зображень уже нема потреби.
            if (!@rename($temp, $target)) {
                @unlink($temp);
                $result->add('photosFailed');

                throw new SiteException(
                    'Не вдалося зберегти фотографію ' . $id . ' у каталог ' . $dir . '.'
                );
            }
        }

        $this->downloaded++;
        $this->networkErrors = 0;
        $result->add('photosDownloaded');

        return self::PHOTO_OK;
    }

    /**
     * Фотографії співробітників.
     *
     * Окремого хеша тут не потрібно: ідентифікатор файлу у полі photo
     * змінюється разом із самою фотографією. Тому достатньо перевірити, чи є
     * на диску файл із таким ідентифікатором — а файл із попереднім
     * ідентифікатором прибереться як зайвий.
     *
     * Розмірів у цієї фотографії немає: у сховищі вона лежить одна, і на диск
     * кладеться такою, як прийшла. Масштабувати її нікуди — картка агента
     * показує один портрет, а не галерею.
     *
     * @param Result $result Лічильники
     * @param string $cloud  Базова адреса сховища
     *
     * @return void
     */
    private function syncStaffPhotos(Result $result, $cloud)
    {
        foreach ($this->staff->withPhotos() as $person) {
            if ($this->stopped) {
                return;
            }

            $agentId = Resizer::safeName($person['agentId']);
            $id = Resizer::safeName($person['photo']);

            if ($agentId === '' || $id === '') {
                continue;
            }

            $dir = Fs::join($this->photosDir, self::DIR_STAFF, $agentId);
            $file = Fs::join($dir, $id . '.jpg');

            // Нульовий розмір означає, що попередній прогін перервали
            // посередині запису — такий файл вважаємо відсутнім.
            if (!is_file($file) || filesize($file) === 0) {
                if ($this->limitReached()) {
                    $this->stopped = true;

                    return;
                }

                $url = $this->photoUrl(
                    $cloud,
                    str_replace('{agentId}', $agentId, self::STAFF_SOURCE),
                    $id
                );

                if ($this->download($dir, $id, $url, $result, false) === self::PHOTO_OK) {
                    $result->add('photosStaffDownloaded');
                } else {
                    // Не вдалося — старий файл не прибираємо: краще показати
                    // застарілу фотографію, ніж порожнє місце.
                    continue;
                }
            }

            $result->add('photosRemoved', $this->cleanupDir($dir, [$id . '.jpg']));
        }
    }

    /**
     * Прибирає каталоги фотографій, яким уже нічого не відповідає в базі.
     *
     * Потрібно після видалення публікацій — і після будь-якого прогону, який
     * перервали на середині видалення. Порівнюємо з тим, що є в базі зараз,
     * тому крок самодостатній: жодного списку «що видалили минулого разу»
     * зберігати не треба.
     *
     * @param Result $result Лічильники
     *
     * @return void
     */
    private function cleanupOrphans(Result $result)
    {
        $this->cleanupOrphanDirs(
            Fs::join($this->photosDir, self::DIR_OBJECTS),
            $this->objects->allPublicationIds(),
            'публікацій',
            $result
        );

        $this->cleanupOrphanDirs(
            Fs::join($this->photosDir, self::DIR_STAFF),
            $this->staff->allAgentIds(),
            'співробітників',
            $result
        );
    }

    /**
     * Видаляє підкаталоги, назв яких немає в переданому списку.
     *
     * @param string   $parentDir Каталог з підкаталогами по одному на запис
     * @param string[] $keepIds   Ідентифікатори, які треба зберегти
     * @param string   $what      Назва для повідомлення в лог
     * @param Result   $result    Лічильники
     *
     * @return void
     */
    private function cleanupOrphanDirs($parentDir, array $keepIds, $what, Result $result)
    {
        if (!is_dir($parentDir)) {
            return;
        }

        $entries = @scandir($parentDir);

        if ($entries === false) {
            return;
        }

        $entries = array_diff($entries, ['.', '..']);

        if ($entries === []) {
            return;
        }

        // Обережність на випадок, коли таблиця раптом виявилась порожньою:
        // це майже завжди означає проблему з базою, а не те, що користувач
        // справді видалив усі записи. Фотографії відновити нізвідки, тому
        // краще залишити зайве й сказати про це.
        if ($keepIds === []) {
            $this->logger->warn(
                'У базі немає жодного запису ' . $what . ', а каталоги з фотографіями є ('
                . count($entries) . ' шт.). Нічого не видаляю — перевірте базу.'
            );

            return;
        }

        $keep = array_flip($keepIds);
        $removed = 0;

        foreach ($entries as $entry) {
            if (isset($keep[$entry])) {
                continue;
            }

            $path = Fs::join($parentDir, $entry);

            if (!is_dir($path)) {
                @unlink($path);

                continue;
            }

            if (Fs::removeDir($path)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            $result->add('photosDirsRemoved', $removed);
            $this->logger->write('Видалено каталогів фотографій ' . $what . ': ' . $removed);
        }
    }

    /**
     * Видаляє з каталогу все, чого немає в списку потрібних файлів.
     *
     * Так прибираються фотографії, вилучені з обʼєкта в CRM, файли розмірів,
     * які користувач видалив із конфігу, і тимчасові файли перерваного
     * завантаження.
     *
     * @param string   $dir      Каталог
     * @param string[] $expected Назви файлів, які мають залишитись
     *
     * @return int Скільки файлів видалено
     */
    private function cleanupDir($dir, array $expected)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            return 0;
        }

        $keep = array_flip($expected);
        $removed = 0;

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            if (isset($keep[$entry])) {
                continue;
            }

            if (@unlink(Fs::join($dir, $entry))) {
                $removed++;
            }
        }

        // Каталог, у якому нічого не має лежати, теж прибираємо.
        if ($expected === []) {
            @rmdir($dir);
        }

        return $removed;
    }

    /**
     * Складає адресу фотографії у сховищі.
     *
     * Схема задана CRM:
     *
     *     {cloud}{source}/{id}-{variant}.jpg
     *
     * де variant для обʼєктів — 'publish' (з нанесеним водяним знаком) або
     * '2500x2500' (оригінал без знака). У фотографій співробітників варіанта
     * немає взагалі, тому адреса коротша:
     *
     *     {cloud}agent/{agentId}/{photo}.jpg
     *
     * @param string      $cloud   Базова адреса сховища із завершальним слешем
     * @param string      $source  Шлях у сховищі, напр. 'object-base/68945d9b...'
     * @param string      $id      Ідентифікатор фотографії
     * @param string|null $variant Назва варіанта у сховищі; null — без варіанта
     *
     * @return string
     */
    private function photoUrl($cloud, $source, $id, $variant = null)
    {
        return $cloud . trim($source, '/') . '/' . $id
            . ($variant === null ? '' : '-' . $variant) . '.jpg';
    }

    /**
     * Чи витрачено дозволену на прогін кількість завантажень.
     *
     * @return bool
     */
    private function limitReached()
    {
        return $this->limit > 0 && $this->downloaded >= $this->limit;
    }
}
