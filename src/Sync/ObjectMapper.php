<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Arr;

/**
 * Перетворює публікацію обʼєкта з фіда в рядок таблиці objects.
 *
 * Розподіл даних між стовпцями і JSON
 * -----------------------------------
 * В окремі стовпці виносяться лише ті поля, за якими на сайті будуть
 * фільтрувати або сортувати: операція, тип, кімнати, площі, ціни, локація.
 * Усе інше — характеристики, тексти, фотографії, адреса, мультимедіа —
 * лежить у стовпці data цілим JSON, і саме звідти шаблони беруть дані для
 * рендерингу. Так додавання нового поля у фіді не вимагає міграції бази.
 *
 * Назви стовпців повторюють назви полів фіда (camelCase), тому таблиці
 * відповідності імен тут немає — і не потрібно.
 */
class ObjectMapper
{
    /**
     * Максимальні значення для числових стовпців.
     *
     * Потрібні, бо MySQL у строгому режимі падає з помилкою, якщо значення
     * не влазить у DECIMAL(12,2) чи SMALLINT. Один обʼєкт із зіпсованими
     * даними не повинен ламати весь прогін синхронізації, тому граничні
     * значення просто обрізаються.
     */
    const MAX_AREA = 9999999999.99;
    const MAX_PRICE = 999999999999.99;
    const MAX_ROOMS = 65535;
    const MAX_FLOOR = 32767;

    /**
     * Формує рядок для таблиці objects.
     *
     * Стовпці hashPhotosDisk і photosCount тут НЕ заповнюються: вони описують
     * стан локально завантажених файлів, а не те, що прийшло у фіді. Їх
     * виставляє синхронізація фотографій — після того, як файли реально
     * лягли на диск.
     *
     * @param array  $item Публікація з фіда
     * @param string $now  Поточний час у формі 'Y-m-d H:i:s'
     *
     * @return array Масив «стовпець => значення»
     *
     * @throws SiteException Якщо в публікації немає обовʼязкових полів
     */
    public static function toRow(array $item, $now)
    {
        $publicationId = Arr::str($item, 'objectPublicationId', 24);
        $objectId = Arr::str($item, 'objectId', 24);

        if ($publicationId === null) {
            throw new SiteException('У публікації немає objectPublicationId.');
        }

        if ($objectId === null) {
            throw new SiteException('У публікації ' . $publicationId . ' немає objectId.');
        }

        $location = self::location($item);

        return [
            // --- Ідентифікатори ---------------------------------------------
            'objectPublicationId' => $publicationId,
            'objectId'            => $objectId,
            'agentId'             => Arr::str($item, 'agentId', 24),

            // --- Класифікація -----------------------------------------------
            'operation'    => Arr::str($item, 'operation', 16),
            'type'         => Arr::str($item, 'type', 16),
            'whole'        => Arr::bool($item, 'whole', true),
            'commerceType' => Arr::str($item, 'commerceType', 32),
            'landType'     => Arr::str($item, 'landType', 32),
            'parkingType'  => Arr::str($item, 'parkingType', 32),

            // --- Основні параметри ------------------------------------------
            'rooms'         => Arr::int($item, 'rooms', 0, self::MAX_ROOMS),
            'roomsOverall'  => Arr::int($item, 'roomsOverall', 0, self::MAX_ROOMS),
            'areaOverall'   => Arr::float($item, 'areaOverall', 2, self::MAX_AREA),
            'areaTotal'     => Arr::float($item, 'areaTotal', 2, self::MAX_AREA),
            'areaLiving'    => Arr::float($item, 'areaLiving', 2, self::MAX_AREA),
            'areaKitchen'   => Arr::float($item, 'areaKitchen', 2, self::MAX_AREA),
            'areaLand'      => Arr::float($item, 'areaLand', 2, self::MAX_AREA),
            'floor'         => Arr::int($item, 'floor', -self::MAX_FLOOR, self::MAX_FLOOR),
            'floors'        => Arr::int($item, 'floors', -self::MAX_FLOOR, self::MAX_FLOOR),
            'newBuilding'   => Arr::bool($item, 'newBuilding'),
            'commissioning' => Arr::bool($item, 'commissioning'),

            // --- Ціни -------------------------------------------------------
            //  CRM віддає ціну одразу в трьох валютах, тому перемикання валюти
            //  на сайті нічого не перераховує.
            'priceUsd' => Arr::float($item, 'priceGeneralExchange.usd', 2, self::MAX_PRICE),
            'priceUah' => Arr::float($item, 'priceGeneralExchange.uah', 2, self::MAX_PRICE),
            'priceEur' => Arr::float($item, 'priceGeneralExchange.eur', 2, self::MAX_PRICE),
            'unitUsd'  => Arr::float($item, 'priceUnitExchange.usd', 2, self::MAX_PRICE),
            'unitUah'  => Arr::float($item, 'priceUnitExchange.uah', 2, self::MAX_PRICE),
            'unitEur'  => Arr::float($item, 'priceUnitExchange.eur', 2, self::MAX_PRICE),

            // --- Локація ----------------------------------------------------
            'cityId'           => $location['cityId'],
            'cityUk'           => $location['cityUk'],
            'cityRu'           => $location['cityRu'],
            'districtId'       => $location['districtId'],
            'districtUk'       => $location['districtUk'],
            'districtRu'       => $location['districtRu'],
            'complexId'        => Arr::str($item, 'location.complex.id', 32),
            'complexUk'        => Arr::str($item, 'location.complex.nameUk', 128),
            'complexRu'        => Arr::str($item, 'location.complex.nameRu', 128),
            'metro'            => $location['metro'],
            'metroDistanceMin' => $location['metroDistanceMin'],
            'lat'              => Arr::float($item, 'location.location.lat', 8, 90),
            'lon'              => Arr::float($item, 'location.location.lon', 8, 180),

            // --- Медіа ------------------------------------------------------
            'multimediaCount' => count(Arr::toArray($item, 'multimedia')),

            // --- Повні дані для рендерингу ----------------------------------
            'data' => self::encode($item),

            // --- Хеші --------------------------------------------------------
            //  hashData вирішує, чи перезаписувати рядок.
            //  hashPhotos — це «що повинно бути на диску»; синхронізація
            //  фотографій порівнює його з hashPhotosDisk і робить різницю.
            'hashData'   => self::dataHash($item),
            'hashPhotos' => self::photosHash($item),

            // --- Службові ---------------------------------------------------
            //  Присутність у фіді означає «обʼєкт актуальний», тому статус
            //  повертається в active навіть якщо раніше публікацію позначили
            //  реалізованою і вона знову зʼявилась у фіді.
            'status'    => 'active',
            'seenAt'    => $now,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    /**
     * Хеш усіх даних публікації — разом із масивом фотографій.
     *
     * Змінився хеш — перезаписуємо рядок. Не змінився — досить оновити seenAt
     * і йти далі, а це основна економія часу на великих базах.
     *
     * Чому масив photos тут враховується
     * ----------------------------------
     * Список фотографій лежить у стовпці data, з якого шаблони будують галерею.
     * Якби ми виключили photos із цього хеша, то при зміні лише фотографій
     * рядок не перезаписався б — і в data залишився б застарілий список,
     * тоді як у фіді він уже новий.
     *
     * Перезапис одного рядка коштує дешево. Дорога робота — завантаження і
     * масштабування файлів — залишається під захистом окремого хеша
     * photosHash(), і вона виконується лише коли справді змінились фотографії.
     *
     * @param array $item Публікація з фіда
     *
     * @return string 32 символи md5
     */
    public static function dataHash(array $item)
    {
        return self::hash($item);
    }

    /**
     * Хеш масиву фотографій — записується у стовпець hashPhotos.
     *
     * Синхронізація фотографій порівнює його зі стовпцем hashPhotosDisk.
     * Збіглися — файли на диску актуальні й до них не торкаємось. Різні —
     * завантажуємо та масштабуємо різницю.
     *
     * Важливо: стовпець hashPhotosDisk заповнює лише синхронізація
     * фотографій, і лише ПІСЛЯ того, як файли реально лягли на диск. Інакше
     * збій завантаження призвів би до того, що ми вважали б фотографії
     * наявними.
     *
     * @param array $item Публікація з фіда
     *
     * @return string 32 символи md5
     */
    public static function photosHash(array $item)
    {
        return self::hash(Arr::toArray($item, 'photos'));
    }

    /**
     * Розбирає локацію: населений пункт, район, метро.
     *
     * Назви беремо з масиву location.components — це впорядкований список
     * складових адреси, у якому кожен елемент має class:
     *
     *     state    — область
     *     region   — район області
     *     terrain  — місцевість (з уточненням type: region, community, district)
     *     city     — населений пункт
     *     district — адміністративний район міста
     *     street   — вулиця
     *     house    — будинок
     *
     * Нас цікавлять лише city і district: саме за ними будуть фільтрувати.
     * Важливо не сплутати class="district" (адміністративний район міста,
     * напр. «Галицький район») із class="terrain" type="district"
     * (мікрорайон, напр. «Снопків») — це різні речі.
     *
     * @param array $item Публікація з фіда
     *
     * @return array Значення стовпців локації
     */
    private static function location(array $item)
    {
        $components = Arr::toArray($item, 'location.components');

        $city = Arr::firstWhere($components, 'class', 'city');
        $district = Arr::firstWhere($components, 'class', 'district');

        // Якщо components у фіді немає (старіша версія CRM), беремо хоча б
        // ідентифікатори з самої location — назви тоді залишаться порожніми.
        $cityId = $city !== null
            ? Arr::str($city, 'id', 32)
            : Arr::str($item, 'location.cityId', 32);

        // location.districtId приходить то null, то масивом ідентифікаторів,
        // тому нормалізуємо його в масив і беремо перший елемент.
        $districtIds = Arr::toArray($item, 'location.districtId');

        $districtId = $district !== null
            ? Arr::str($district, 'id', 32)
            : Arr::str($districtIds, '0', 32);

        $metro = self::metro($item);

        return [
            'cityId'           => $cityId,
            'cityUk'           => Arr::str($city, 'nameUk', 128),
            'cityRu'           => Arr::str($city, 'nameRu', 128),
            'districtId'       => $districtId,
            'districtUk'       => Arr::str($district, 'nameUk', 128),
            'districtRu'       => Arr::str($district, 'nameRu', 128),
            'metro'            => $metro['json'],
            'metroDistanceMin' => $metro['nearest'],
        ];
    }

    /**
     * Розбирає станції метро.
     *
     * У фіді це або null, або список станцій із відстанню в метрах:
     *
     *     [{"id": "...", "nameUk": "Святошин", "nameRu": "Святошин", "distance": 14170}]
     *
     * Список зберігаємо як JSON, а відстань до найближчої станції — окремим
     * числом, щоб працював фільтр «поруч із метро» без розбору JSON.
     *
     * @param array $item Публікація з фіда
     *
     * @return array{json: string|null, nearest: int|null}
     */
    private static function metro(array $item)
    {
        $stations = Arr::toArray($item, 'location.metroStations');

        if ($stations === []) {
            return ['json' => null, 'nearest' => null];
        }

        $distances = [];

        foreach ($stations as $station) {
            $distance = Arr::int($station, 'distance', 0);

            if ($distance !== null) {
                $distances[] = $distance;
            }
        }

        return [
            'json'    => self::encode($stations),
            'nearest' => $distances === [] ? null : min($distances),
        ];
    }

    /**
     * Кодує значення в JSON для збереження в базі.
     *
     * Український і російський текст зберігаємо як є, без \uXXXX: так вміст
     * стовпця data можна читати очима в phpMyAdmin, а це дуже допомагає при
     * налагодженні шаблонів.
     *
     * @param mixed $value Значення
     *
     * @return string
     *
     * @throws SiteException Якщо значення не кодується в JSON
     */
    private static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new SiteException('Не вдалося закодувати дані в JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Стабільний хеш структури даних.
     *
     * Перед хешуванням ключі рекурсивно сортуються. Це важливо: якщо CRM
     * віддасть ті самі дані, але з полями в іншому порядку, хеш не змінюється
     * і ми не робимо марного перезапису.
     *
     * @param mixed $value Значення
     *
     * @return string 32 символи md5
     */
    private static function hash($value)
    {
        if (is_array($value)) {
            $value = Arr::ksortRecursive($value);
        }

        return md5(self::encode($value));
    }
}
