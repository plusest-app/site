<?php

namespace Plusest\Site\Installer;

use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Fs;

/**
 * Генерує config.php із шаблону resources/config.template.php.stub
 *
 * Чому не var_export() і не json-подібний дамп масиву: у конфізі майже сотня
 * рядків пояснень українською, і саме вони роблять файл придатним для правки
 * руками. Автоматичний дамп масиву їх би знищив. Тому шаблон — це майбутній
 * config.php з усіма коментарями, у якому замість значень стоять токени
 * вигляду {{dbHost}}.
 *
 * Токени в шаблоні пишуться БЕЗ лапок:
 *
 *     'host' => {{dbHost}},
 *
 * Лапки та екранування додає цей клас — тому значення, у якому користувач
 * написав апостроф або зворотний слеш, не зламає згенерований файл.
 *
 * Використовується двома шляхами встановлення:
 *   * CLI install — рендерить шаблон із значеннями за замовчуванням, щоб
 *     користувач далі заповнив файл руками;
 *   * веб-установник — рендерить із тим, що людина ввела у формі.
 */
class ConfigTemplate
{
    /**
     * Токени, які підставляються в шаблон «як є», без лапок.
     *
     * Це ті, що стоять усередині блоку коментарів у кінці файлу — шляхи та
     * посилання в підсказці про cron. Лапки там були б зайвими, зате підстава
     * без екранування дозволила б закрити коментар послідовністю "＊/", тому
     * значення таких токенів чистяться окремо в rawValue().
     *
     * @var string[]
     */
    private static $rawTokens = [
        'cronScriptPath',
        'cronWebUrl',
    ];

    /**
     * Значення за замовчуванням для всіх токенів шаблону.
     *
     * Ключ — назва токена без фігурних дужок. Значення — звичайне значення PHP
     * (рядок, число, bool, null, масив), яке буде відформатоване у літерал.
     *
     * @return array
     */
    public static function defaults()
    {
        return [
            // --- База даних -------------------------------------------------
            'dbHost'     => '127.0.0.1',
            'dbPort'     => 3306,
            'dbName'     => '',
            'dbUser'     => '',
            'dbPassword' => '',
            'dbCharset'  => 'utf8mb4',
            'dbSocket'   => '',
            'dbPrefix'   => '',

            // --- Фід --------------------------------------------------------
            'feedUrl'        => '',
            'feedTimeout'    => 180,
            'feedRetries'    => 3,
            'feedRetryDelay' => 5,
            'feedVerifySsl'  => true,

            // --- Шляхи ------------------------------------------------------
            'pathsData'      => 'data',
            'pathsPhotos'    => 'public/photos',
            'pathsTemplates' => 'templates',
            'pathsSchema'    => 'install/schema.sql',

            // --- URL --------------------------------------------------------
            'urlsBase'   => '/base/',
            'urlsPhotos' => '/base/photos/',

            // --- Мови -------------------------------------------------------
            'langAvailable' => ['uk', 'ru'],
            'langDefault'   => 'uk',

            // --- Валюти -----------------------------------------------------
            'currencyAvailable' => ['usd', 'uah', 'eur'],

            // --- Сторінки бази обʼєктів -------------------------------------
            'siteTitleUk'        => 'База нерухомості',
            'siteTitleRu'        => 'База недвижимости',
            'sitePerPage'        => 30,
            'siteSortDefault'    => 'new',
            'siteShowSold'       => true,
            'siteMetroDistances' => [300, 500, 1000, 1500, 2000, 3000, 5000, 10000, 15000],

            // --- Фотографії -------------------------------------------------
            'photosDriver'        => 'gd',
            'photosQuality'       => 85,
            'photosLargeWidth'    => 2000,
            'photosLargeHeight'   => null,
            'photosMediumWidth'   => 600,
            'photosMediumHeight'  => null,
            'photosPreviewWidth'  => 45,
            'photosPreviewHeight' => 35,

            // --- Синхронізація ----------------------------------------------
            'syncMissingMode'  => 'sold',

            // Розклад звернень до CRM: у будні дні раз на дві години,
            // уночі та у вихідні — раз на шість.
            'syncIntervalDay'   => 7200,
            'syncIntervalNight' => 21600,
            'syncDayFrom'       => 8,
            'syncDayTo'         => 21,

            'syncPhotoLimit'   => 0,
            'syncPhotoTimeout' => 60,
            'syncLog'          => true,
            'syncLogLines'     => 5000,

            // --- Веб-запуск синхронізації -----------------------------------
            //  Порожній токен = веб-запуск вимкнений. Установник підставляє
            //  сюди випадкове значення лише якщо користувач увімкнув цей режим.
            'cronToken'       => '',
            'cronMinInterval' => 300,

            // Підказка в коментарі в кінці конфігу. Установник підставляє тут
            // справжні шлях і посилання, щоб рядок для cron можна було просто
            // скопіювати, не підставляючи нічого руками.
            'cronScriptPath' => '/шлях/до/plusestSite/bin/sync.php',
            'cronWebUrl'     => 'https://site.com/base/cron.php?token=ВАШ_ТОКЕН',

            // --- Налагодження -----------------------------------------------
            'debugEnabled' => false,
            'debugSummary' => false,
        ];
    }

    /**
     * Рендерить шаблон із переданими значеннями.
     *
     * @param string $templateFile Шлях до config.template.php
     * @param array  $values       Значення токенів. Ті, яких немає, беруться
     *                             з defaults()
     *
     * @return string Готовий вміст config.php
     *
     * @throws SiteException Якщо шаблон не знайдено або в ньому залишились
     *                       незаповнені токени
     */
    public static function render($templateFile, array $values = [])
    {
        if (!is_file($templateFile)) {
            throw new SiteException('Шаблон конфігу не знайдено: ' . $templateFile);
        }

        $template = file_get_contents($templateFile);

        if ($template === false) {
            throw new SiteException('Не вдалося прочитати шаблон конфігу: ' . $templateFile);
        }

        // Невідомі ключі відкидаємо: у шаблоні для них однаково немає токена,
        // а тиха підстановка чогось випадкового у config.php нам не потрібна.
        $known = self::defaults();
        $values = array_intersect_key($values, $known) + $known;

        $search = [];
        $replace = [];

        foreach ($values as $token => $value) {
            $search[] = '{{' . $token . '}}';
            $replace[] = in_array($token, self::$rawTokens, true)
                ? self::rawValue($value)
                : self::toPhpLiteral($value);
        }

        $result = str_replace($search, $replace, $template);

        // Страховка від помилки в шаблоні: якщо десь залишився токен, файл
        // буде зі синтаксичною помилкою. Краще впасти тут, ніж записати
        // непрацездатний config.php.
        if (preg_match_all('~\{\{([A-Za-z0-9_]+)\}\}~', $result, $matches)) {
            throw new SiteException(
                'У шаблоні конфігу залишились незаповнені токени: '
                . implode(', ', array_unique($matches[1]))
            );
        }

        return $result;
    }

    /**
     * Рендерить шаблон і записує результат у файл.
     *
     * Перед записом перевіряє результат на синтаксичні помилки, а наявний
     * config.php ніколи не перезаписує без явного дозволу — щоб випадковий
     * повторний запуск установника не знищив налаштування робочого сайту.
     *
     * @param string $templateFile Шлях до config.template.php
     * @param string $targetFile   Куди записати config.php
     * @param array  $values       Значення токенів
     * @param bool   $overwrite    Дозволити перезапис наявного файлу
     *
     * @return void
     *
     * @throws SiteException Якщо файл уже існує (без $overwrite), результат
     *                       містить синтаксичну помилку або запис не вдався
     */
    public static function write($templateFile, $targetFile, array $values = [], $overwrite = false)
    {
        if (!$overwrite && is_file($targetFile)) {
            throw new SiteException(
                'Файл налаштувань уже існує: ' . $targetFile . "\n"
                . 'Щоб перезаписати його, видаліть файл вручну.'
            );
        }

        $contents = self::render($templateFile, $values);

        self::assertValidSyntax($contents);

        Fs::writeAtomic($targetFile, $contents);
    }

    /**
     * Перетворює значення PHP у літерал для вставки у файл.
     *
     * @param mixed $value Значення
     *
     * @return string Наприклад: 'text', 3306, true, null, ['uk', 'ru']
     *
     * @throws SiteException Якщо тип значення не підтримується
     */
    public static function toPhpLiteral($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Крапка як десятковий розділювач незалежно від локалі сервера.
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        if (is_string($value)) {
            return self::quote($value);
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                // Списки друкуємо без ключів, асоціативні масиви — з ключами.
                $parts[] = is_int($key)
                    ? self::toPhpLiteral($item)
                    : self::quote((string) $key) . ' => ' . self::toPhpLiteral($item);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        throw new SiteException('Значення такого типу не можна записати в конфіг: ' . gettype($value));
    }

    /**
     * Готує значення для підстановки в текст коментаря.
     *
     * Лапок не додаємо, зате прибираємо переноси рядків і послідовність,
     * якою можна закрити блок коментаря — інакше значення, підставлене
     * всередину /* ... *&#47;, розвалило б файл.
     *
     * @param mixed $value Значення
     *
     * @return string
     */
    private static function rawValue($value)
    {
        $value = (string) $value;

        // Символи керування та переноси рядків.
        $value = preg_replace('~[\x00-\x1F\x7F]~u', ' ', $value);

        // Закриття блоку коментаря.
        $value = str_replace(['*/', '/*'], '', $value);

        return trim($value);
    }

    /**
     * Бере рядок в одинарні лапки, екрануючи те, що там небезпечно.
     *
     * В одинарних лапках PHP розуміє лише дві escape-послідовності — \' та \\ —
     * тому цього достатньо. Заодно прибираємо символи керування: у налаштуваннях
     * їм узагалі нема місця, а от зламати файл переносом рядка вони можуть.
     *
     * @param string $value Рядок
     *
     * @return string
     */
    private static function quote($value)
    {
        $value = preg_replace('~[\x00-\x1F\x7F]~u', '', $value);

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Перевіряє, що згенерований код не містить синтаксичних помилок.
     *
     * Захист від власних помилок у шаблоні: краще відмовитись від запису, ніж
     * покласти сайт файлом, який не парситься.
     *
     * @param string $contents Вміст майбутнього config.php
     *
     * @return void
     *
     * @throws SiteException Якщо знайдено синтаксичну помилку
     */
    private static function assertValidSyntax($contents)
    {
        // Парсимо справжнім парсером PHP, а не запуском `php -l`: під FPM
        // PHP_BINARY вказує на сам демон php-fpm, який ключа -l не знає й у
        // відповідь друкує свій usage, а під mod_php — на httpd, де -l означає
        // список модулів. Перше давало хибну помилку, друге — хибний успіх.
        // token_get_all() з TOKEN_PARSE не залежить ні від SAPI, ні від того,
        // чи дозволений на хостингу запуск процесів.
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new SiteException(
                'Згенерований config.php містить синтаксичну помилку: ' . $e->getMessage()
            );
        }
    }
}
