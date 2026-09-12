<?php

namespace Plusest\Site\Installer;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Console\Cli;
use Plusest\Site\Db;
use Plusest\Site\Migrator;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Support\Fs;
use Plusest\Site\Support\Package;

/**
 * Веб-установник: налаштування заготовки через браузер.
 *
 * Для кого він
 * ------------
 * Більшість користувачів заготовки — рієлтори й власники агентств, у яких
 * немає SSH-доступу до хостингу. Вони заливають ZIP-архів файловим
 * менеджером, і єдиний доступний їм спосіб щось налаштувати — форма в
 * браузері. Тому установник робить рівно те саме, що консольні команди
 * install і migrate, і користується тим самим кодом.
 *
 * Чотири кроки
 * ------------
 *  1. Ключ доступу. Установник створює файл data/installKey.txt і просить
 *     скопіювати з нього ключ. Файл не доступний з вебу, тому пройти цей
 *     крок може лише той, хто має доступ до файлів сайту, — див. InstallKey.
 *  2. Перевірка оточення: версія PHP, розширення, права на запис.
 *  3. Форма налаштувань. Значення підставляються з наявного config.php,
 *     якщо він уже заповнений.
 *  4. Запис config.php, створення таблиць, завантаження моделей даних і
 *     підсумок із рядками для cron.
 *
 * Установник не видаляє себе після встановлення
 * ---------------------------------------------
 * Це зроблено свідомо: перенастроїти базу даних або змінити розміри
 * фотографій через форму значно простіше, ніж правити config.php по FTP.
 * Безпеці це не шкодить — щоб пройти перший крок, потрібен ключ із файлу,
 * якого з вебу не видно. А от повторний запуск попереджає, що config.php
 * буде перезаписаний.
 *
 * Перша синхронізація тут не запускається
 * ---------------------------------------
 * Забір фіда й завантаження кількох тисяч фотографій триває довше, ніж
 * дозволяє будь-який хостинг тримати HTTP-запит. Тому останній крок лише
 * показує готові рядки для cron і адресу веб-запуску: перший прогін зробить
 * планувальник.
 */
class WebInstaller
{
    /**
     * Назва поля з ключем доступу у формах.
     */
    const FIELD_KEY = 'installKey';

    /**
     * Назва поля з номером кроку.
     */
    const FIELD_STEP = 'step';

    /**
     * Поля форми налаштувань: назва токена конфігу => тип значення.
     *
     * Назви полів у формі збігаються з назвами токенів у шаблоні конфігу
     * (див. ConfigTemplate), тому окремої таблиці відповідності не потрібно.
     *
     * Типи: string, int, intOrNull, bool, list.
     *
     * @var array
     */
    private static $fields = [
        // --- База даних -----------------------------------------------------
        'dbHost'     => 'string',
        'dbPort'     => 'int',
        'dbName'     => 'string',
        'dbUser'     => 'string',
        'dbPassword' => 'string',
        'dbSocket'   => 'string',
        'dbPrefix'   => 'string',

        // --- Фід ------------------------------------------------------------
        'feedUrl' => 'string',

        // --- Адреси ---------------------------------------------------------
        'urlsBase'   => 'string',
        'urlsPhotos' => 'string',

        // --- Мови й валюти --------------------------------------------------
        'langAvailable'     => 'list',
        'langDefault'       => 'string',
        'currencyAvailable' => 'list',

        // --- Розділ сайту ---------------------------------------------------
        'siteTitleUk'     => 'string',
        'siteTitleRu'     => 'string',
        'sitePerPage'     => 'int',
        'siteSortDefault' => 'string',
        'siteShowSold'    => 'bool',

        // --- Фотографії -----------------------------------------------------
        'photosDriver'        => 'string',
        'photosQuality'       => 'int',
        'photosLargeWidth'    => 'intOrNull',
        'photosLargeHeight'   => 'intOrNull',
        'photosMediumWidth'   => 'intOrNull',
        'photosMediumHeight'  => 'intOrNull',
        'photosPreviewWidth'  => 'intOrNull',
        'photosPreviewHeight' => 'intOrNull',

        // --- Синхронізація --------------------------------------------------
        'syncMissingMode' => 'string',
        'syncPhotoLimit'  => 'int',

        // --- Службове -------------------------------------------------------
        'debugEnabled' => 'bool',
        'debugSummary' => 'bool',
    ];

    /**
     * Каталог заготовки — той, у якому лежить config.php.
     *
     * @var string
     */
    private $root;

    /**
     * @var InstallKey
     */
    private $key;

    /**
     * Дані, надіслані формою.
     *
     * @var array
     */
    private $post = [];

    /**
     * @param string $rootDir Каталог заготовки (де config.php)
     */
    public function __construct($rootDir)
    {
        $this->root = $rootDir;
        $this->key = new InstallKey($this->dataDir());
        $this->post = isset($_POST) && is_array($_POST) ? $_POST : [];
    }

    /**
     * Обробляє запит і друкує сторінку установника.
     *
     * @return void
     */
    public function run()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');

            // Установник не має потрапляти ні в кеш проксі, ні в пошуковий
            // індекс: це сторінка з формою доступу до бази даних.
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }

        $step = isset($this->post[self::FIELD_STEP]) ? (int) $this->post[self::FIELD_STEP] : 0;

        try {
            // Перший крок — єдиний, який доступний без ключа: саме на ньому
            // ключ і вводять.
            if ($step === 0) {
                echo $this->stepKey();

                return;
            }

            if (!$this->key->matches($this->keyFromPost())) {
                echo $this->stepKey(['Ключ не збігається з тим, що записаний у файлі. '
                    . 'Скопіюйте рядок після «KEY:» повністю.']);

                return;
            }

            if ($step === 1) {
                echo $this->stepRequirements();

                return;
            }

            if ($step === 2) {
                echo $this->stepForm($this->currentValues());

                return;
            }

            if ($step === 3) {
                echo $this->install();

                return;
            }

            echo $this->stepKey();
        } catch (Exception $e) {
            echo $this->page(
                'Помилка',
                '<div class="alert alert-danger"><strong>Не вдалося продовжити.</strong><br>'
                . $this->esc($e->getMessage()) . '</div>'
                . '<a class="btn btn-outline-secondary" href="">Почати спочатку</a>'
            );
        }
    }

    /**
     * Крок 1: ключ доступу.
     *
     * @param string[] $errors Повідомлення про помилки
     *
     * @return string HTML
     */
    private function stepKey(array $errors = [])
    {
        // Створюємо ключ, якщо його ще немає. Саме на цьому кроці, а не
        // раніше: інакше файл зʼявився б від першого ж запиту робота.
        $keyFile = $this->key->file();
        $keyReady = true;

        try {
            $this->key->ensure();
        } catch (Exception $e) {
            $keyReady = false;
            $errors[] = 'Не вдалося створити файл із ключем: ' . $e->getMessage();
        }

        $body = $this->errorsBlock($errors);

        if ($this->key->isInstalled()) {
            $body .= '<div class="alert alert-warning">'
                . '<strong>Розділ уже встановлений.</strong><br>'
                . 'Установник можна пройти повторно — це зручно, коли змінилися дані доступу до '
                . 'бази або потрібні інші розміри фотографій. Але майте на увазі: файл '
                . '<code>config.php</code> буде перезаписаний, і правки, зроблені в ньому руками, '
                . 'зникнуть.'
                . '</div>';
        }

        if ($keyReady) {
            $body .= '<p>Щоб продовжити, відкрийте файл</p>'
                . '<pre class="bg-light border rounded p-3">' . $this->esc($keyFile) . '</pre>'
                . '<p>тим самим файловим менеджером або FTP-клієнтом, яким ви заливали архів, '
                . 'і скопіюйте звідти рядок після <code>KEY:</code>.</p>'
                . '<p class="text-muted small">Ключ підтверджує, що налаштування робить власник '
                . 'сайту. Без нього установником міг би скористатися будь-хто, хто вгадав адресу.</p>';
        }

        $body .= '<form method="post" class="mt-4">'
            . '<input type="hidden" name="' . self::FIELD_STEP . '" value="1">'
            . '<div class="mb-3">'
            . '<label class="form-label" for="key">Ключ доступу</label>'
            . '<input class="form-control form-control-lg" id="key" name="' . self::FIELD_KEY . '"'
            . ' autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX" required>'
            . '</div>'
            . '<button class="btn btn-primary" type="submit">Далі</button>'
            . '</form>';

        return $this->page('Ключ доступу', $body, 1);
    }

    /**
     * Крок 2: перевірка оточення.
     *
     * @return string HTML
     */
    private function stepRequirements()
    {
        $requirements = Requirements::forInstallRoot($this->root);
        $checks = $requirements->all();
        $passed = $requirements->passed($checks);

        $rows = '';

        foreach ($checks as $check) {
            if ($check['passed']) {
                $badge = '<span class="badge text-bg-success">гаразд</span>';
            } elseif ($check['critical']) {
                $badge = '<span class="badge text-bg-danger">потрібно виправити</span>';
            } else {
                $badge = '<span class="badge text-bg-warning">увага</span>';
            }

            $rows .= '<tr>'
                . '<td>' . $this->esc($check['name']) . '</td>'
                . '<td class="text-muted">' . $this->esc($check['actual']) . '</td>'
                . '<td class="text-end">' . $badge . '</td>'
                . '</tr>';

            if (!$check['passed']) {
                $rows .= '<tr><td colspan="3" class="small text-muted pt-0">'
                    . $this->esc($check['hint']) . '</td></tr>';
            }
        }

        $body = '<table class="table align-middle"><tbody>' . $rows . '</tbody></table>';

        if (!$passed) {
            $body .= '<div class="alert alert-danger">Є перевірки, без яких заготовка не '
                . 'працюватиме. Виправте їх і натисніть «Перевірити знову».</div>';
        }

        $body .= '<form method="post" class="d-flex gap-2">'
            . $this->hiddenKey()
            . '<input type="hidden" name="' . self::FIELD_STEP . '" value="'
            . ($passed ? 2 : 1) . '">'
            . '<button class="btn ' . ($passed ? 'btn-primary' : 'btn-outline-secondary') . '"'
            . ' type="submit">' . ($passed ? 'Далі' : 'Перевірити знову') . '</button>'
            . '</form>';

        return $this->page('Перевірка оточення', $body, 2);
    }

    /**
     * Крок 3: форма налаштувань.
     *
     * @param array    $values Значення полів
     * @param string[] $errors Повідомлення про помилки
     *
     * @return string HTML
     */
    private function stepForm(array $values, array $errors = [])
    {
        $requirements = Requirements::forInstallRoot($this->root);
        $drivers = $requirements->availableImageDrivers();

        $body = $this->errorsBlock($errors)
            . '<form method="post">'
            . $this->hiddenKey()
            . '<input type="hidden" name="' . self::FIELD_STEP . '" value="3">';

        // --- База даних -----------------------------------------------------
        $body .= $this->section(
            'База даних',
            'Створіть базу даних у панелі хостингу й перенесіть сюди дані доступу. '
            . 'Заготовка створить у ній чотири таблиці.',
            $this->row([
                $this->text('dbHost', 'Сервер', $values, 'Зазвичай localhost або 127.0.0.1', 4),
                $this->text('dbPort', 'Порт', $values, '', 2),
                $this->text('dbName', 'Назва бази', $values, '', 6, true),
                $this->text('dbUser', 'Користувач', $values, '', 6, true),
                $this->password('dbPassword', 'Пароль', $values, 6),
                $this->text('dbPrefix', 'Префікс таблиць', $values,
                    'Потрібен, якщо в базі вже є ваші таблиці. Наприклад plusest_', 6),
                $this->text('dbSocket', 'Unix-сокет', $values,
                    'Заповнюйте лише якщо MySQL слухає сокет, а не порт', 6),
            ])
        );

        // --- Фід ------------------------------------------------------------
        $body .= $this->section(
            'Обʼєкти з CRM',
            'Створіть у кабінеті Plusest публікацію для сайту й скопіюйте сюди видане посилання. '
            . 'Воно містить ідентифікатор вашого агентства — нікому його не показуйте.',
            $this->row([
                $this->text('feedUrl', 'Посилання публікації', $values,
                    'Виглядає так: https://plusest.app/api/json-feed/site/?id=...', 12, true),
            ])
        );

        // --- Адреси ---------------------------------------------------------
        $body .= $this->section(
            'Адреси',
            'Шлях, за яким розділ доступний на сайті. Установник підставив те, що визначив '
            . 'сам, — виправте, якщо помилився.',
            $this->row([
                $this->text('urlsBase', 'Адреса розділу', $values, 'Зі слешами з обох боків: /base/', 6),
                $this->text('urlsPhotos', 'Адреса фотографій', $values,
                    'Зазвичай адреса розділу + photos/', 6),
            ])
        );

        // --- Розділ ---------------------------------------------------------
        $body .= $this->section(
            'Сторінки розділу',
            'Як виглядає список обʼєктів для відвідувача.',
            $this->row([
                $this->text('siteTitleUk', 'Заголовок українською', $values, '', 6),
                $this->text('siteTitleRu', 'Заголовок російською', $values, '', 6),
                $this->text('sitePerPage', 'Обʼєктів на сторінці', $values, '', 3),
                $this->select('siteSortDefault', 'Сортування за замовчуванням', $values, [
                    'new'       => 'спочатку нові',
                    'old'       => 'спочатку давні',
                    'priceUp'   => 'ціна: від дешевих',
                    'priceDown' => 'ціна: від дорогих',
                    'areaUp'    => 'площа: від меншої',
                    'areaDown'  => 'площа: від більшої',
                ], 5),
                $this->checkbox('siteShowSold', 'Показувати реалізовані обʼєкти', $values,
                    'Вони виводяться після актуальних і з позначкою «реалізовано»'),
            ])
        );

        // --- Мови й валюти --------------------------------------------------
        $body .= $this->section(
            'Мови й валюти',
            'CRM віддає тексти двома мовами, а ціну — одразу в трьох валютах. Залиште те, '
            . 'що потрібно вашим відвідувачам: якщо мова одна, перемикач не показується.',
            $this->row([
                $this->checkboxList('langAvailable', 'Мови', $values, ['uk' => 'українська', 'ru' => 'російська'], 4),
                $this->select('langDefault', 'Мова за замовчуванням', $values,
                    ['uk' => 'українська', 'ru' => 'російська'], 4),
                $this->checkboxList('currencyAvailable', 'Валюти', $values,
                    ['usd' => 'долар', 'uah' => 'гривня', 'eur' => 'євро'], 4),
            ])
        );

        // --- Фотографії -----------------------------------------------------
        $driverOptions = [];

        foreach ($drivers as $driver) {
            $driverOptions[$driver] = $driver === 'gd' ? 'GD (є майже всюди)' : 'Imagick (якісніше)';
        }

        if ($driverOptions === []) {
            $driverOptions = ['gd' => 'GD'];
        }

        $body .= $this->section(
            'Фотографії',
            'Кожна фотографія обʼєкта зберігається у трьох розмірах. Порожня висота означає '
            . '«не обмежувати» — пропорції зберігаються.',
            $this->row([
                $this->select('photosDriver', 'Обробка зображень', $values, $driverOptions, 4),
                $this->text('photosQuality', 'Якість JPEG', $values, 'Від 1 до 100. Розумно 85', 2),
                $this->text('photosLargeWidth', 'Велика: ширина', $values, '', 3),
                $this->text('photosLargeHeight', 'Велика: висота', $values, '', 3),
                $this->text('photosMediumWidth', 'Середня: ширина', $values, '', 3),
                $this->text('photosMediumHeight', 'Середня: висота', $values, '', 3),
                $this->text('photosPreviewWidth', 'Мініатюра: ширина', $values, '', 3),
                $this->text('photosPreviewHeight', 'Мініатюра: висота', $values, '', 3),
            ])
        );

        // --- Синхронізація --------------------------------------------------
        $body .= $this->section(
            'Синхронізація',
            'Що робити з публікацією, яка зникла з CRM, і чи обмежувати завантаження '
            . 'фотографій за один прогін.',
            $this->row([
                $this->select('syncMissingMode', 'Публікація зникла з CRM', $values, [
                    'sold'   => 'позначити реалізованою (рекомендовано)',
                    'hidden' => 'прибрати з пошуку, лишити за посиланням',
                    'delete' => 'видалити разом із фотографіями',
                ], 6),
                $this->text('syncPhotoLimit', 'Фотографій за прогін', $values,
                    '0 — без обмеження. Обмеження корисне на слабкому хостингу', 3),
                $this->checkbox('cronWeb', 'Дозволити запуск синхронізації через браузер',
                    ['cronWeb' => $values['cronToken'] !== ''],
                    'Потрібно, якщо панель хостингу вміє лише «викликати URL за розкладом»'),
                $this->checkbox('debugEnabled', 'Показувати текст помилок на сторінках',
                    $values, 'На робочому сайті має бути вимкнено'),
                $this->checkbox('debugSummary', 'Дописувати сводку про час відповіді',
                    $values, 'Комментар у кінці сторінки: тривалість етапів і запитів до бази.'
                    . ' Потрібен, якщо сторінки відкриваються повільно'),
            ])
        );

        $body .= '<div class="d-flex gap-2 mb-5">'
            . '<button class="btn btn-primary btn-lg" type="submit">Зберегти й створити таблиці</button>'
            . '</div></form>';

        return $this->page('Налаштування', $body, 3);
    }

    /**
     * Крок 4: запис конфігу, створення таблиць, моделі даних.
     *
     * @return string HTML
     */
    private function install()
    {
        $values = $this->valuesFromPost();
        $problems = $this->validate($values);

        if ($problems !== []) {
            return $this->stepForm($values, $problems);
        }

        // --- Запис config.php ------------------------------------------------
        $configFile = Fs::join($this->root, 'config.php');

        ConfigTemplate::write(
            Package::configTemplate(),
            $configFile,
            $values + [
                'cronScriptPath' => Fs::join($this->root, 'bin', 'sync.php'),
                'cronWebUrl'     => $this->cronWebUrl($values),
            ],
            // Перезапис дозволений: установник для цього й лишається на
            // сервері. Попередження про це показане на першому кроці.
            true
        );

        $config = Config::load($configFile);

        // --- Таблиці ----------------------------------------------------------
        $db = new Db((array) $config->get('db', []));
        $migrator = new Migrator($db);
        $migrator->apply(Migrator::resolveSchemaFile($config));

        $missing = $migrator->missingTables();

        // --- Моделі даних ------------------------------------------------------
        $models = new ModelStore($config);
        $modelReport = $models->refresh(true);

        // --- Позначка «встановлено» -------------------------------------------
        $this->key->markInstalled([
            'version' => Cli::VERSION,
            'tables'  => $migrator->existingTables(),
        ]);

        return $this->page('Готово', $this->summary($config, $values, $missing, $modelReport), 4);
    }

    /**
     * Підсумкова сторінка: що зробили і що робити далі.
     *
     * @param Config   $config       Записані налаштування
     * @param array    $values       Значення полів форми
     * @param string[] $missing      Таблиці, які не створились
     * @param array    $modelReport  Результат завантаження моделей
     *
     * @return string HTML
     */
    private function summary(Config $config, array $values, array $missing, array $modelReport)
    {
        $body = '';

        if ($missing === []) {
            $body .= '<div class="alert alert-success"><strong>Налаштування збережені, '
                . 'таблиці створені.</strong></div>';
        } else {
            $body .= '<div class="alert alert-danger">Не створені таблиці: '
                . $this->esc(implode(', ', $missing)) . '</div>';
        }

        // Моделі даних: без них сторінки обʼєктів показуватимуть службові
        // назви полів, тому про невдачу треба сказати одразу.
        $modelProblems = [];

        foreach ($modelReport as $name => $status) {
            if ($status !== 'downloaded' && $status !== 'fresh') {
                $modelProblems[] = $name . ': ' . $status;
            }
        }

        if ($modelProblems !== []) {
            $body .= '<div class="alert alert-warning"><strong>Моделі даних не завантажились.</strong><br>'
                . $this->esc(implode('; ', $modelProblems))
                . '<br>Це не заважає встановленню: наступний прогін синхронізації спробує знову.</div>';
        }

        // --- Помилки в конфізі, які видно лише після запису -------------------
        $issues = $config->validate();

        if ($issues !== []) {
            $body .= '<div class="alert alert-warning"><strong>Зверніть увагу:</strong><ul class="mb-0">';

            foreach ($issues as $issue) {
                $body .= '<li>' . $this->esc($issue) . '</li>';
            }

            $body .= '</ul></div>';
        }

        // --- Cron -------------------------------------------------------------
        $script = Fs::join($this->root, 'bin', 'sync.php');

        $body .= '<h2 class="h5 mt-4">Останній крок: запуск за розкладом</h2>'
            . '<p>Заготовка нічого не забирає з CRM сама. Додайте завдання в панелі хостингу — '
            . 'раз на 15 хвилин. Частіше не потрібно, а рідше — шкода: саме частими прогонами '
            . 'дозавантажуються фотографії.</p>'
            . '<p class="mb-1"><strong>Якщо є доступ до cron:</strong></p>'
            . '<pre class="bg-light border rounded p-3">3,18,33,48 * * * * /usr/bin/php '
            . $this->esc($script) . ' --quiet &gt;&gt; /dev/null 2&gt;&amp;1</pre>';

        if ($values['cronToken'] !== '') {
            $body .= '<p class="mb-1"><strong>Якщо панель хостингу вміє лише викликати URL:</strong></p>'
                . '<pre class="bg-light border rounded p-3">' . $this->esc($this->cronWebUrl($values)) . '</pre>'
                . '<p class="small text-muted">Ця адреса — фактично пароль до запуску синхронізації. '
                . 'Не публікуйте її.</p>';
        } else {
            $body .= '<p class="small text-muted">Запуск через браузер вимкнений. Щоб увімкнути, '
                . 'пройдіть установник знову й поставте відповідну позначку.</p>';
        }

        $body .= '<p class="mt-4">Перший прогін завантажить усі обʼєкти й фотографії — це може '
            . 'тривати години. Сторінки розділу вже працюють: обʼєкти зʼявлятимуться на них '
            . 'у міру завантаження.</p>';

        $body .= '<div class="d-flex gap-2 mt-4">'
            . '<a class="btn btn-primary" href="' . $this->esc($config->get('urls.base', '/')) . '">'
            . 'Відкрити розділ нерухомості</a>'
            . '<a class="btn btn-outline-secondary" href="">Пройти установник знову</a>'
            . '</div>';

        return $body;
    }

    /**
     * Перевіряє надіслані значення до запису конфігу.
     *
     * @param array $values Значення полів
     *
     * @return string[] Список проблем; порожній масив — усе гаразд
     */
    private function validate(array $values)
    {
        $problems = [];

        if ($values['dbName'] === '') {
            $problems[] = 'Не заповнена назва бази даних.';
        }

        if ($values['dbUser'] === '') {
            $problems[] = 'Не заповнене імʼя користувача бази даних.';
        }

        if ($values['feedUrl'] === '') {
            $problems[] = 'Не заповнене посилання публікації з CRM.';
        } elseif (!preg_match('~^https?://~i', $values['feedUrl'])) {
            $problems[] = 'Посилання публікації має починатися з https://';
        }

        if ($values['langAvailable'] === []) {
            $problems[] = 'Виберіть хоча б одну мову.';
        }

        if ($values['currencyAvailable'] === []) {
            $problems[] = 'Виберіть хоча б одну валюту.';
        }

        // Мова за замовчуванням мусить бути серед увімкнених — інакше
        // відвідувач побачить те, чого не вибирали.
        if ($values['langAvailable'] !== [] && !in_array($values['langDefault'], $values['langAvailable'], true)) {
            $problems[] = 'Мова за замовчуванням не входить до вибраних мов.';
        }

        // Найважливіша перевірка: чи справді працює підключення до бази. Без
        // неї користувач дізнався б про помилку в паролі лише тоді, коли
        // відкрив би сторінку з обʼєктами.
        if ($problems === []) {
            try {
                $db = new Db([
                    'host'     => $values['dbHost'],
                    'port'     => $values['dbPort'],
                    'name'     => $values['dbName'],
                    'user'     => $values['dbUser'],
                    'password' => $values['dbPassword'],
                    'socket'   => $values['dbSocket'],
                    'charset'  => 'utf8mb4',
                ]);

                $db->fetchValue('SELECT VERSION()');
            } catch (Exception $e) {
                $problems[] = 'Не вдалося підключитися до бази даних: ' . $e->getMessage();
            }
        }

        return $problems;
    }

    /**
     * Значення полів із форми, приведені до потрібних типів.
     *
     * @return array
     */
    private function valuesFromPost()
    {
        $values = [];

        foreach (self::$fields as $name => $type) {
            $raw = isset($this->post[$name]) ? $this->post[$name] : null;

            switch ($type) {
                case 'int':
                    $values[$name] = (int) $this->scalar($raw);

                    break;

                case 'intOrNull':
                    $scalar = $this->scalar($raw);
                    $values[$name] = $scalar === '' ? null : (int) $scalar;

                    break;

                case 'bool':
                    $values[$name] = $raw !== null && $raw !== '' && $raw !== '0';

                    break;

                case 'list':
                    $values[$name] = [];

                    foreach ((array) $raw as $one) {
                        $one = $this->scalar($one);

                        if ($one !== '') {
                            $values[$name][] = $one;
                        }
                    }

                    break;

                default:
                    $values[$name] = $this->scalar($raw);
            }
        }

        // Токен веб-запуску не вводять руками: якщо позначка стоїть —
        // генеруємо випадковий рядок, якщо ні — залишаємо порожнім, і
        // public/cron.php відповідатиме 404.
        //
        // Наявний токен при повторному налаштуванні зберігаємо: інакше
        // адреса, вже вписана в панелі хостингу, перестала б працювати.
        if (empty($this->post['cronWeb'])) {
            $values['cronToken'] = '';
        } else {
            $current = $this->currentValues();

            $values['cronToken'] = $current['cronToken'] === ''
                ? $this->randomToken()
                : $current['cronToken'];
        }

        // Адреса фотографій за замовчуванням — це адреса розділу + photos/.
        if ($values['urlsPhotos'] === '' && $values['urlsBase'] !== '') {
            $values['urlsPhotos'] = rtrim($values['urlsBase'], '/') . '/photos/';
        }

        return $values;
    }

    /**
     * Значення для форми: з наявного config.php або за замовчуванням.
     *
     * @return array
     */
    private function currentValues()
    {
        $defaults = ConfigTemplate::defaults();

        // Адресу розділу визначаємо з поточного запиту: установник відкритий
        // за адресою вигляду https://site.com/base/install/, отже базовий
        // шлях — те, що залишиться після відкидання останнього сегмента.
        $defaults['urlsBase'] = Requirements::detectBaseUrl();
        $defaults['urlsPhotos'] = $defaults['urlsBase'] . 'photos/';

        $configFile = Fs::join($this->root, 'config.php');

        if (!is_file($configFile)) {
            return $defaults;
        }

        try {
            $config = Config::load($configFile);
        } catch (Exception $e) {
            return $defaults;
        }

        // Відповідність «токен форми => ключ у config.php». Заповнені
        // значення показуємо у формі, щоб повторне налаштування не
        // доводилось починати з нуля.
        $map = [
            'dbHost'     => 'db.host',
            'dbPort'     => 'db.port',
            'dbName'     => 'db.name',
            'dbUser'     => 'db.user',
            'dbPassword' => 'db.password',
            'dbSocket'   => 'db.socket',
            'dbPrefix'   => 'db.prefix',

            'feedUrl' => 'feed.url',

            'urlsBase'   => 'urls.base',
            'urlsPhotos' => 'urls.photos',

            'langAvailable'     => 'lang.available',
            'langDefault'       => 'lang.default',
            'currencyAvailable' => 'currency.available',

            'siteTitleUk'     => 'site.titleUk',
            'siteTitleRu'     => 'site.titleRu',
            'sitePerPage'     => 'site.perPage',
            'siteSortDefault' => 'site.sortDefault',
            'siteShowSold'    => 'site.showSold',

            'photosDriver'        => 'photos.driver',
            'photosQuality'       => 'photos.quality',
            'photosLargeWidth'    => 'photos.sizes.large.width',
            'photosLargeHeight'   => 'photos.sizes.large.height',
            'photosMediumWidth'   => 'photos.sizes.medium.width',
            'photosMediumHeight'  => 'photos.sizes.medium.height',
            'photosPreviewWidth'  => 'photos.sizes.preview.width',
            'photosPreviewHeight' => 'photos.sizes.preview.height',

            'syncMissingMode' => 'sync.missingMode',
            'syncPhotoLimit'  => 'sync.photoLimit',

            'cronToken'    => 'cron.token',
            'debugEnabled' => 'debug.enabled',
            'debugSummary' => 'debug.summary',
        ];

        $values = $defaults;

        foreach ($map as $token => $key) {
            $value = $config->get($key);

            if ($value !== null && $value !== '') {
                $values[$token] = $value;
            }
        }

        return $values;
    }

    /**
     * Адреса веб-запуску синхронізації.
     *
     * @param array $values Значення полів
     *
     * @return string
     */
    private function cronWebUrl(array $values)
    {
        if ($values['cronToken'] === '') {
            return 'https://site.com/base/cron.php?token=ВАШ_ТОКЕН';
        }

        return Requirements::detectHost()
            . rtrim($values['urlsBase'], '/') . '/cron.php?token=' . $values['cronToken'];
    }

    /**
     * Каталог для службових файлів.
     *
     * Береться з наявного конфігу, якщо він є: користувач міг перенести
     * каталог data у місце, недоступне з вебу.
     *
     * @return string
     */
    private function dataDir()
    {
        $configFile = Fs::join($this->root, 'config.php');

        if (is_file($configFile)) {
            try {
                return Config::load($configFile)->path('paths.data');
            } catch (Exception $e) {
                // Конфіг зламаний або не заповнений — беремо шлях за
                // замовчуванням, він же в шаблоні конфігу.
            }
        }

        return Fs::join($this->root, 'data');
    }

    /**
     * Ключ доступу, надісланий формою.
     *
     * @return string
     */
    private function keyFromPost()
    {
        return isset($this->post[self::FIELD_KEY]) ? $this->scalar($this->post[self::FIELD_KEY]) : '';
    }

    /**
     * Прихованим полем передаємо ключ на наступний крок.
     *
     * Сесії тут навмисно не використовуються: на частині хостингів вони
     * налаштовані так, що каталог сесій недоступний для запису, і установник
     * падав би на другому кроці.
     *
     * @return string HTML
     */
    private function hiddenKey()
    {
        return '<input type="hidden" name="' . self::FIELD_KEY . '" value="'
            . $this->esc($this->keyFromPost()) . '">';
    }

    /**
     * Випадковий токен для веб-запуску синхронізації.
     *
     * @return string
     */
    private function randomToken()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (Exception $e) {
                // Провалюємось до наступного варіанта.
            }
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);

            if ($bytes !== false) {
                return bin2hex($bytes);
            }
        }

        // Крайній випадок: джерела випадковості немає. Такий токен слабкий,
        // тому попереджаємо про це в самому значенні.
        return 'zminitsIaObovIazkovo' . md5(uniqid('', true));
    }

    /**
     * Значення поля у вигляді рядка.
     *
     * @param mixed $value Значення з $_POST
     *
     * @return string
     */
    private function scalar($value)
    {
        return is_array($value) || $value === null ? '' : trim((string) $value);
    }

    /**
     * Екранує текст для HTML.
     *
     * @param mixed $value Значення
     *
     * @return string
     */
    private function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Блок із повідомленнями про помилки.
     *
     * @param string[] $errors Повідомлення
     *
     * @return string HTML
     */
    private function errorsBlock(array $errors)
    {
        if ($errors === []) {
            return '';
        }

        $items = '';

        foreach ($errors as $error) {
            $items .= '<li>' . $this->esc($error) . '</li>';
        }

        return '<div class="alert alert-danger"><ul class="mb-0">' . $items . '</ul></div>';
    }

    /**
     * Розділ форми із заголовком і поясненням.
     *
     * @param string $title Заголовок
     * @param string $hint  Пояснення
     * @param string $body  Поля
     *
     * @return string HTML
     */
    private function section($title, $hint, $body)
    {
        return '<section class="card mb-4"><div class="card-body">'
            . '<h2 class="h5">' . $this->esc($title) . '</h2>'
            . '<p class="text-muted small">' . $this->esc($hint) . '</p>'
            . $body
            . '</div></section>';
    }

    /**
     * Рядок сітки з полями.
     *
     * @param string[] $fields Готові поля
     *
     * @return string HTML
     */
    private function row(array $fields)
    {
        return '<div class="row g-3">' . implode('', $fields) . '</div>';
    }

    /**
     * Текстове поле.
     *
     * @param string $name     Назва поля
     * @param string $label    Підпис
     * @param array  $values   Значення полів
     * @param string $hint     Пояснення під полем
     * @param int    $width    Ширина в колонках сітки Bootstrap (з 12)
     * @param bool   $required Чи обовʼязкове поле
     *
     * @return string HTML
     */
    private function text($name, $label, array $values, $hint = '', $width = 6, $required = false)
    {
        $value = isset($values[$name]) ? $values[$name] : '';

        return '<div class="col-12 col-md-' . (int) $width . '">'
            . '<label class="form-label" for="' . $this->esc($name) . '">' . $this->esc($label) . '</label>'
            . '<input class="form-control" id="' . $this->esc($name) . '" name="' . $this->esc($name) . '"'
            . ' value="' . $this->esc($value) . '"' . ($required ? ' required' : '') . '>'
            . ($hint === '' ? '' : '<div class="form-text">' . $this->esc($hint) . '</div>')
            . '</div>';
    }

    /**
     * Поле для пароля.
     *
     * @param string $name   Назва поля
     * @param string $label  Підпис
     * @param array  $values Значення полів
     * @param int    $width  Ширина в колонках сітки
     *
     * @return string HTML
     */
    private function password($name, $label, array $values, $width = 6)
    {
        $value = isset($values[$name]) ? $values[$name] : '';

        return '<div class="col-12 col-md-' . (int) $width . '">'
            . '<label class="form-label" for="' . $this->esc($name) . '">' . $this->esc($label) . '</label>'
            . '<input class="form-control" type="password" autocomplete="new-password"'
            . ' id="' . $this->esc($name) . '" name="' . $this->esc($name) . '"'
            . ' value="' . $this->esc($value) . '">'
            . '</div>';
    }

    /**
     * Список для вибору одного значення.
     *
     * @param string $name    Назва поля
     * @param string $label   Підпис
     * @param array  $values  Значення полів
     * @param array  $options Варіанти «значення => підпис»
     * @param int    $width   Ширина в колонках сітки
     *
     * @return string HTML
     */
    private function select($name, $label, array $values, array $options, $width = 6)
    {
        $current = isset($values[$name]) ? (string) $values[$name] : '';
        $items = '';

        foreach ($options as $value => $text) {
            $items .= '<option value="' . $this->esc($value) . '"'
                . ((string) $value === $current ? ' selected' : '') . '>'
                . $this->esc($text) . '</option>';
        }

        return '<div class="col-12 col-md-' . (int) $width . '">'
            . '<label class="form-label" for="' . $this->esc($name) . '">' . $this->esc($label) . '</label>'
            . '<select class="form-select" id="' . $this->esc($name) . '" name="' . $this->esc($name) . '">'
            . $items . '</select></div>';
    }

    /**
     * Набір позначок для вибору кількох значень.
     *
     * @param string $name    Назва поля
     * @param string $label   Підпис
     * @param array  $values  Значення полів
     * @param array  $options Варіанти «значення => підпис»
     * @param int    $width   Ширина в колонках сітки
     *
     * @return string HTML
     */
    private function checkboxList($name, $label, array $values, array $options, $width = 6)
    {
        $current = isset($values[$name]) ? (array) $values[$name] : [];
        $items = '';

        foreach ($options as $value => $text) {
            $id = $name . ucfirst((string) $value);

            $items .= '<div class="form-check">'
                . '<input class="form-check-input" type="checkbox" id="' . $this->esc($id) . '"'
                . ' name="' . $this->esc($name) . '[]" value="' . $this->esc($value) . '"'
                . (in_array((string) $value, array_map('strval', $current), true) ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $this->esc($id) . '">'
                . $this->esc($text) . '</label></div>';
        }

        return '<div class="col-12 col-md-' . (int) $width . '">'
            . '<div class="form-label">' . $this->esc($label) . '</div>'
            . $items . '</div>';
    }

    /**
     * Одна позначка «так або ні».
     *
     * @param string $name   Назва поля
     * @param string $label  Підпис
     * @param array  $values Значення полів
     * @param string $hint   Пояснення
     *
     * @return string HTML
     */
    private function checkbox($name, $label, array $values, $hint = '')
    {
        $checked = !empty($values[$name]);

        return '<div class="col-12">'
            . '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" value="1" id="' . $this->esc($name) . '"'
            . ' name="' . $this->esc($name) . '"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $this->esc($name) . '">'
            . $this->esc($label) . '</label>'
            . ($hint === '' ? '' : '<div class="form-text">' . $this->esc($hint) . '</div>')
            . '</div></div>';
    }

    /**
     * Обгортка сторінки установника.
     *
     * Своя, а не з каталогу шаблонів: установник мусить працювати навіть
     * тоді, коли шаблони ще не налаштовані або в них є помилка.
     *
     * @param string $title Заголовок сторінки
     * @param string $body  Вміст
     * @param int    $step  Номер поточного кроку для показу прогресу
     *
     * @return string HTML
     */
    private function page($title, $body, $step = 0)
    {
        $steps = [1 => 'Ключ доступу', 2 => 'Оточення', 3 => 'Налаштування', 4 => 'Готово'];
        $progress = '';

        foreach ($steps as $number => $name) {
            $class = 'badge ' . ($number === $step
                ? 'text-bg-primary'
                : ($number < $step ? 'text-bg-success' : 'text-bg-light text-muted'));

            $progress .= '<span class="' . $class . '">' . $number . '. ' . $this->esc($name) . '</span> ';
        }

        return '<!doctype html>' . "\n"
            . '<html lang="uk"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $this->esc($title) . ' — установник Plusest Site</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"'
            . ' rel="stylesheet">'
            . '</head><body class="bg-body-tertiary">'
            . '<div class="container py-5" style="max-width: 960px">'
            . '<h1 class="h3 mb-1">Установник бази нерухомості</h1>'
            . '<p class="text-muted">' . $this->esc($title) . '</p>'
            . '<div class="mb-4">' . $progress . '</div>'
            . $body
            . '</div>'
            . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">'
            . '</script>'
            . '</body></html>';
    }
}
