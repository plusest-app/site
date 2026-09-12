<?php

namespace Plusest\Site\Model;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Exception\SiteException;
use Plusest\Site\Feed\Client;
use Plusest\Site\Support\Fs;

/**
 * Моделі даних обʼєкта — підписи до параметрів і характеристик.
 *
 * Що це за моделі
 * ---------------
 * У фіді обʼєкт описаний машинними ключами: `heating: "autonomous-gas"`,
 * `characteristics.territory.fence: ["stone", "picket"]`. Щоб показати це
 * відвідувачу, потрібні дві речі: назва поля («Опалення») і назва значення
 * («автономне газове») — обидві двома мовами. Саме це й лежить у моделях:
 *
 *   parameters.json      — основні параметри обʼєкта;
 *   characteristics.json — додаткові характеристики, згруповані за розділами
 *                          (комунікації, приміщення, оздоблення, комфорт,
 *                          територія, ділянка...).
 *
 * Моделі лежать на plusest.app за статичними посиланнями й змінюються рідко —
 * коли в CRM додають нове поле або нове значення. Тому вони завантажуються
 * раз на добу (див. константу TTL) під час звичайного прогону синхронізації
 * і зберігаються у службовому каталозі:
 *
 *     data/models/parameters.json
 *     data/models/characteristics.json
 *
 * Чому сторінки сайту нічого не завантажують
 * ------------------------------------------
 * Рендеринг сторінки читає лише локальні файли. Якщо їх немає, шаблон покаже
 * машинні ключі — некрасиво, але сторінка відкриється. Ходити по мережу під
 * час обробки запиту відвідувача не можна: сторонній сервер може відповідати
 * секунди, і всі відвідувачі чекали б разом із ним.
 *
 * @see Attribute Форматування значень за цими моделями
 */
class ModelStore
{
    /**
     * Назва моделі основних параметрів.
     */
    const PARAMETERS = 'parameters';

    /**
     * Назва моделі додаткових характеристик.
     */
    const CHARACTERISTICS = 'characteristics';

    /**
     * Підкаталог у службовому каталозі, де лежать завантажені моделі.
     */
    const DIR = 'models';

    /**
     * Звідки завантажуються моделі даних.
     *
     * У config.php цього значення немає навмисно: моделі описують поля самої
     * CRM, тому підмінити їх власною копією означало б показувати відвідувачу
     * підписи, яких у CRM не існує. Адреса змінюється разом із пакетом.
     */
    const BASE_URL = 'https://plusest.app/source-model/';

    /**
     * Шлях кожної моделі відносно BASE_URL.
     *
     * @var string[]
     */
    private static $paths = [
        // Основні параметри обʼєкта: тип, операція, площі, поверх, опалення...
        self::PARAMETERS => 'object/parameters.json',

        // Додаткові характеристики, згруповані за розділами: комунікації,
        // приміщення, оздоблення, комфорт, територія, ділянка...
        self::CHARACTERISTICS => 'object/characteristics/consolidated.json',
    ];

    /**
     * Скільки секунд вважати завантажену копію моделі свіжою. 86400 — доба.
     *
     * Частіше не потрібно: у CRM нове поле зʼявляється кілька разів на рік.
     */
    const TTL = 86400;

    /**
     * @var Config
     */
    private $config;

    /**
     * Каталог із завантаженими моделями (абсолютний шлях).
     *
     * @var string
     */
    private $dir;

    /**
     * Уже прочитані моделі — щоб не читати той самий файл двічі за один запит.
     *
     * @var array
     */
    private $loaded = [];

    /**
     * @param Config $config Налаштування
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->dir = Fs::join($config->path('paths.data'), self::DIR);
    }

    /**
     * Модель основних параметрів обʼєкта.
     *
     * @return array Порожній масив, якщо модель ще не завантажена
     */
    public function parameters()
    {
        return $this->get(self::PARAMETERS);
    }

    /**
     * Модель додаткових характеристик, згрупованих за розділами.
     *
     * @return array Порожній масив, якщо модель ще не завантажена
     */
    public function characteristics()
    {
        return $this->get(self::CHARACTERISTICS);
    }

    /**
     * Читає модель із локального файлу.
     *
     * Помилок не кидає: відсутня модель — не причина не показати сторінку.
     *
     * @param string $name self::PARAMETERS або self::CHARACTERISTICS
     *
     * @return array
     */
    public function get($name)
    {
        if (array_key_exists($name, $this->loaded)) {
            return $this->loaded[$name];
        }

        $file = $this->file($name);
        $model = [];

        if (is_file($file)) {
            $contents = @file_get_contents($file);

            if ($contents !== false) {
                $decoded = json_decode($contents, true);

                if (is_array($decoded)) {
                    $model = $decoded;
                }
            }
        }

        $this->loaded[$name] = $model;

        return $model;
    }

    /**
     * Завантажує моделі з plusest.app, якщо термін їхньої свіжості вийшов.
     *
     * Викликається прогоном синхронізації та веб-установником. Помилку
     * завантаження не вважаємо критичною: моделі, що вже лежать на диску,
     * продовжать працювати, а спробу повторимо наступним прогоном.
     *
     * @param bool $force Завантажити навіть якщо файли ще свіжі
     *
     * @return array Масив «назва моделі => 'downloaded' | 'fresh' | текст помилки»
     */
    public function refresh($force = false)
    {
        $report = [];

        foreach (array_keys(self::$paths) as $name) {
            if (!$force && $this->isFresh($name)) {
                $report[$name] = 'fresh';

                continue;
            }

            try {
                $this->download($name);
                $report[$name] = 'downloaded';
            } catch (Exception $e) {
                $report[$name] = $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * Чи є на диску свіжа копія моделі.
     *
     * Свіжість рахується за часом зміни файлу: окремого запису в базі для
     * цього не потрібно, а працює це й тоді, коли база недоступна.
     *
     * @param string $name Назва моделі
     *
     * @return bool
     */
    public function isFresh($name)
    {
        $file = $this->file($name);

        if (!is_file($file)) {
            return false;
        }

        $time = @filemtime($file);

        return $time !== false && (time() - $time) < self::TTL;
    }

    /**
     * Чи завантажені обидві моделі.
     *
     * @return bool
     */
    public function ready()
    {
        return $this->get(self::PARAMETERS) !== [] && $this->get(self::CHARACTERISTICS) !== [];
    }

    /**
     * Шлях до локального файлу моделі.
     *
     * @param string $name Назва моделі
     *
     * @return string
     */
    public function file($name)
    {
        return Fs::join($this->dir, $this->safeName($name) . '.json');
    }

    /**
     * Час останнього оновлення моделі.
     *
     * @param string $name Назва моделі
     *
     * @return string|null Дата 'Y-m-d H:i:s' або null, якщо файлу немає
     */
    public function updatedAt($name)
    {
        $time = @filemtime($this->file($name));

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /**
     * Завантажує одну модель і записує її на диск.
     *
     * @param string $name Назва моделі
     *
     * @return void
     *
     * @throws SiteException Якщо запит не вдався або відповідь не є моделлю
     */
    private function download($name)
    {
        $url = $this->url($name);

        // Той самий клієнт, що й для фіда: ті самі таймаути, повтори при
        // збоях мережі та налаштування перевірки SSL.
        $client = new Client($url, (array) $this->config->get('feed', []));

        $body = $client->download($url);
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || $decoded === []) {
            throw new SiteException(
                'Модель "' . $name . '" завантажилась, але це не схоже на дані: '
                . 'очікували JSON-обʼєкт, отримали ' . strlen($body) . ' байтів.'
            );
        }

        // Запис атомарний: сторінка сайту може читати цей файл саме зараз,
        // і побачити напівзаписану модель вона не повинна.
        Fs::writeAtomic($this->file($name), json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Повне посилання на модель.
     *
     * @param string $name Назва моделі
     *
     * @return string
     *
     * @throws SiteException Якщо такої моделі не існує
     */
    private function url($name)
    {
        if (!isset(self::$paths[$name])) {
            throw new SiteException('Невідома модель даних: "' . $name . '".');
        }

        return rtrim(self::BASE_URL, '/') . '/' . ltrim(self::$paths[$name], '/');
    }

    /**
     * Прибирає з назви моделі все, що не може бути частиною імені файлу.
     *
     * Назви приходять із нашого ж коду, але метод захищає від помилки, за якої
     * у шлях потрапило б щось на кшталт '../config'.
     *
     * @param string $name Назва моделі
     *
     * @return string
     */
    private function safeName($name)
    {
        return preg_replace('~[^A-Za-z0-9_-]~', '', (string) $name);
    }
}
