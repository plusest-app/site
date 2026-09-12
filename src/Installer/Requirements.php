<?php

namespace Plusest\Site\Installer;

use Plusest\Site\Support\Fs;

/**
 * Перевірка оточення перед встановленням.
 *
 * Той самий клас використовують і веб-установник, і команда check у консолі:
 * повертає плоский список перевірок, а вже фасад вирішує, як їх показати —
 * таблицею в браузері чи рядками в терміналі.
 *
 * Кожна перевірка — масив із ключами:
 *   name     — що перевіряли, для людини;
 *   passed   — чи пройдено;
 *   critical — чи блокує встановлення (false = попередження);
 *   actual   — що знайшли насправді;
 *   hint     — що робити, якщо не пройдено.
 */
class Requirements
{
    /**
     * Мінімальна версія PHP.
     */
    const MIN_PHP = '7.3.0';

    /**
     * Каталоги, які мусять бути доступні для запису.
     *
     * Ключ — опис для людини, значення — абсолютний шлях.
     *
     * @var array
     */
    private $writableTargets;

    /**
     * @param array $writableTargets Каталоги «опис => шлях», які перевіряємо
     *                               на можливість запису
     */
    public function __construct(array $writableTargets = [])
    {
        $this->writableTargets = $writableTargets;
    }

    /**
     * Перевірки для ще не встановленої заготовки.
     *
     * Використовує веб-установник: config.php ще немає, тому шляхи беруться
     * стандартні — відносно кореня встановлення.
     *
     * @param string $rootDir Каталог, у який ставиться заготовка
     *
     * @return self
     */
    public static function forInstallRoot($rootDir)
    {
        return new self([
            'Каталог заготовки (тут буде створений config.php)' => $rootDir,
            'Службовий каталог: моделі даних, лог, ключ установника' => Fs::join($rootDir, 'data'),
            'Каталог для завантажених фотографій' => Fs::join($rootDir, 'public', 'photos'),
        ]);
    }

    /**
     * Виконує всі перевірки.
     *
     * @return array[] Список перевірок
     */
    public function all()
    {
        return array_merge(
            $this->checkPhp(),
            $this->checkExtensions(),
            $this->checkImageDrivers(),
            $this->checkWritable(),
            $this->checkMisc()
        );
    }

    /**
     * Чи можна встановлювати: жодна критична перевірка не провалена.
     *
     * @param array[]|null $checks Готовий список перевірок або null, щоб
     *                             виконати їх заново
     *
     * @return bool
     */
    public function passed(array $checks = null)
    {
        $checks = $checks === null ? $this->all() : $checks;

        foreach ($checks as $check) {
            if ($check['critical'] && !$check['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Які драйвери обробки зображень доступні.
     *
     * Веб-установник за цим списком показує вибір driver у формі: якщо є лише
     * GD — вибору не пропонуємо, ставимо його одразу.
     *
     * @return string[] Наприклад ['gd', 'imagick']
     */
    public function availableImageDrivers()
    {
        $drivers = [];

        if (extension_loaded('gd')) {
            $drivers[] = 'gd';
        }

        if (extension_loaded('imagick')) {
            $drivers[] = 'imagick';
        }

        return $drivers;
    }

    /**
     * Визначає базовий URL заготовки з поточного HTTP-запиту.
     *
     * Це та частина конфігу, яку користувачі заповнюють неправильно частіше
     * за все. Установник відкритий за адресою вигляду
     * https://site.com/base/install/, отже базовий шлях — це те, що лишається
     * після відкидання останнього сегмента. Так значення підставляється саме,
     * і помилитись уже нема де.
     *
     * @return string Наприклад '/base/'. Якщо визначити не вдалося — '/'
     */
    public static function detectBaseUrl()
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return '/';
        }

        // Відкидаємо параметри запиту — потрібен лише шлях.
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Прибираємо назву скрипта, якщо запит прийшов не на каталог,
        // а напряму на index.php.
        $path = preg_replace('~/[^/]*\.php$~', '/', $path);

        // Прибираємо останній сегмент — це каталог самого установника.
        $path = preg_replace('~/install/?$~', '/', $path);

        $path = trim((string) $path, '/');

        // Установник у корені домену — базовий шлях теж корінь.
        if ($path === '') {
            return '/';
        }

        // Гарантуємо слеші з обох боків.
        return '/' . $path . '/';
    }

    /**
     * Повна схема й хост поточного запиту — для показу готових посилань.
     *
     * @return string Наприклад 'https://site.com'
     */
    public static function detectHost()
    {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';

        // За зворотним проксі схема приходить у заголовку.
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $https = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

        // Заголовок Host приходить від клієнта, тому беремо лише початок рядка
        // до першого символу, який не може бути частиною імені хоста.
        // Саме обрізаємо, а не вичищаємо: якщо хтось підсунув
        // "site.com\r\nX-Injected: 1", маємо отримати "site.com", а не склеєне
        // сміття "site.comX-Injected:1".
        if (preg_match('~^[A-Za-z0-9.\-]+(:\d+)?~', $host, $matches)) {
            $host = $matches[0];
        } else {
            $host = 'localhost';
        }

        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * Версія PHP.
     *
     * @return array[]
     */
    private function checkPhp()
    {
        $passed = version_compare(PHP_VERSION, self::MIN_PHP, '>=');

        return [[
            'name'     => 'Версія PHP не нижче ' . self::MIN_PHP,
            'passed'   => $passed,
            'critical' => true,
            'actual'   => PHP_VERSION,
            'hint'     => 'Перемкніть сайт на новішу версію PHP у панелі хостингу.',
        ]];
    }

    /**
     * Обовʼязкові розширення PHP.
     *
     * @return array[]
     */
    private function checkExtensions()
    {
        $required = [
            'pdo_mysql' => 'Робота з базою даних MySQL',
            'curl'      => 'Завантаження фіда та фотографій з CRM',
            'json'      => 'Розбір даних, що приходять із CRM',
            'mbstring'  => 'Коректна робота з українським і російським текстом',
        ];

        $checks = [];

        foreach ($required as $extension => $why) {
            $checks[] = [
                'name'     => 'Розширення ' . $extension,
                'passed'   => extension_loaded($extension),
                'critical' => true,
                'actual'   => extension_loaded($extension) ? 'підключене' : 'відсутнє',
                'hint'     => $why . '. Увімкніть розширення в панелі хостингу '
                    . 'або попросіть це зробити службу підтримки.',
            ];
        }

        return $checks;
    }

    /**
     * Наявність бібліотеки обробки зображень.
     *
     * @return array[]
     */
    private function checkImageDrivers()
    {
        $drivers = $this->availableImageDrivers();

        return [[
            'name'     => 'Обробка зображень (gd або imagick)',
            'passed'   => $drivers !== [],
            'critical' => true,
            'actual'   => $drivers === [] ? 'жодної не знайдено' : implode(', ', $drivers),
            'hint'     => 'Без неї не вийде масштабувати фотографії обʼєктів. '
                . 'Увімкніть розширення gd — воно є майже на кожному хостингу.',
        ]];
    }

    /**
     * Права на запис у потрібні каталоги.
     *
     * @return array[]
     */
    private function checkWritable()
    {
        $checks = [];

        foreach ($this->writableTargets as $name => $path) {
            // Каталогу може ще не бути — тоді пробуємо створити.
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }

            $writable = is_dir($path) && is_writable($path);

            $checks[] = [
                'name'     => $name,
                'passed'   => $writable,
                'critical' => true,
                'actual'   => $writable ? 'доступний для запису' : $path,
                'hint'     => 'Дайте право на запис користувачу, під яким працює PHP. '
                    . 'Через FTP це «права доступу» 775 на каталог.',
            ];
        }

        return $checks;
    }

    /**
     * Дрібніші перевірки, які не блокують встановлення, але про них варто знати.
     *
     * @return array[]
     */
    private function checkMisc()
    {
        $checks = [];

        // Ліміт памʼяті: розбір великого фіда та масштабування фотографій
        // на 32 мегабайтах впаде.
        $limit = $this->memoryLimitBytes();
        $enough = $limit === -1 || $limit >= 128 * 1024 * 1024;

        $checks[] = [
            'name'     => 'Ліміт памʼяті PHP не менше 128M',
            'passed'   => $enough,
            'critical' => false,
            'actual'   => (string) ini_get('memory_limit'),
            'hint'     => 'Масштабування великих фотографій може не вміститись у поточний ліміт. '
                . 'Збільште memory_limit у налаштуваннях PHP.',
        ];

        // Час виконання: перша синхронізація може бути довгою. Для cron це
        // не важливо (там ліміту немає), а от для веб-запуску — важливо.
        $maxTime = (int) ini_get('max_execution_time');
        $checks[] = [
            'name'     => 'Час виконання скрипта не менше 120 секунд',
            'passed'   => $maxTime === 0 || $maxTime >= 120,
            'critical' => false,
            'actual'   => $maxTime === 0 ? 'без обмеження' : $maxTime . ' с',
            'hint'     => 'Перша синхронізація завантажує всі фотографії й може не вкластися в ліміт. '
                . 'Це не страшно: наступний запуск продовжить з того місця, де зупинився.',
        ];

        // Функції для запуску процесів потрібні лише для перевірки
        // згенерованого конфігу — без них просто пропускаємо цю перевірку.
        $checks[] = [
            'name'     => 'Функція exec для самоперевірки конфігу',
            'passed'   => function_exists('exec')
                && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
            'critical' => false,
            'actual'   => function_exists('exec') ? 'доступна' : 'відключена',
            'hint'     => 'Без неї установник не зможе перевірити згенерований config.php '
                . 'на синтаксичні помилки. На встановлення це не впливає.',
        ];

        return $checks;
    }

    /**
     * Ліміт памʼяті PHP у байтах.
     *
     * @return int Кількість байтів або -1, якщо ліміту немає
     */
    private function memoryLimitBytes()
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        if ($unit === 'g') {
            return $value * 1024 * 1024 * 1024;
        }

        if ($unit === 'm') {
            return $value * 1024 * 1024;
        }

        if ($unit === 'k') {
            return $value * 1024;
        }

        return $value;
    }
}
