<?php

namespace Plusest\Site\Search;

use Plusest\Site\Config;
use Plusest\Site\Repository\ObjectRepository;

/**
 * Розібраний запит списку обʼєктів: фільтри, сортування, сторінка.
 *
 * Обʼєкт незмінний. Щоб отримати запит «те саме, але без фільтра за містом»
 * або «те саме, сторінка 3», використовуйте методи with*(): вони повертають
 * нову копію. Так шаблон може спокійно будувати десятки посилань, не боячись
 * зіпсувати поточний стан сторінки.
 *
 * Розбір адреси
 * -------------
 * Адреса складається з пар «ключ-значення», розділених слешем, — див. опис
 * класу Filters. Розбір навмисно суворий: невідомий ключ, повторений ключ або
 * непридатне значення означають, що сторінки за такою адресою не існує, і
 * fromSegments() повертає null. Front controller показує на це 404.
 *
 * Причина суворості проста: якщо ігнорувати сміття в адресі, у пошуковий
 * індекс потрапить нескінченна кількість адрес з однаковим вмістом.
 */
class Query
{
    /**
     * Ключ у адресі, яким задається сторінка.
     */
    const KEY_PAGE = 'page';

    /**
     * Ключ у адресі, яким задається сортування.
     */
    const KEY_SORT = 'sort';

    /**
     * Допустимі значення сортування.
     *
     * Ключ — значення в адресі, значення — стовпець і напрямок. Стовпець
     * 'price' і 'area' підставляються під поточну валюту та тип площі вже
     * в SearchRepository.
     *
     * @var array
     */
    private static $sorts = [
        'new'       => ['column' => 'createdAt', 'direction' => 'DESC'],
        'old'       => ['column' => 'createdAt', 'direction' => 'ASC'],
        'priceUp'   => ['column' => 'price', 'direction' => 'ASC'],
        'priceDown' => ['column' => 'price', 'direction' => 'DESC'],
        'areaUp'    => ['column' => 'areaTotal', 'direction' => 'ASC'],
        'areaDown'  => ['column' => 'areaTotal', 'direction' => 'DESC'],
    ];

    /**
     * Значення фільтрів: ключ фільтра => розібране значення.
     *
     * @var array
     */
    private $filters = [];

    /**
     * Вибране сортування.
     *
     * @var string
     */
    private $sort;

    /**
     * Сортування за замовчуванням із налаштувань.
     *
     * Потрібне, щоб не писати його в адресу: вибірка «за замовчуванням» і
     * вибірка з явно вказаним тим самим сортуванням — це одна сторінка.
     *
     * @var string
     */
    private $sortDefault = 'new';

    /**
     * Номер сторінки, від 1.
     *
     * @var int
     */
    private $page = 1;

    /**
     * Скільки обʼєктів на сторінці.
     *
     * @var int
     */
    private $perPage = 24;

    /**
     * Валюта, у якій відвідувач дивиться ціни. Впливає на сортування за ціною.
     *
     * @var string
     */
    private $currency = 'usd';

    /**
     * Статуси публікацій, які показуємо.
     *
     * @var string[]
     */
    private $statuses = [ObjectRepository::STATUS_ACTIVE];

    /**
     * Чи показувати реалізовані обʼєкти після актуальних.
     *
     * @var bool
     */
    private $soldLast = false;

    /**
     * Створює порожній запит із налаштувань — без жодного фільтра.
     *
     * @param Config $config   Налаштування
     * @param string $currency Поточна валюта відвідувача
     *
     * @return self
     */
    public static function fromConfig(Config $config, $currency = null)
    {
        $query = new self();

        $query->perPage = max(1, min(200, (int) $config->get('site.perPage', 24)));
        $query->sort = self::normalizeSort($config->get('site.sortDefault', 'new'));
        $query->sortDefault = $query->sort;
        $query->currency = $currency === null ? $config->defaultCurrency() : $currency;

        // Реалізовані обʼєкти показуємо в загальному списку, але завжди після
        // актуальних: відвідувач шукає, що можна купити зараз, а «продано» —
        // це доказ, що агентство працює, а не товар.
        if ($config->get('site.showSold', true)) {
            $query->statuses = [ObjectRepository::STATUS_ACTIVE, ObjectRepository::STATUS_SOLD];
            $query->soldLast = true;
        }

        return $query;
    }

    /**
     * Розбирає адресу списку обʼєктів.
     *
     * @param string[] $segments Частини шляху після базової адреси
     * @param Config   $config   Налаштування
     * @param string   $currency Поточна валюта відвідувача
     *
     * @return self|null null, якщо адреса непридатна — тоді це 404
     */
    public static function fromSegments(array $segments, Config $config, $currency = null)
    {
        $query = self::fromConfig($config, $currency);
        $seen = [];

        foreach ($segments as $segment) {
            $segment = (string) $segment;

            if ($segment === '') {
                continue;
            }

            $position = strpos($segment, '-');

            // Кожна частина шляху — це строго «ключ-значення».
            if ($position === false || $position === 0) {
                return null;
            }

            $key = substr($segment, 0, $position);
            $raw = substr($segment, $position + 1);

            // Двічі той самий ключ — помилка: незрозуміло, який із них брати.
            if (isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;

            if ($key === self::KEY_PAGE) {
                if (!preg_match('~^\d{1,6}$~', $raw) || (int) $raw < 1) {
                    return null;
                }

                $query->page = (int) $raw;

                continue;
            }

            if ($key === self::KEY_SORT) {
                if (!isset(self::$sorts[$raw])) {
                    return null;
                }

                $query->sort = $raw;

                continue;
            }

            $value = Filters::parse($key, $raw);

            if ($value === null) {
                return null;
            }

            $query->filters[$key] = $value;
        }

        // Ціна вибирається лише в одній валюті: два діапазони одночасно —
        // це або помилка, або спроба зробити з однієї вибірки безліч адрес.
        if (count(array_intersect(array_keys($query->filters), Filters::priceKeys())) > 1) {
            return null;
        }

        // Відстань до метро без вибраних станцій ні на що не впливає, а адресу
        // подвоює. Вважаємо таку адресу неповною.
        if (isset($query->filters['metroDistance']) && !isset($query->filters['metroId'])) {
            return null;
        }

        return $query;
    }

    /**
     * Значення всіх фільтрів.
     *
     * @return array
     */
    public function filters()
    {
        return $this->filters;
    }

    /**
     * Значення одного фільтра.
     *
     * @param string $key     Ключ фільтра
     * @param mixed  $default Що повернути, якщо фільтр не заданий
     *
     * @return mixed
     */
    public function filter($key, $default = null)
    {
        return isset($this->filters[$key]) ? $this->filters[$key] : $default;
    }

    /**
     * Чи заданий фільтр.
     *
     * @param string $key Ключ фільтра
     *
     * @return bool
     */
    public function has($key)
    {
        return isset($this->filters[$key]);
    }

    /**
     * Чи є в переліку значень фільтра конкретне значення.
     *
     * Потрібно формі: саме так позначаються вибрані пункти у списках.
     *
     * @param string $key   Ключ фільтра
     * @param mixed  $value Значення
     *
     * @return bool
     */
    public function selected($key, $value)
    {
        if (!isset($this->filters[$key])) {
            return false;
        }

        $current = $this->filters[$key];

        if (is_array($current)) {
            // Порівнюємо як рядки: у переліку кімнат лежать числа, а з форми
            // приходять рядки.
            return in_array((string) $value, array_map('strval', $current), true);
        }

        return (string) $current === (string) $value;
    }

    /**
     * Одна з меж діапазону.
     *
     * @param string $key   Ключ фільтра
     * @param string $bound 'from' або 'to'
     *
     * @return float|null
     */
    public function bound($key, $bound)
    {
        if (!isset($this->filters[$key][$bound])) {
            return null;
        }

        return $this->filters[$key][$bound];
    }

    /**
     * Скільки фільтрів задано.
     *
     * Шаблон за цим показує кнопку «скинути фільтр».
     *
     * @return int
     */
    public function count()
    {
        return count($this->filters);
    }

    /**
     * Поточне сортування.
     *
     * @return string
     */
    public function sort()
    {
        return $this->sort;
    }

    /**
     * Стовпець і напрямок поточного сортування.
     *
     * @return array Масив [column, direction]
     */
    public function sortRule()
    {
        return self::$sorts[$this->sort];
    }

    /**
     * Усі допустимі значення сортування.
     *
     * @return string[]
     */
    public static function sorts()
    {
        return array_keys(self::$sorts);
    }

    /**
     * Номер поточної сторінки.
     *
     * @return int
     */
    public function page()
    {
        return $this->page;
    }

    /**
     * Скільки обʼєктів на сторінці.
     *
     * @return int
     */
    public function perPage()
    {
        return $this->perPage;
    }

    /**
     * Скільки рядків пропустити у запиті.
     *
     * @return int
     */
    public function offset()
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Поточна валюта.
     *
     * @return string
     */
    public function currency()
    {
        return $this->currency;
    }

    /**
     * Статуси публікацій, які показуємо.
     *
     * @return string[]
     */
    public function statuses()
    {
        return $this->statuses;
    }

    /**
     * Чи виводити реалізовані обʼєкти після актуальних.
     *
     * @return bool
     */
    public function soldLast()
    {
        return $this->soldLast;
    }

    /**
     * Копія запиту зі зміненим фільтром.
     *
     * Номер сторінки скидається: після зміни фільтра сторінки 5 може вже
     * не існувати.
     *
     * @param string $key   Ключ фільтра
     * @param mixed  $value Розібране значення або null, щоб прибрати фільтр
     *
     * @return self
     */
    public function with($key, $value)
    {
        $copy = clone $this;
        $copy->page = 1;

        if ($value === null || $value === [] || $value === '') {
            unset($copy->filters[$key]);

            // Відстань до метро без станцій не має сенсу — прибираємо разом.
            if ($key === 'metroId') {
                unset($copy->filters['metroDistance']);
            }

            return $copy;
        }

        $copy->filters[$key] = $value;

        return $copy;
    }

    /**
     * Копія запиту без жодного фільтра.
     *
     * @return self
     */
    public function reset()
    {
        $copy = clone $this;
        $copy->filters = [];
        $copy->page = 1;

        return $copy;
    }

    /**
     * Копія запиту з іншим номером сторінки.
     *
     * @param int $page Номер сторінки
     *
     * @return self
     */
    public function withPage($page)
    {
        $copy = clone $this;
        $copy->page = max(1, (int) $page);

        return $copy;
    }

    /**
     * Копія запиту з іншим сортуванням.
     *
     * @param string $sort Значення сортування
     *
     * @return self
     */
    public function withSort($sort)
    {
        $copy = clone $this;
        $copy->sort = self::normalizeSort($sort);
        $copy->page = 1;

        return $copy;
    }

    /**
     * Копія запиту з іншою валютою.
     *
     * Діапазон ціни при цьому прибирається: перерахувати «від 50 000 доларів»
     * у гривні ми не можемо — курс нам ніхто не передавав.
     *
     * @param string $currency Валюта
     *
     * @return self
     */
    public function withCurrency($currency)
    {
        $copy = clone $this;
        $copy->currency = (string) $currency;

        foreach (Filters::priceKeys() as $key) {
            unset($copy->filters[$key]);
        }

        return $copy;
    }

    /**
     * Частини шляху, що описують цей запит.
     *
     * Порядок фіксований — спочатку фільтри в порядку опису у Filters, потім
     * сортування, потім сторінка. Завдяки цьому одна вибірка завжди має одну
     * адресу, і front controller може порівняти її з тією, що прийшла, та
     * зробити 301 на канонічну.
     *
     * @return string[]
     */
    public function segments()
    {
        $segments = [];

        foreach (Filters::keys() as $key) {
            if (!isset($this->filters[$key])) {
                continue;
            }

            $segment = Filters::format($key, $this->filters[$key]);

            if ($segment !== null) {
                $segments[] = $segment;
            }
        }

        // Сортування за замовчуванням в адресу не пишемо — воно й так діє.
        if ($this->sort !== $this->sortDefault) {
            $segments[] = self::KEY_SORT . '-' . $this->sort;
        }

        if ($this->page > 1) {
            $segments[] = self::KEY_PAGE . '-' . $this->page;
        }

        return $segments;
    }

    /**
     * Приводить значення сортування до допустимого.
     *
     * @param string|null $sort Значення з налаштувань або адреси
     *
     * @return string
     */
    private static function normalizeSort($sort)
    {
        $sort = (string) $sort;

        return isset(self::$sorts[$sort]) ? $sort : 'new';
    }
}
