<?php

namespace Plusest\Site\Web;

use Plusest\Site\Config;
use Plusest\Site\Model\Attribute;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Repository\ObjectRepository;
use Plusest\Site\Support\Arr;

/**
 * Готує дані обʼєкта до виведення в шаблоні.
 *
 * Уся логіка «як показати» зібрана тут, а не в шаблонах: у шаблонах
 * залишається лише верстка, яку користувач переробляє під свій сайт. Якщо
 * потрібно змінити спосіб показу — наприклад додати до заголовка район —
 * правити треба цей клас, і зміна одразу підхопиться і в списку, і на
 * сторінці обʼєкта.
 *
 * Мова й валюта передаються в конструктор, тому в методах їх указувати не
 * потрібно: за один запит вони не змінюються.
 */
class Present
{
    /**
     * Скільки днів обʼєкт вважається новим — для позначки «NEW» на картці.
     */
    const NEW_DAYS = 7;

    /**
     * Запас часу на перший прогін синхронізації, секунди.
     *
     * Позначку «NEW» ставимо за часом появи рядка в локальній базі, а при
     * першому прогоні туди разом падає вся база агентства. Тому обʼєкти,
     * записані протягом години після першого звернення до CRM, новими не
     * вважаються — інакше весь список тиждень стояв би в синіх позначках.
     */
    const FIRST_SYNC_GRACE = 3600;

    /**
     * Параметри, які показуються в таблиці на сторінці обʼєкта, у порядку показу.
     *
     * Операція і тип нерухомості тут не потрібні — вони й так у заголовку.
     * Поля, яких у конкретного обʼєкта немає (площа ділянки в квартири,
     * тип комерції в будинку), пропускаються самі.
     *
     * @var string[]
     */
    private static $parameterKeys = [
        // Поля 'whole' тут немає навмисно: «частина обʼєкта» вже стоїть у
        // заголовку («Продаж частини будинку»), і рядок «Частина обʼєкта:
        // так» одразу під ним нічого не додає.
        'rooms',
        'roomsOverall',
        'areaTotal',
        'areaLiving',
        'areaKitchen',
        'areaOverall',
        'areaLand',
        'floor',
        'floors',
        'multilevel',
        'layout',
        'heating',
        'repair',
        'houseWall',
        'houseType',
        'houseCondition',
        'commerceType',
        'commerceName',
        'commercePosition',
        'parkingType',
        'landType',
        'newBuilding',
        'commissioning',
        'priceBargain',
        'priceCredit',
    ];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ModelStore
     */
    private $models;

    /**
     * @var Translator
     */
    private $t;

    /**
     * @var Url
     */
    private $url;

    /**
     * Поточна мова: 'uk' або 'ru'.
     *
     * @var string
     */
    private $lang;

    /**
     * Поточна валюта: 'usd', 'uah' або 'eur'.
     *
     * @var string
     */
    private $currency;

    /**
     * Час першого успішного отримання фіда — 'Y-m-d H:i:s' або null.
     *
     * @var string|null
     */
    private $firstSyncAt;

    /**
     * @param Config      $config      Налаштування
     * @param ModelStore  $models      Моделі даних
     * @param Translator  $t           Переклади інтерфейсу
     * @param Url         $url         Побудовник адрес
     * @param string      $lang        Поточна мова
     * @param string      $currency    Поточна валюта
     * @param string|null $firstSyncAt Час першого прогону синхронізації
     */
    public function __construct(
        Config $config,
        ModelStore $models,
        Translator $t,
        Url $url,
        $lang = 'uk',
        $currency = 'usd',
        $firstSyncAt = null
    ) {
        $this->config = $config;
        $this->models = $models;
        $this->t = $t;
        $this->url = $url;
        $this->lang = $lang;
        $this->currency = $currency;
        $this->firstSyncAt = $firstSyncAt;
    }

    /**
     * Назва обʼєкта однією фразою: «Продаж частини 3к будинку».
     *
     * Складається з операції, ознаки «частина обʼєкта», кількості кімнат і
     * уточненого типу нерухомості. Слова беруться з перекладів (ключі з
     * префіксом `name.`), а не з моделі даних: у моделі вони стоять у
     * називному відмінку («будинок»), а у фразі потрібен родовий («будинку»).
     *
     * Дві форми назви
     * ---------------
     *   $full = true  — повна, для заголовка H1 сторінки публікації:
     *                   «Продаж 3к будинку»;
     *   $full = false — коротка, для картки в списку: «Продаж будинку».
     *
     * У картці кількість кімнат не потрібна — вона й так стоїть окремим
     * параметром під фотографією, а назва в три рядки ламає сітку.
     *
     * @param array $row  Рядок публікації
     * @param bool  $full Повна форма
     *
     * @return string
     */
    public function title(array $row, $full = false)
    {
        $name = '';
        $operation = (string) $row['operation'];

        if ($operation === 'sale' || $operation === 'rent') {
            $name = $this->t->get('name.' . $operation);
        }

        $type = (string) $row['type'];

        // Обʼєкт цілком чи лише частина. У базі це TINYINT, тому '0' — це
        // саме частина обʼєкта, а не «поле не заповнене».
        $whole = !empty($row['whole']);

        switch ($type) {
            // Квартира й будинок описуються однаково: у них є кімнати, і в
            // частини обʼєкта показуємо і свої кімнати, і кімнати всього
            // обʼєкта — «частини (2к) 5к будинку».
            case 'apartment':
            case 'house':
                if (!$whole) {
                    $name .= ' ' . $this->t->get('name.part');

                    if ($full) {
                        if ($this->positive($row, 'rooms')) {
                            $name .= ' (' . $this->roomsWord($row['rooms']) . ')';
                        }

                        if ($this->positive($row, 'roomsOverall')) {
                            $name .= ' ' . $this->roomsWord($row['roomsOverall']);
                        }
                    }
                } elseif ($full && $this->positive($row, 'rooms')) {
                    $name .= ' ' . $this->roomsWord($row['rooms']);
                }

                $name .= ' ' . $this->t->get('name.type.' . $type);

                break;

            // Ділянка: тип призначення важливіший за все інше, бо саме він
            // вирішує, що на цій землі дозволено будувати.
            case 'land':
                $name .= ' ' . $this->t->get('name.type.land');

                $landType = $this->nameWord('landType', $row['landType']);

                if ($landType !== null) {
                    $name .= ' ' . $landType;
                }

                break;

            // Автомісце: гараж, бокс і місце на стоянці — різні речі, тому
            // тип підставляється замість загального слова, а не після нього.
            case 'parking':
                $parkingType = $this->nameWord('parkingType', $row['parkingType']);

                $name .= ' ' . ($parkingType === null
                    ? $this->t->get('name.type.parking')
                    : $parkingType);

                break;

            // Комерція: так само замість загального слова, але «частина»
            // тут можлива — половину будівлі продають часто.
            case 'commerce':
                if (!$whole) {
                    $name .= ' ' . $this->t->get('name.part');
                }

                $commerceType = $this->nameWord('commerceType', $row['commerceType']);

                $name .= ' ' . ($commerceType === null
                    ? $this->t->get('name.type.commerce')
                    : $commerceType);

                break;

            default:
                // Тип, якого ми не знаємо (CRM додала новий) — показуємо хоча
                // б його назву з моделі даних.
                $label = $this->typeLabel($row);

                if ($label !== '') {
                    $name .= ' ' . mb_strtolower($label, 'UTF-8');
                }

                break;
        }

        return trim($name);
    }

    /**
     * Повна назва обʼєкта — для заголовка сторінки публікації.
     *
     * @param array $row Рядок публікації
     *
     * @return string
     */
    public function fullTitle(array $row)
    {
        return $this->title($row, true);
    }

    /**
     * Назва типу нерухомості з моделі: «квартира», «будинок»...
     *
     * @param array $row Рядок публікації
     *
     * @return string
     */
    public function typeLabel(array $row)
    {
        $options = Attribute::options($this->models->parameters(), 'type', $this->lang);
        $type = (string) $row['type'];

        // Перша літера велика: у моделі значення записані з малої, бо там
        // вони стоять усередині фрази.
        return isset($options[$type]) ? $this->upperFirst($options[$type]) : $type;
    }

    /**
     * Назва операції з моделі: «продаж» або «оренда».
     *
     * @param array $row Рядок публікації
     *
     * @return string
     */
    public function operationLabel(array $row)
    {
        $options = Attribute::options($this->models->parameters(), 'operation', $this->lang);
        $operation = (string) $row['operation'];

        return isset($options[$operation]) ? $this->upperFirst($options[$operation]) : $operation;
    }

    /**
     * Ціна у поточній валюті.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null null, якщо ціна не вказана
     */
    public function price(array $row)
    {
        return $this->money($row, 'price');
    }

    /**
     * Ціна за одиницю площі у поточній валюті.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function unitPrice(array $row)
    {
        return $this->money($row, 'unit');
    }

    /**
     * Підпис до ціни за одиницю площі: «за м²» або «за соту».
     *
     * Для землі ціна вважається за соту, для решти — за квадратний метр.
     *
     * @param array $row Рядок публікації
     *
     * @return string
     */
    public function unitLabel(array $row)
    {
        return $row['type'] === 'land'
            ? $this->t->get('card.unitLand')
            : $this->t->get('card.unit');
    }

    /**
     * Другий рядок під ціною.
     *
     * Для продажу це ціна за квадратний метр (для ділянки — за соту): саме
     * за нею відвідувач порівнює обʼєкти між собою. Для оренди ціни за метр
     * не буває — там важливо інше: що це платіж за місяць, а не за весь
     * термін.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null null, якщо показувати нічого
     */
    public function priceNote(array $row)
    {
        if ($row['operation'] === 'rent') {
            return $this->t->get('card.perMonth');
        }

        $unit = $this->unitPrice($row);

        if ($unit === null) {
            return null;
        }

        return $unit . ' ' . $this->unitLabel($row);
    }

    /**
     * Чи можливий торг за цим обʼєктом.
     *
     * @param array $row Рядок публікації
     *
     * @return bool
     */
    public function hasBargain(array $row)
    {
        return !empty($row['data']['priceBargain']);
    }

    /**
     * Чи можливі кредит, іпотека або розтермінування.
     *
     * @param array $row Рядок публікації
     *
     * @return bool
     */
    public function hasCredit(array $row)
    {
        return !empty($row['data']['priceCredit']);
    }

    /**
     * Площа обʼєкта в короткому вигляді — «250 м²» або «4 сот.» для ділянки.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function areaText(array $row)
    {
        if ($row['areaTotal'] !== null && (float) $row['areaTotal'] > 0) {
            return $this->t->get('card.area', ['value' => Attribute::number($row['areaTotal'], 1)]);
        }

        if ($row['areaLand'] !== null && (float) $row['areaLand'] > 0) {
            return $this->t->get('card.areaLand', ['value' => Attribute::number($row['areaLand'], 2)]);
        }

        return null;
    }

    /**
     * Кількість кімнат словом: «3 кімнати», «2 приміщення».
     *
     * Для комерції та автомість у CRM це саме приміщення, а не кімнати:
     * «5 кімнат» про склад чи гараж звучало б дивно.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null null, якщо кількість не вказана
     */
    public function roomsText(array $row)
    {
        if (!$this->positive($row, 'rooms')) {
            return null;
        }

        $key = in_array($row['type'], ['commerce', 'parking'], true)
            ? 'unit.premises'
            : 'unit.rooms';

        return $this->t->counted($key, (int) $row['rooms']);
    }

    /**
     * Поверх коротко: «3 / 9».
     *
     * Компактна форма для рядка параметрів у картці. Розгорнутий підпис
     * («поверх 3 з 9») віддає floorText() — його ставлять у title, щоб
     * значення «3 / 9» не потребувало пояснень.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function floorShort(array $row)
    {
        $floor = $row['floor'] === null ? null : (int) $row['floor'];
        $floors = $row['floors'] === null ? null : (int) $row['floors'];

        if ($floor === null && $floors === null) {
            return null;
        }

        if ($floor === null) {
            return (string) $floors;
        }

        if ($floors === null) {
            return (string) $floor;
        }

        return $floor . ' / ' . $floors;
    }

    /**
     * Чи зʼявився обʼєкт у базі менш ніж NEW_DAYS днів тому.
     *
     * Дати публікації в CRM фід не передає, тому рахуємо за часом появи
     * рядка в локальній базі. Обʼєкти, записані першим прогоном
     * синхронізації, новими не вважаються — див. FIRST_SYNC_GRACE.
     *
     * @param array $row Рядок публікації
     *
     * @return bool
     */
    public function isNew(array $row)
    {
        if (empty($row['createdAt'])) {
            return false;
        }

        $created = strtotime((string) $row['createdAt']);

        if ($created === false) {
            return false;
        }

        if (time() - $created > self::NEW_DAYS * 86400) {
            return false;
        }

        // Перший прогін наповнює базу цілком, і всі ті обʼєкти отримують
        // однаковий createdAt. Новими вони не є — це просто встановлення.
        if ($this->firstSyncAt !== null) {
            $first = strtotime($this->firstSyncAt);

            if ($first !== false && $created <= $first + self::FIRST_SYNC_GRACE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Позначка на реалізованому обʼєкті: «Продано» або «Здано».
     *
     * @param array $row Рядок публікації
     *
     * @return string
     */
    public function soldLabel(array $row)
    {
        return $this->t->get($row['operation'] === 'rent' ? 'card.soldRent' : 'card.soldSale');
    }

    /**
     * Поверх у вигляді «поверх 3 з 9».
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function floorText(array $row)
    {
        // Три різні випадки: у квартири відомий і поверх, і поверховість
        // будинку, у приватного будинку — лише поверховість, а в частини
        // будинку буває навпаки. Прочерк замість числа виглядав би так,
        // ніби дані втратились.
        if ($row['floor'] === null && $row['floors'] === null) {
            return null;
        }

        if ($row['floor'] === null) {
            return $this->t->get('card.floorsOnly', ['floors' => (int) $row['floors']]);
        }

        if ($row['floors'] === null) {
            return $this->t->get('card.floorOnly', ['floor' => (int) $row['floor']]);
        }

        return $this->t->get('card.floor', [
            'floor'  => (int) $row['floor'],
            'floors' => (int) $row['floors'],
        ]);
    }

    /**
     * Адреса обʼєкта.
     *
     * @param array $row   Рядок публікації
     * @param bool  $short Коротка форма («вул.» замість «вулиця»)
     *
     * @return string|null
     */
    public function address(array $row, bool $short = true)
    {
        $suffix = $this->lang === 'ru' ? 'Ru' : 'Uk';
        $key = $short ? 'addressShort' : 'address';

	    return Arr::str($row['data'], 'location.' . $key . $suffix);
    }

	/**
	 * Посилання на Google Maps для цього обʼєкта.
	 *
	 * @param array $row Рядок публікації
	 *
	 * @return string|null
	 */
	public function mapUrl(array $row)
	{
		return $this->url->map($row['lat'], $row['lon'], $this->lang);
	}

	/**
	 * Повернути координати для цього обʼєкта.
	 *
	 * @param array $row Рядок публікації
	 *
	 * @return array|null
	 */
	public function mapCoordinates(array $row)
	{

		if (empty($row['lat']) || empty($row['lon'])) {
			return null;
		}


		return [$row['lon'],$row['lat']];
	}

    /**
     * Назва населеного пункту.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function city(array $row)
    {
        return $this->localized($row, 'city');
    }

    /**
     * Назва району міста.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function district(array $row)
    {
        return $this->localized($row, 'district');
    }

    /**
     * Назва житлового комплексу.
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function complex(array $row)
    {
        return $this->localized($row, 'complex');
    }

    /**
     * Станції метро поруч у вигляді готових рядків.
     *
     * @param array $row Рядок публікації
     *
     * @return string[] Наприклад ['Святошин, 700 м']
     */
    public function metro(array $row)
    {
        $stations = [];

        foreach ((array) $row['metro'] as $station) {
            $name = Arr::str($station, $this->lang === 'ru' ? 'nameRu' : 'nameUk', 128);

            if ($name === null) {
                $name = Arr::str($station, 'nameUk', 128);
            }

            if ($name === null) {
                continue;
            }

            $distance = Arr::int($station, 'distance', 0);

            $stations[] = $distance === null
                ? $name
                : $this->t->get('card.metro', [
                    'name'     => $name,
                    'distance' => Attribute::number($distance),
                ]);
        }

        return $stations;
    }

    /**
     * Опис обʼєкта, підготовлений до виведення в HTML.
     *
     * Текст екранується тут же і повертається з переносами рядків, тому в
     * шаблоні його виводять як є, без esc().
     *
     * @param array $row Рядок публікації
     *
     * @return string|null
     */
    public function descriptionHtml(array $row)
    {
        $text = Arr::str($row['data'], $this->lang === 'ru' ? 'textRu' : 'textUk');

        if ($text === null) {
            $text = Arr::str($row['data'], $this->lang === 'ru' ? 'textUk' : 'textRu');
        }

        if ($text === null) {
            return null;
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * Фотографії обʼєкта у вигляді готових адрес.
     *
     * @param array  $row  Рядок публікації
     * @param string $size Назва розміру з photos.sizes
     *
     * @return array[] Список [id, url]
     */
    public function photos(array $row, $size = 'medium')
    {
        $photos = [];

        // Список фотографій приходить із фіда, а файли завантажуються окремим
        // кроком синхронізації — і при першому прогоні це триває годинами.
        // Стовпець photosCount заповнюється лише після того, як файли справді
        // лягли на диск, тому поки він нульовий, галерею не показуємо взагалі:
        // порожнє поле з підписом «без фотографій» виглядає краще, ніж рядок
        // порваних картинок.
        if ((int) $row['photosCount'] === 0) {
            return [];
        }

        foreach ((array) $row['photos'] as $photo) {
            $id = Arr::str($photo, 'id', 64);

            if ($id === null) {
                continue;
            }

            $photos[] = [
                'id'  => $id,
                'url' => $this->url->photo($row['objectPublicationId'], $id, $size),
            ];
        }

        return $photos;
    }

    /**
     * Адреса першої фотографії — головної в картці й у списку.
     *
     * @param array  $row  Рядок публікації
     * @param string $size Назва розміру
     *
     * @return string|null null, якщо фотографій немає
     */
    public function photo(array $row, $size = 'medium')
    {
        $photos = $this->photos($row, $size);

        return $photos === [] ? null : $photos[0]['url'];
    }

    /**
     * Основні параметри обʼєкта для таблиці на сторінці публікації.
     *
     * @param array $row Рядок публікації
     *
     * @return array[] Список [key, name, value, postfix]
     */
    public function parameters(array $row)
    {
        $model = $this->models->parameters();
        $rows = Attribute::parameters($model, $row['data'], self::$parameterKeys, $this->lang);

        // Прибираємо порожні позначки. У CRM поля-прапорці показуються
        // завжди, разом зі значенням «ні», — там це потрібно рієлтору, щоб
        // бачити, що поле заповнене. Відвідувачу сайту чотири рядки «ні»
        // підряд («Новобуд: ні», «Торг: ні») не кажуть нічого.
        //
        // Виняток — поля з ознакою reverse у моделі: вони сформульовані
        // навпаки, і «Частина обʼєкта: ні» означає «продається цілком».
        return array_values(array_filter($rows, function (array $one) use ($model, $row) {
            $key = $one['key'];

            if (!isset($model[$key]['type']) || $model[$key]['type'] !== 'boolean') {
                return true;
            }

            if (!empty($model[$key]['reverse'])) {
                return true;
            }

            return !empty($row['data'][$key]);
        }));
    }

    /**
     * Додаткові характеристики, згруповані за розділами.
     *
     * @param array $row Рядок публікації
     *
     * @return array[] Список [key, name, items[]]
     */
    public function characteristics(array $row)
    {
        $characteristics = isset($row['data']['characteristics']) && is_array($row['data']['characteristics'])
            ? $row['data']['characteristics']
            : [];

        if ($characteristics === []) {
            return [];
        }

        return Attribute::characteristics($this->models->characteristics(), $characteristics, $this->lang);
    }

    /**
     * Відео та посилання на огляди.
     *
     * @param array $row Рядок публікації
     *
     * @return array[] Список [url, type]
     */
    public function multimedia(array $row)
    {
        $items = [];

        foreach (Arr::toArray($row['data'], 'multimedia') as $item) {
            $url = Arr::str($item, 'url', 500);

            // Посилання підставляється в href, тому пускаємо лише http(s).
            if ($url === null || !preg_match('~^https?://~i', $url)) {
                continue;
            }

            $items[] = [
                'url'  => $url,
                'type' => Arr::str($item, 'type', 32),
            ];
        }

        return $items;
    }

    /**
     * Кадастрові номери ділянки.
     *
     * @param array $row Рядок публікації
     *
     * @return string[]
     */
    public function cadastre(array $row)
    {
        $numbers = [];

        foreach (Arr::toArray($row['data'], 'cadastre') as $number) {
            if (!is_array($number) && trim((string) $number) !== '') {
                $numbers[] = trim((string) $number);
            }
        }

        return $numbers;
    }

    /**
     * Чи реалізований обʼєкт.
     *
     * @param array $row Рядок публікації
     *
     * @return bool
     */
    public function isSold(array $row)
    {
        return $row['status'] === ObjectRepository::STATUS_SOLD;
    }

    /**
     * Імʼя агента у вигляді «Імʼя Прізвище».
     *
     * @param array|null $agent Дані агента
     *
     * @return string|null
     */
    public function agentName($agent)
    {
        if (!is_array($agent)) {
            return null;
        }

        $parts = [];

        foreach (['name', 'surname'] as $key) {
            if (isset($agent[$key]) && $agent[$key] !== '') {
                $parts[] = $agent[$key];
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Адреса фотографії агента.
     *
     * Розміру тут немає: фотографія співробітника зберігається одним файлом
     * такою, як прийшла з CRM.
     *
     * @param array|null $agent Дані агента
     *
     * @return string|null
     */
    public function agentPhoto($agent)
    {
        if (!is_array($agent) || empty($agent['photo']) || empty($agent['agentId'])) {
            return null;
        }

        return $this->url->staffPhoto($agent['agentId'], $agent['photo']);
    }

    /**
     * Продаючий текст під карточкою агента.
     *
     * Форма дієслова залежить від статі співробітника, записаної в CRM
     * («він розповість» / «вона розповість»). Якщо стать не вказана —
     * беремо текст від агентства в цілому («ми розповімо»), бо вгадувати її
     * за імʼям не можна: ті самі імена бувають і чоловічими, і жіночими.
     *
     * @param array|null $agent Дані агента
     *
     * @return string
     */
    public function agentLead($agent)
    {
        $name = $this->agentName($agent);

        if ($name === null) {
            return $this->t->get('object.agentLead');
        }

        $sex = is_array($agent) && isset($agent['sex']) ? (string) $agent['sex'] : '';

        if ($sex !== 'man' && $sex !== 'woman') {
            return $this->t->get('object.agentLead');
        }

        // У звертанні природніше саме імʼя, без прізвища: «Зателефонуйте
        // Генріху» замість «Зателефонуйте Генріху Хімічу».
        $first = is_array($agent) && !empty($agent['name']) ? (string) $agent['name'] : $name;

        return $this->t->get(
            $sex === 'woman' ? 'object.agentLeadWoman' : 'object.agentLeadMan',
            ['name' => $first]
        );
    }

    /**
     * Посилання агента на соцмережі.
     *
     * @param array|null $agent Дані агента
     *
     * @return array[] Список [platform, link]
     */
    public function agentLinks($agent)
    {
        if (!is_array($agent)) {
            return [];
        }

        $links = [];

        foreach ((array) $agent['links'] as $link) {
            $url = Arr::str($link, 'link', 500);

            // Адреса підставляється в href, тому пускаємо лише http(s).
            if ($url === null || !preg_match('~^https?://~i', $url)) {
                continue;
            }

            $links[] = [
                'platform' => Arr::str($link, 'platform', 32),
                'link'     => $url,
            ];
        }

        return $links;
    }

    /**
     * Телефони агента у вигляді, придатному для показу і для посилання tel:
     *
     * @param array|null $agent Дані агента
     *
     * @return array[] Список [text, tel, telegram, viber]
     */
    public function agentPhones($agent)
    {
        if (!is_array($agent)) {
            return [];
        }

        $phones = [];

        foreach ((array) $agent['phones'] as $phone) {
            $text = Arr::str($phone, 'phoneFull', 32);

            if ($text === null) {
                $text = Arr::str($phone, 'phone', 32);
            }

            if ($text === null) {
                continue;
            }

            // Для посилання tel: залишаємо лише цифри й ведучий плюс.
            $number = Arr::str($phone, 'number', 32);
            $tel = $number === null
                ? preg_replace('~[^0-9]~', '', $text)
                : preg_replace('~[^0-9]~', '', $number);

            $phones[] = [
                'text'     => $text,
                'tel'      => $tel === '' ? null : '+' . $tel,
                'telegram' => Arr::str($phone, 'telegram', 64),
                'viber'    => Arr::str($phone, 'viber', 32),
            ];
        }

        return $phones;
    }

    /**
     * Поточна мова.
     *
     * @return string
     */
    public function lang()
    {
        return $this->lang;
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
     * Символ поточної валюти.
     *
     * @return string
     */
    public function currencySymbol()
    {
        return Translator::currencySymbol($this->currency);
    }

    /**
     * Допустимі значення параметра з моделі — для списків у фільтрі.
     *
     * @param string $key Ключ параметра, напр. 'type'
     *
     * @return array Масив «значення => назва»
     */
    public function options($key)
    {
        return Attribute::options($this->models->parameters(), $key, $this->lang);
    }

    /**
     * Назва одного значення параметра.
     *
     * @param string $key   Ключ параметра
     * @param string $value Значення
     *
     * @return string
     */
    public function optionLabel($key, $value)
    {
        $options = $this->options($key);

        return isset($options[$value]) ? $options[$value] : (string) $value;
    }

    /**
     * Назва поля з моделі параметрів.
     *
     * @param string      $key      Ключ параметра
     * @param string|null $fallback Що показати, якщо поля в моделі немає
     *
     * @return string
     */
    public function paramLabel($key, $fallback = null)
    {
        return Attribute::label($this->models->parameters(), $key, $this->lang, $fallback);
    }

    /**
     * Ціна зі стовпця вибраної валюти.
     *
     * @param array  $row    Рядок публікації
     * @param string $prefix 'price' або 'unit'
     *
     * @return string|null
     */
    private function money(array $row, $prefix)
    {
        $column = $prefix . ucfirst($this->currency);

        if (!isset($row[$column]) || $row[$column] === null || (float) $row[$column] <= 0) {
            return null;
        }

        return self::formatMoney($row[$column], $this->currency);
    }

    /**
     * Число з позначкою валюти.
     *
     * Знак долара ставиться ПЕРЕД числом, гривня та євро — після: так це
     * записують у самих цих країнах, і «$150 000» читається звичніше, ніж
     * «150 000 $».
     *
     * Копійки показуємо лише тоді, коли вони справді є: «149 995» замість
     * «149 995,00». Розділювач розрядів — нерозривний пробіл, інакше ціна
     * розірветься на два рядки посередині числа.
     *
     * @param float|string $value    Сума
     * @param string       $currency 'usd', 'uah' або 'eur'
     *
     * @return string
     */
    public static function formatMoney($value, $currency)
    {
        $nbsp = "\xC2\xA0";
        $number = number_format((float) $value, 2, '.', $nbsp);

        // Нульова дробова частина — просто зайвий шум у ціні.
        if (substr($number, -3) === '.00') {
            $number = substr($number, 0, -3);
        }

        $symbol = Translator::currencySymbol($currency);

        return strtolower((string) $currency) === 'usd'
            ? $symbol . $nbsp . $number
            : $number . $nbsp . $symbol;
    }

    /**
     * Назва локації потрібною мовою зі стовпців cityUk/cityRu тощо.
     *
     * @param array  $row    Рядок публікації
     * @param string $prefix 'city', 'district' або 'complex'
     *
     * @return string|null
     */
    private function localized(array $row, $prefix)
    {
        $primary = $prefix . ($this->lang === 'ru' ? 'Ru' : 'Uk');
        $secondary = $prefix . ($this->lang === 'ru' ? 'Uk' : 'Ru');

        if (isset($row[$primary]) && $row[$primary] !== '' && $row[$primary] !== null) {
            return $row[$primary];
        }

        if (isset($row[$secondary]) && $row[$secondary] !== '' && $row[$secondary] !== null) {
            return $row[$secondary];
        }

        return null;
    }

    /**
     * Кількість кімнат у скороченій формі для назви: «3к».
     *
     * @param int|string $rooms Кількість кімнат
     *
     * @return string
     */
    private function roomsWord($rooms)
    {
        return $this->t->get('name.rooms', ['count' => (int) $rooms]);
    }

    /**
     * Слово для назви обʼєкта за значенням із CRM.
     *
     * Наприклад nameWord('commerceType', 'office') дає «офісного
     * приміщення». Значення, якого немає в перекладах (CRM додала новий тип
     * після виходу пакета), дає null — і назва складається із загального
     * слова замість уточненого.
     *
     * @param string      $group Група слів: landType, parkingType, commerceType
     * @param string|null $value Значення з CRM
     *
     * @return string|null
     */
    private function nameWord($group, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = 'name.' . $group . '.' . $value;

        return $this->t->has($key) ? $this->t->get($key) : null;
    }

    /**
     * Чи заповнене числове поле рядка додатним значенням.
     *
     * У фіді «не заповнено» приходить і як null, і як 0, тому перевіряти
     * доводиться і те, і те.
     *
     * @param array  $row    Рядок публікації
     * @param string $column Назва стовпця
     *
     * @return bool
     */
    private function positive(array $row, $column)
    {
        return isset($row[$column]) && $row[$column] !== null && (float) $row[$column] > 0;
    }

    /**
     * Робить першу літеру великою (з урахуванням UTF-8).
     *
     * @param string $text Текст
     *
     * @return string
     */
    private function upperFirst($text)
    {
        $text = (string) $text;

        if ($text === '') {
            return $text;
        }

        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($text, 1, null, 'UTF-8');
    }
}
