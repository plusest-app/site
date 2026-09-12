<?php

namespace Plusest\Site\Search;

/**
 * Опис усіх фільтрів пошуку — єдине джерело правди.
 *
 * Один і той самий опис використовують чотири різні речі:
 *
 *   * розбір адреси      — Query::fromPath() перетворює '/rooms-2,3/' у значення;
 *   * складання адреси   — Url будує ЧПУ назад із значень;
 *   * побудова запиту    — SearchRepository робить із них WHERE;
 *   * форма фільтра      — шаблон знає, які поля показати і як їх назвати.
 *
 * Тому додати новий фільтр — це дописати рядок сюди (і, якщо потрібно, поле у
 * формі). Нічого іншого правити не доведеться.
 *
 * Формат адреси
 * -------------
 * Фільтри стоять у шляху парами «ключ-значення», розділеними слешем:
 *
 *     /base/operation-sale/type-house,apartment/rooms-2,3/floor-2_5/
 *     priceUsd-50000_150000/cityId-ixi7l922xsyfctdasd292ldygxtossi8/page-2/
 *
 *   * кілька значень — через кому:            type-house,apartment
 *   * діапазон — через підкреслення:          floor-2_5
 *   * діапазон з одного боку — порожня межа:  floors-_9  (не вище девʼятого)
 *
 * Ідентифікатори локацій пишемо як є, без транслітерації назв: назва міста
 * може змінитись (перейменування вулиць і міст в Україні — звична справа),
 * а ідентифікатор у CRM залишиться тим самим, і збережені відвідувачем
 * посилання не зламаються.
 *
 * Види фільтрів (kind)
 * --------------------
 *   values  — перелік допустимих значень зі списку 'values';
 *   numbers — перелік цілих чисел (кількість кімнат);
 *   ids     — перелік ідентифікаторів CRM (місто, район, метро);
 *   range   — діапазон «від-до» для числового стовпця;
 *   number  — одне ціле число (відстань до метро).
 */
class Filters
{
    /**
     * Максимальна довжина ідентифікатора CRM.
     *
     * У фіді трапляються два формати: 24 символи (ObjectId) і 32 символи
     * (ідентифікатори довідника локацій). Беремо з запасом — стовпці в базі
     * теж VARCHAR(32).
     */
    const ID_LENGTH = 32;

    /**
     * Опис фільтрів. Ключ масиву — ключ у ЧПУ.
     *
     *   kind     — вид фільтра, див. опис класу;
     *   column   — стовпець таблиці objects, за яким фільтруємо;
     *   values   — допустимі значення (для kind = values);
     *   map      — чим замінити значення перед підстановкою в SQL;
     *   limit    — скільки значень дозволено вибрати одночасно;
     *   max      — верхня межа числа (захист від сміття в адресі);
     *   label    — ключ у моделі параметрів, з якого брати підпис поля;
     *   currency — валюта, до якої належить стовпець ціни.
     *
     * Навіщо потрібен map
     * -------------------
     * Логічні стовпці в базі — це TINYINT 1/0, а в адресі числа виглядали б
     * незрозуміло: '/whole-1/' нічого не каже ні відвідувачу, ні пошуковій
     * системі. Тому в адресі стоять слова ('whole-part'), а map перекладає
     * їх у значення стовпця перед побудовою запиту.
     *
     * @var array
     */
    private static $definitions = [
        // --- Класифікація ---------------------------------------------------
        //  Операція і тип нерухомості — вибір ОДНОГО значення. Пошук «продаж
        //  або оренда одночасно» не має сенсу: у цих двох випадків різні
        //  ціни, різні валюти й різні очікування відвідувача. А від того, що
        //  вибір один, залежать і решта полів фільтра: тип автомісця
        //  показується лише для автомість, кімнати — лише для житла.
        'operation' => [
            'kind'   => 'values',
            'column' => 'operation',
            'values' => ['sale', 'rent'],
            'limit'  => 1,
            'label'  => 'operation',
        ],
        'type' => [
            'kind'   => 'values',
            'column' => 'type',
            'values' => ['apartment', 'house', 'land', 'parking', 'commerce'],
            'limit'  => 1,
            'label'  => 'type',
        ],

        // Весь обʼєкт чи лише його частина. У базі це TINYINT: 1 — цілком.
        'whole' => [
            'kind'   => 'values',
            'column' => 'whole',
            'values' => ['full', 'part'],
            'map'    => ['full' => 1, 'part' => 0],
            'limit'  => 1,
        ],

        // --- Уточнення типу нерухомості -------------------------------------
        //  Кожне з цих полів заповнене лише в обʼєктів свого типу, тому у
        //  фільтрі вони показуються за вибраним типом (це робить шаблон).
        //  Вибір тут множинний: «офіс або магазин» — звичайний запит.
        //
        //  Переліки значень повторюють модель даних parameters.json. Тримати
        //  їх тут потрібно тому, що значення з адреси перевіряється ДО
        //  звернення до бази, а модель може бути ще не завантажена.
        'commerceType' => [
            'kind'   => 'values',
            'column' => 'commerceType',
            'values' => [
                'premises', 'office', 'manufacture', 'shop', 'warehouse', 'restaurant',
                'hotel', 'medicine', 'gym', 'beauty', 'fun', 'farmer', 'car',
                'building', 'saf', 'other',
            ],
            'limit'  => 16,
            'label'  => 'commerceType',
        ],
        'landType' => [
            'kind'   => 'values',
            'column' => 'landType',
            'values' => ['a', 'b', 'c', 'd', 'e', 'g', 'h', 'i', 'j', 'k'],
            'limit'  => 10,
            'label'  => 'landType',
        ],
        'parkingType' => [
            'kind'   => 'values',
            'column' => 'parkingType',
            'values' => ['garage', 'parking', 'open-air', 'box', 'hangar'],
            'limit'  => 5,
            'label'  => 'parkingType',
        ],

        // --- Новобудова -----------------------------------------------------
        //  Два окремих питання, які часто плутають: newBuilding — це «дім
        //  новий», а commissioning — «його вже здали в експлуатацію». Купівля
        //  в недобудованому будинку дешевша, але з ризиком, тому відвідувач
        //  цілком свідомо шукає то одне, то інше.
        'newBuilding' => [
            'kind'   => 'values',
            'column' => 'newBuilding',
            'values' => ['yes', 'no'],
            'map'    => ['yes' => 1, 'no' => 0],
            'limit'  => 1,
            'label'  => 'newBuilding',
        ],
        'commissioning' => [
            'kind'   => 'values',
            'column' => 'commissioning',
            'values' => ['yes', 'no'],
            'map'    => ['yes' => 1, 'no' => 0],
            'limit'  => 1,
            'label'  => 'commissioning',
        ],

        // --- Основні параметри ----------------------------------------------
        //  Кімнати — саме перелік, а не діапазон: відвідувач шукає «дво- або
        //  трикімнатну», а не «від двох до трьох кімнат».
        'rooms' => [
            'kind'   => 'numbers',
            'column' => 'rooms',
            'limit'  => 10,
            'max'    => 30,
            'label'  => 'rooms',
        ],
        'floor' => [
            'kind'   => 'range',
            'column' => 'floor',
            'max'    => 200,
            'label'  => 'floor',
        ],
        'floors' => [
            'kind'   => 'range',
            'column' => 'floors',
            'max'    => 200,
            'label'  => 'floors',
        ],
        'areaTotal' => [
            'kind'   => 'range',
            'column' => 'areaTotal',
            'max'    => 1000000,
            'label'  => 'areaTotal',
        ],
        'areaLand' => [
            'kind'   => 'range',
            'column' => 'areaLand',
            'max'    => 1000000,
            'label'  => 'areaLand',
        ],

        // --- Ціна -----------------------------------------------------------
        //  Ключ у адресі містить валюту, бо адресу відвідувач може надіслати
        //  комусь іншому, у кого в браузері вибрана інша валюта. «Від 50 000»
        //  без валюти в такому посиланні означало б різні речі.
        'priceUsd' => [
            'kind'     => 'range',
            'column'   => 'priceUsd',
            'max'      => 999999999999,
            'currency' => 'usd',
            'label'    => 'priceGeneral',
        ],
        'priceUah' => [
            'kind'     => 'range',
            'column'   => 'priceUah',
            'max'      => 999999999999,
            'currency' => 'uah',
            'label'    => 'priceGeneral',
        ],
        'priceEur' => [
            'kind'     => 'range',
            'column'   => 'priceEur',
            'max'      => 999999999999,
            'currency' => 'eur',
            'label'    => 'priceGeneral',
        ],

        // --- Локація --------------------------------------------------------
        'cityId' => [
            'kind'   => 'ids',
            'column' => 'cityId',
            'limit'  => 20,
        ],
        'districtId' => [
            'kind'   => 'ids',
            'column' => 'districtId',
            'limit'  => 30,
        ],

        // --- Метро ----------------------------------------------------------
        //  Фільтрується не стовпцем, а приєднанням таблиці objectMetro, тому
        //  column тут немає. Пара «станції + відстань» працює разом:
        //  «у межах 800 метрів від будь-якої з вибраних станцій».
        //
        //  Обмеження на кількість станцій немає навмисно: у Києві їх понад
        //  пʼятдесят, і відвідувач цілком може шукати «будь-де на червоній
        //  лінії».
        'metroId' => [
            'kind' => 'ids',
        ],
        'metroDistance' => [
            'kind' => 'number',
            'max'  => 30000,
        ],
    ];

    /**
     * Усі ключі фільтрів у порядку, у якому вони стоять в адресі.
     *
     * Порядок фіксований: одна й та сама вибірка мусить мати одну адресу,
     * інакше пошукові системи вважатимуть їх різними сторінками.
     *
     * @return string[]
     */
    public static function keys()
    {
        return array_keys(self::$definitions);
    }

    /**
     * Опис одного фільтра.
     *
     * @param string $key Ключ фільтра
     *
     * @return array|null null, якщо такого фільтра немає
     */
    public static function definition($key)
    {
        return isset(self::$definitions[$key]) ? self::$definitions[$key] : null;
    }

    /**
     * Ключ фільтра ціни для вказаної валюти.
     *
     * @param string $currency 'usd', 'uah' або 'eur'
     *
     * @return string Наприклад 'priceUsd'
     */
    public static function priceKey($currency)
    {
        return 'price' . ucfirst(strtolower((string) $currency));
    }

    /**
     * Перекладає значення фільтра у значення стовпця бази.
     *
     * Потрібно тим фільтрам, у яких опис містить `map`: в адресі стоїть слово
     * ('part'), а в стовпці — число (0). Фільтри без `map` повертаються як є.
     *
     * @param string $key    Ключ фільтра
     * @param array  $values Значення, розібрані з адреси
     *
     * @return array Значення, придатні для підстановки в SQL
     */
    public static function toColumn($key, array $values)
    {
        $definition = self::definition($key);

        if ($definition === null || !isset($definition['map'])) {
            return $values;
        }

        $mapped = [];

        foreach ($values as $one) {
            if (array_key_exists($one, $definition['map'])) {
                $mapped[] = $definition['map'][$one];
            }
        }

        return $mapped;
    }

    /**
     * Ключі фільтрів ціни для всіх валют.
     *
     * @return string[]
     */
    public static function priceKeys()
    {
        $keys = [];

        foreach (self::$definitions as $key => $definition) {
            if (isset($definition['currency'])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Розбирає значення фільтра з адреси.
     *
     * Повертає null, якщо значення непридатне — а це для нас означає, що
     * сторінки за такою адресою не існує. Тихо відкидати сміття не можна:
     * інакше '/rooms-abc/' показав би повний список обʼєктів, а пошуковик
     * вирішив би, що в нас нескінченно багато сторінок з однаковим вмістом.
     *
     * @param string $key Ключ фільтра
     * @param string $raw Значення з адреси, як його написав відвідувач
     *
     * @return array|int|null Розібране значення або null, якщо воно хибне
     */
    public static function parse($key, $raw)
    {
        $definition = self::definition($key);

        if ($definition === null || $raw === '') {
            return null;
        }

        switch ($definition['kind']) {
            case 'values':
                return self::parseValues($definition, $raw);

            case 'numbers':
                return self::parseNumbers($definition, $raw);

            case 'ids':
                return self::parseIds($definition, $raw);

            case 'range':
                return self::parseRange($definition, $raw);

            case 'number':
                return self::parseNumber($definition, $raw);
        }

        return null;
    }

    /**
     * Складає значення фільтра назад у частину адреси.
     *
     * @param string           $key   Ключ фільтра
     * @param array|int|string $value Розібране значення
     *
     * @return string|null Наприклад 'type-house,apartment'. null — значення порожнє
     */
    public static function format($key, $value)
    {
        $definition = self::definition($key);

        if ($definition === null || $value === null || $value === [] || $value === '') {
            return null;
        }

        if ($definition['kind'] === 'range') {
            $from = isset($value['from']) && $value['from'] !== null ? self::formatNumber($value['from']) : '';
            $to = isset($value['to']) && $value['to'] !== null ? self::formatNumber($value['to']) : '';

            if ($from === '' && $to === '') {
                return null;
            }

            return $key . '-' . $from . '_' . $to;
        }

        if (is_array($value)) {
            return $value === [] ? null : $key . '-' . implode(',', $value);
        }

        return $key . '-' . $value;
    }

    /**
     * Значення зі списку допустимих.
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Значення з адреси
     *
     * @return string[]|null
     */
    private static function parseValues(array $definition, $raw)
    {
        $allowed = isset($definition['values']) ? $definition['values'] : [];
        $result = [];

        foreach (explode(',', $raw) as $one) {
            $one = trim($one);

            // Невідоме значення — це саме помилка, а не «нічого не вибрано».
            if (!in_array($one, $allowed, true)) {
                return null;
            }

            if (!in_array($one, $result, true)) {
                $result[] = $one;
            }
        }

        return self::limited($definition, $result);
    }

    /**
     * Перелік цілих чисел.
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Значення з адреси
     *
     * @return int[]|null
     */
    private static function parseNumbers(array $definition, $raw)
    {
        $max = isset($definition['max']) ? (int) $definition['max'] : null;
        $result = [];

        foreach (explode(',', $raw) as $one) {
            if (!preg_match('~^\d{1,3}$~', trim($one))) {
                return null;
            }

            $number = (int) $one;

            if ($max !== null && $number > $max) {
                return null;
            }

            if (!in_array($number, $result, true)) {
                $result[] = $number;
            }
        }

        sort($result);

        return self::limited($definition, $result);
    }

    /**
     * Перелік ідентифікаторів CRM.
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Значення з адреси
     *
     * @return string[]|null
     */
    private static function parseIds(array $definition, $raw)
    {
        $result = [];

        foreach (explode(',', $raw) as $one) {
            $one = trim($one);

            if (!preg_match('~^[A-Za-z0-9_-]{1,' . self::ID_LENGTH . '}$~', $one)) {
                return null;
            }

            if (!in_array($one, $result, true)) {
                $result[] = $one;
            }
        }

        // Порядок не має значення для запиту, але має для адреси: та сама
        // вибірка мусить давати той самий рядок.
        sort($result);

        return self::limited($definition, $result);
    }

    /**
     * Діапазон «від-до».
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Значення з адреси
     *
     * @return array|null Масив [from, to]
     */
    private static function parseRange(array $definition, $raw)
    {
        // Підкреслення обовʼязкове навіть коли задана лише одна межа:
        // 'floor-2_' і 'floor-_2' — різні речі, а просто 'floor-2' було б
        // незрозуміло яка з них.
        if (strpos($raw, '_') === false) {
            return null;
        }

        list($from, $to) = explode('_', $raw, 2);

        $from = self::rangeBound($definition, $from);
        $to = self::rangeBound($definition, $to);

        if ($from === false || $to === false) {
            return null;
        }

        if ($from === null && $to === null) {
            return null;
        }

        // Межі навпаки — очевидна помилка в адресі.
        if ($from !== null && $to !== null && $from > $to) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Одна межа діапазону.
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Текст межі
     *
     * @return float|null|false null — межі немає, false — значення хибне
     */
    private static function rangeBound(array $definition, $raw)
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Дозволяємо дробові значення: площа буває 40.5 м².
        if (!preg_match('~^\d{1,12}(\.\d{1,2})?$~', $raw)) {
            return false;
        }

        $value = (float) $raw;

        if (isset($definition['max']) && $value > $definition['max']) {
            return false;
        }

        return $value;
    }

    /**
     * Одне ціле число.
     *
     * @param array  $definition Опис фільтра
     * @param string $raw        Значення з адреси
     *
     * @return int|null
     */
    private static function parseNumber(array $definition, $raw)
    {
        if (!preg_match('~^\d{1,6}$~', trim($raw))) {
            return null;
        }

        $value = (int) $raw;

        if ($value <= 0) {
            return null;
        }

        if (isset($definition['max']) && $value > $definition['max']) {
            return null;
        }

        return $value;
    }

    /**
     * Обрізає перелік до дозволеної кількості значень.
     *
     * Захист від адреси з тисячею ідентифікаторів: такий запит поклав би базу,
     * а користі від нього немає.
     *
     * @param array $definition Опис фільтра
     * @param array $values     Значення
     *
     * @return array|null
     */
    private static function limited(array $definition, array $values)
    {
        if ($values === []) {
            return null;
        }

        if (isset($definition['limit']) && count($values) > $definition['limit']) {
            $values = array_slice($values, 0, (int) $definition['limit']);
        }

        return $values;
    }

    /**
     * Число у вигляді, придатному для адреси.
     *
     * Цілі значення пишемо без дробової частини: 'areaTotal-40_80' виглядає
     * зрозуміліше за 'areaTotal-40.00_80.00'.
     *
     * @param float $value Число
     *
     * @return string
     */
    private static function formatNumber($value)
    {
        $value = (float) $value;

        if ($value === floor($value)) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
