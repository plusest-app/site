<?php

namespace Plusest\Site;

use Plusest\Site\Exception\ConfigException;
use Plusest\Site\Support\Fs;

/**
 * Контейнер налаштувань заготовки.
 *
 * Читає масив із файлу config.php (той, що установник копіює в проєкт
 * користувача) і дає доступ до значень через «точкову» нотацію:
 *
 *     $config->get('db.host');
 *     $config->get('photos.sizes.preview.width', 45);
 *     $config->need('feed.url');     // кине виключення, якщо не заповнено
 *
 * Обʼєкт незмінний: після завантаження налаштування не редагуються. Якщо
 * потрібно щось перевизначити (наприклад, у тестах) — передайте масив
 * у конструктор напряму.
 */
class Config
{
    /**
     * Часовий пояс, який діє, якщо в конфізі його не задали.
     *
     * Саме назва пояса, а не «як налаштовано на сервері»: поки пояс брався з
     * php.ini, розклад звернень до CRM рахував денні години в невідомо якому
     * поясі (див. Sync\Schedule).
     */
    const DEFAULT_TIMEZONE = 'Europe/Kyiv';

    /**
     * Рівнозначні назви поясів — на випадок старої чи нової бази tzdata.
     *
     * Київ перейменували з Kiev на Kyiv лише у tzdata 2022b, а на хостингах
     * трапляється і PHP зі старішою базою, і найсвіжіший. Тому назву, якої
     * цей PHP не знає, підміняємо рівнозначною, замість того щоб відмовлятись
     * від налаштування.
     *
     * @var array
     */
    private static $timezoneAliases = [
        'Europe/Kyiv' => 'Europe/Kiev',
        'Europe/Kiev' => 'Europe/Kyiv',
    ];

    /**
     * Повний масив налаштувань.
     *
     * @var array
     */
    private $items;

    /**
     * Шлях до файлу, з якого завантажено налаштування (null, якщо масив
     * передали напряму). Використовується як база для відносних шляхів.
     *
     * @var string|null
     */
    private $path;

    /**
     * Кеш уже обчислених значень за точковими ключами.
     *
     * Під час рендерингу списку обʼєктів ті самі ключі запитуються сотні разів,
     * тому дешевше запамʼятати результат, ніж щоразу розбирати рядок.
     *
     * @var array
     */
    private $cache = [];

    /**
     * @param array       $items Масив налаштувань
     * @param string|null $path  Шлях до файлу-джерела, якщо він відомий
     */
    public function __construct(array $items, $path = null)
    {
        $this->items = $items;
        $this->path = $path;
    }

    /**
     * Завантажує налаштування з PHP-файлу, який повертає масив.
     *
     * @param string $path Шлях до config.php
     *
     * @return self
     *
     * @throws ConfigException Якщо файл не знайдено або він повернув не масив
     */
    public static function load($path)
    {
        if (!is_file($path)) {
            throw new ConfigException(
                'Файл налаштувань не знайдено: ' . $path . "\n" .
                'Запустіть установник: php vendor/bin/plusestSite install'
            );
        }

        /** @noinspection PhpIncludeInspection */
        $items = require $path;

        if (!is_array($items)) {
            throw new ConfigException('Файл налаштувань має повертати масив: ' . $path);
        }

        $config = new self($items, $path);

        // Пояс застосовуємо одразу після завантаження, а не в точках входу:
        // від нього залежать і розклад синхронізації, і час, який пишеться в
        // базу та в лог, і дати на сторінках сайту. Точок входу чотири (cron,
        // веб-запуск cron.php, сторінка сайту, команди CLI), і всі вони
        // приходять сюди.
        $config->applyTimezone();

        return $config;
    }

    /**
     * Значення за точковим ключем.
     *
     * @param string $key     Ключ, наприклад 'db.host' або 'photos.sizes'
     * @param mixed  $default Що повернути, якщо ключа немає
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                // Проміжні відсутні ключі не кешуємо як default — щоб не
                // сплутати «немає ключа» з «значення дорівнює default».
                return $default;
            }

            $value = $value[$segment];
        }

        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Значення обовʼязкового параметра.
     *
     * Відрізняється від get() тим, що кидає зрозуміле виключення замість того,
     * щоб тихо повернути null і зламатись десь глибше.
     *
     * @param string $key Точковий ключ
     *
     * @return mixed
     *
     * @throws ConfigException Якщо ключа немає або значення порожнє
     */
    public function need($key)
    {
        $value = $this->get($key);

        if ($value === null || $value === '' || $value === []) {
            throw new ConfigException(
                'У налаштуваннях не заповнено обовʼязковий параметр "' . $key . '"' .
                ($this->path !== null ? ' (файл ' . $this->path . ')' : '')
            );
        }

        return $value;
    }

    /**
     * Чи є в налаштуваннях такий ключ.
     *
     * @param string $key Точковий ключ
     *
     * @return bool
     */
    public function has($key)
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Абсолютний шлях із налаштувань секції paths.
     *
     * Якщо в конфізі вказано відносний шлях — він розкривається відносно
     * каталогу самого config.php. Це дозволяє користувачу писати коротко:
     * 'photos' => 'public/photos'.
     *
     * @param string      $key     Точковий ключ, наприклад 'paths.photos'
     * @param string|null $append  Що додати до знайденого шляху
     *
     * @return string
     *
     * @throws ConfigException Якщо шлях не заповнений
     */
    public function path($key, $append = null)
    {
        $path = (string) $this->need($key);

        if (!$this->isAbsolutePath($path) && $this->path !== null) {
            $path = Fs::join(dirname($this->path), $path);
        }

        // Прогін через join навіть для одного елемента приводить розділювачі
        // до вигляду, звичного для поточної операційної системи.
        return $append === null ? Fs::join($path) : Fs::join($path, $append);
    }

    /**
     * Весь масив налаштувань — на випадок, коли потрібно передати його далі.
     *
     * @return array
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * Часовий пояс, у якому працює сайт.
     *
     * @return string|null Назва пояса; null — якщо в конфізі пояс порожній
     *                     або цей PHP такої назви не знає
     */
    public function timezone()
    {
        $timezone = trim((string) $this->get('timezone', self::DEFAULT_TIMEZONE));

        // Порожнє значення — свідома відмова від налаштування: залишаємо те,
        // що стоїть у php.ini. Комусь із власним оточенням так і потрібно.
        if ($timezone === '') {
            return null;
        }

        if (self::isKnownTimezone($timezone)) {
            return $timezone;
        }

        if (isset(self::$timezoneAliases[$timezone])
            && self::isKnownTimezone(self::$timezoneAliases[$timezone])
        ) {
            return self::$timezoneAliases[$timezone];
        }

        return null;
    }

    /**
     * Встановлює часовий пояс із налаштувань.
     *
     * Навіщо це потрібно
     * ------------------
     * Без цього date() працює в поясі з php.ini, а він у CLI (де працює cron)
     * і у вебсервері буває різний і майже ніколи не той, у якому живе
     * агентство. Розклад звернень до CRM рахує «денні години» саме через
     * date(), тому розбіжність у три години зсувала все вікно: ранковий
     * запит потрапляв у нічний інтервал, і наступний був лише за пів дня.
     * Тим самим поясом позначається час у базі, у логу й на сторінках.
     *
     * Якщо пояс у конфізі порожній або невідомий цьому PHP — не змінюємо
     * нічого; про невідому назву скаже validate().
     *
     * @return string|null Назва застосованого пояса або null
     */
    public function applyTimezone()
    {
        $timezone = $this->timezone();

        if ($timezone === null) {
            return null;
        }

        date_default_timezone_set($timezone);

        return $timezone;
    }

    /**
     * Чи знає цей PHP такий часовий пояс.
     *
     * Перевіряємо через DateTimeZone, а не через timezone_identifiers_list():
     * у списку немає старих назв-посилань (наприклад Europe/Kiev на новій
     * базі tzdata), хоча працюють вони як і раніше.
     *
     * @param string $timezone Назва пояса
     *
     * @return bool
     */
    private static function isKnownTimezone($timezone)
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Перелік увімкнених мов у порядку, заданому користувачем.
     *
     * Мова за замовчуванням завжди стоїть першою, навіть якщо в конфізі вона
     * вказана в іншому місці списку — так простіше будувати перемикач мов.
     *
     * @return string[] Наприклад ['uk', 'ru']
     */
    public function languages()
    {
        $available = (array) $this->get('lang.available', ['uk']);
        $default = $this->defaultLanguage();

        // Прибираємо дублікати та невідомі коди, залишаємо лише uk/ru.
        $languages = [];

        foreach (array_merge([$default], $available) as $language) {
            $language = strtolower(trim((string) $language));

            if (in_array($language, ['uk', 'ru'], true) && !in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }

        return $languages === [] ? ['uk'] : $languages;
    }

    /**
     * Мова за замовчуванням.
     *
     * @return string 'uk' або 'ru'
     */
    public function defaultLanguage()
    {
        $default = strtolower(trim((string) $this->get('lang.default', 'uk')));

        return in_array($default, ['uk', 'ru'], true) ? $default : 'uk';
    }

    /**
     * Перелік увімкнених валют у порядку, заданому користувачем.
     *
     * Ціна приходить із CRM одразу в трьох валютах, тому перемикання валюти
     * на сайті нічого не перераховує — просто показує інший стовпець.
     *
     * @return string[] Наприклад ['usd', 'uah', 'eur']
     */
    public function currencies()
    {
        $available = (array) $this->get('currency.available', ['usd']);
        $currencies = [];

        foreach ($available as $currency) {
            $currency = strtolower(trim((string) $currency));

            if (in_array($currency, ['usd', 'uah', 'eur'], true) && !in_array($currency, $currencies, true)) {
                $currencies[] = $currency;
            }
        }

        return $currencies === [] ? ['usd'] : $currencies;
    }

    /**
     * Валюта, яку бачить відвідувач, що ще не вибирав її сам.
     *
     * Від типу операції не залежить: вибір валюти — справа відвідувача, і
     * діє він на всі публікації одразу. Тому це просто перша валюта зі
     * списку увімкнених.
     *
     * @return string 'usd', 'uah' або 'eur'
     */
    public function defaultCurrency()
    {
        $available = $this->currencies();

        return $available[0];
    }

    /**
     * Перевіряє налаштування «на око» ще до підключення до БД чи запиту фіда.
     *
     * Повертає список текстових проблем: порожній масив означає, що конфіг
     * заповнений коректно. Викликається установником і скриптом синхронізації,
     * щоб користувач одразу побачив, що саме він забув заповнити.
     *
     * @return string[]
     */
    public function validate()
    {
        $problems = [];

        // --- Часовий пояс ---------------------------------------------------
        //  Помилку в назві важливо показати саме тут: далі пояс просто тихо
        //  залишиться тим, що в php.ini, і розклад поїде на кілька годин.
        $timezone = trim((string) $this->get('timezone', self::DEFAULT_TIMEZONE));

        if ($timezone !== '' && $this->timezone() === null) {
            $problems[] = 'Невідомий часовий пояс "' . $timezone . '" у "timezone" — вкажіть назву'
                . ' зі списку PHP, наприклад Europe/Kyiv (список: php.net/manual/en/timezones.php).';
        }

        // --- База даних -----------------------------------------------------
        foreach (['db.name', 'db.user'] as $key) {
            if ($this->get($key) === null || $this->get($key) === '') {
                $problems[] = 'Не заповнено "' . $key . '" — вкажіть дані доступу до MySQL.';
            }
        }

        // --- Фід ------------------------------------------------------------
        $feedUrl = (string) $this->get('feed.url');

        if ($feedUrl === '') {
            $problems[] = 'Не заповнено "feed.url" — скопіюйте посилання на JSON-feed з Plusest.';
        } elseif (!preg_match('~^https?://~i', $feedUrl)) {
            $problems[] = '"feed.url" має починатися з http:// або https://';
        } elseif (str_contains($feedUrl, 'XXXXXXXX')) {
            $problems[] = '"feed.url" залишився зі значенням-заготовкою — підставте власне посилання.';
        }

        // --- Мови -----------------------------------------------------------
        if ($this->languages() === []) {
            $problems[] = '"lang.available" не містить жодної підтримуваної мови (uk, ru).';
        }

        // --- Валюти ---------------------------------------------------------
        $currencies = (array) $this->get('currency.available', []);
        $known = ['usd', 'uah', 'eur'];

        foreach ($currencies as $currency) {
            if (!in_array(strtolower((string) $currency), $known, true)) {
                $problems[] = 'Невідома валюта "' . $currency . '" у "currency.available" (доступні: usd, uah, eur).';
            }
        }

        // --- Фотографії -----------------------------------------------------
        $driver = strtolower((string) $this->get('photos.driver', 'gd'));

        if (!in_array($driver, ['gd', 'imagick'], true)) {
            $problems[] = '"photos.driver" має бути "gd" або "imagick".';
        } elseif (!extension_loaded($driver)) {
            $problems[] = 'Для "photos.driver" = "' . $driver . '" потрібне однойменне розширення PHP,'
                . ' а воно не встановлене.';
        }

        $sizes = (array) $this->get('photos.sizes', []);

        if ($sizes === []) {
            $problems[] = '"photos.sizes" порожній — потрібен хоча б один розмір фотографій.';
        }

        foreach ($sizes as $name => $size) {
            $width = isset($size['width']) ? (int) $size['width'] : 0;
            $height = isset($size['height']) ? (int) $size['height'] : 0;

            if ($width <= 0 && $height <= 0) {
                $problems[] = 'Розмір фотографій "' . $name . '": потрібно задати width або height.';
            }

            // Назва розміру стає частиною імені файлу на диску, тому в ній
            // допустимі лише латиниця, цифри, дефіс і підкреслення.
            if ($name !== preg_replace('~[^A-Za-z0-9_-]~', '', (string) $name)) {
                $problems[] = 'Назва розміру фотографій "' . $name . '" містить недопустимі символи —'
                    . ' залиште лише латиницю, цифри, дефіс і підкреслення.';
            }
        }

        // --- Поведінка при зникненні публікації -----------------------------
        $missing = (string) $this->get('sync.missingMode', 'sold');

        if (!in_array($missing, ['sold', 'hidden', 'delete'], true)) {
            $problems[] = '"sync.missingMode" має бути одним із: sold, hidden, delete.';
        }

        // --- Розклад звернень до CRM ----------------------------------------
        foreach (['sync.intervalDay', 'sync.intervalNight'] as $key) {
            $interval = $this->get($key, 0);

            if (!is_numeric($interval) || (int) $interval < 0) {
                $problems[] = '"' . $key . '" має бути кількістю секунд (0 — без розкладу).';
            }
        }

        $dayFrom = (int) $this->get('sync.dayFrom', 8);
        $dayTo = (int) $this->get('sync.dayTo', 21);

        if ($dayFrom < 0 || $dayFrom > 23 || $dayTo < 1 || $dayTo > 24 || $dayFrom >= $dayTo) {
            $problems[] = '"sync.dayFrom" і "sync.dayTo" задають денні години:'
                . ' dayFrom від 0 до 23, dayTo від 1 до 24, і dayFrom менше за dayTo.';
        }

        return $problems;
    }

    /**
     * Чи є шлях абсолютним (враховує і Unix, і Windows).
     *
     * @param string $path Шлях
     *
     * @return bool
     */
    private function isAbsolutePath($path)
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
