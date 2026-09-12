<?php

namespace Plusest\Site\Repository;

use Plusest\Site\Db;
use Plusest\Site\Search\Filters;
use Plusest\Site\Search\Query;

/**
 * Запити для сторінок сайту: список обʼєктів, одна публікація, списки фільтра.
 *
 * Чому запити тут, а не в ObjectRepository
 * ----------------------------------------
 * ObjectRepository потрібен синхронізації: записати, оновити, порахувати
 * хеші. Тут — усе для відвідувача: фільтри, сортування, пагінація. Це різні
 * задачі, і найчастіше правлять саме цей клас — коли хочуть додати власний
 * фільтр або змінити порядок виведення.
 *
 * Як будується WHERE
 * ------------------
 * Умови складаються з опису фільтрів (клас Filters), а не з рядків у коді.
 * Назви стовпців беруться звідти ж, тому в SQL не потрапляє нічого, що
 * прийшло з адреси, — лише заповнювачі «?» та значення.
 *
 * Про стовпець data
 * -----------------
 * У списку вибираються всі стовпці разом із data — повним JSON обʼєкта. На
 * сторінці їх щонайбільше кілька десятків (site.perPage), тому це недорого,
 * зате шаблон карточки має все: фотографії, адресу, тексти. А от у запитах,
 * які проходять по всій таблиці (списки для фільтра), data не читається
 * ніколи.
 */
class SearchRepository
{
	/**
	 * @var Db
	 */
	private $db;

	/**
	 * @param Db $db Підключення до бази
	 */
	public function __construct(Db $db)
	{
		$this->db = $db;
	}

	/**
	 * Сторінка списку обʼєктів.
	 *
	 * @param Query $query Розібраний запит: фільтри, сортування, сторінка
	 *
	 * @return array Масив [rows, total, pages, page]
	 */
	public function search(Query $query)
	{
		$where = $this->where($query);

		$total = (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM {objects} o' . $where['sql'],
			$where['bindings']
		);

		$pages = $total === 0 ? 1 : (int) ceil($total / $query->perPage());

		// Сторінки за межами вибірки не існує — про це має дізнатись front
		// controller, щоб віддати 404, а не порожній список.
		if ($query->page() > $pages) {
			return ['rows' => [], 'total' => $total, 'pages' => $pages, 'page' => $query->page()];
		}

		// LIMIT і OFFSET підставляємо в текст запиту числами, а не
		// заповнювачами: MySQL із справжніми підготовленими запитами
		// (PDO::ATTR_EMULATE_PREPARES = false) не приймає їх параметрами.
		$rows = $this->db->fetchAll(
			'SELECT o.* FROM {objects} o'
			. $where['sql']
			. $this->orderBy($query)
			. ' LIMIT ' . (int) $query->perPage()
			. ' OFFSET ' . (int) $query->offset(),
			$where['bindings']
		);

		return [
			'rows'  => array_map([$this, 'hydrate'], $rows),
			'total' => $total,
			'pages' => $pages,
			'page'  => $query->page(),
		];
	}

	/**
	 * Одна публікація разом із даними її агента.
	 *
	 * Статус тут не перевіряється: за прямим посиланням показуємо і
	 * реалізований, і прихований обʼєкт — саме для цього режим 'hidden' і
	 * існує. Шаблон покаже позначку статусу.
	 *
	 * @param string $publicationId ID публікації
	 *
	 * @return array|null null, якщо публікації немає в базі
	 */
	public function publication($publicationId)
	{
		$row = $this->db->fetchRow(
			'SELECT o.*,'
			. ' s.surname AS agentSurname, s.name AS agentName, s.patronymic AS agentPatronymic,'
			. ' s.sex AS agentSex, s.photo AS agentPhoto, s.data AS agentData'
			. ' FROM {objects} o'
			. ' LEFT JOIN {staff} s ON s.agentId = o.agentId'
			. ' WHERE o.objectPublicationId = ?',
			[$publicationId]
		);

		if ($row === null) {
			return null;
		}

		$row = $this->hydrate($row);

		// Дані агента складаємо в окремий підмасив: у шаблоні зручніше
		// звертатись до $object['agent']['phones'], ніж розбирати JSON.
		$row['agent'] = $this->agent($row);

		return $row;
	}

	/**
	 * Наскільки схожий обʼєкт може відрізнятись за площею та кількістю кімнат.
	 *
	 * 0.2 означає «плюс-мінус 20% від поточного». Ширше брати немає сенсу:
	 * двокімнатна квартира на 45 м² і п'ятикімнатна на 200 м² — не заміна одна
	 * одній, і відвідувач це одразу побачить.
	 */
	const SIMILAR_SPREAD = 0.2;

	/**
	 * Схожі обʼєкти — для блоку в кінці сторінки публікації.
	 *
	 * «Схожий» — це та сама операція, той самий тип, те саме місто, стільки ж
	 * кімнат і приблизно та сама площа (±20%). Площа береться загальна, а
	 * якщо її немає — площа ділянки: у земельної ділянки загальної площі не
	 * буває, і без цієї підстановки блок для ділянок був би порожній.
	 *
	 * Умови, для яких у поточного обʼєкта немає даних, просто не додаються:
	 * обʼєкт без указаної кількості кімнат не повинен залишитись зовсім без
	 * блоку «схожі».
	 *
	 * @param array $object Публікація, для якої шукаємо схожі
	 * @param int   $limit  Скільки обʼєктів повернути
	 *
	 * @return array[]
	 */
	public function similar(array $object, int $limit = 3)
	{
		$conditions = [
			'o.status = ?',
			'o.operation = ?',
			'o.type = ?',
			// Не сама публікація і не інша публікація того самого обʼєкта:
			// два однакові оголошення поруч виглядають як помилка сайту.
			'o.objectId <> ?',
		];

		$bindings = [
			ObjectRepository::STATUS_ACTIVE,
			$object['operation'],
			$object['type'],
			$object['objectId'],
		];

		if ($object['cityId'] !== null) {
			$conditions[] = 'o.cityId = ?';
			$bindings[] = $object['cityId'];
		}

		if ($object['rooms'] !== null && (int) $object['rooms'] > 0) {
			$conditions[] = 'o.rooms = ?';
			$bindings[] = (int) $object['rooms'];
		}

		// Площа: загальна, а за її відсутності — ділянки. Порівнюємо той
		// самий стовпець, у якому знайшли значення, інакше 250 м² будинку
		// шукалися б серед соток ділянки.
		$areaColumn = null;

		if ($object['areaTotal'] !== null && (float) $object['areaTotal'] > 0) {
			$areaColumn = 'areaTotal';
		} elseif ($object['areaLand'] !== null && (float) $object['areaLand'] > 0) {
			$areaColumn = 'areaLand';
		}

		if ($areaColumn !== null) {
			$area = (float) $object[$areaColumn];
			$column = 'o.' . $this->db->quoteIdentifier($areaColumn);

			$conditions[] = $column . ' BETWEEN ? AND ?';
			$bindings[] = round($area * (1 - self::SIMILAR_SPREAD), 2);
			$bindings[] = round($area * (1 + self::SIMILAR_SPREAD), 2);
		}

		$rows = $this->db->fetchAll(
			'SELECT o.* FROM {objects} o'
			. ' WHERE ' . implode(' AND ', $conditions)
			. ' ORDER BY o.createdAt DESC'
			. ' LIMIT ' . $limit,
			$bindings
		);

		return array_map([$this, 'hydrate'], $rows);
	}

	/**
	 * Публікації за списком ідентифікаторів — для розділу «Обране».
	 *
	 * Обране зберігається в браузері відвідувача (localStorage), тому сервер
	 * знає лише список ID і має віддати за ними дані. Статус не перевіряємо:
	 * обʼєкт, який відвідувач відклав, а потім продали, показуємо з
	 * позначкою «реалізовано» — це чесніше, ніж тихо прибрати його зі списку.
	 *
	 * Порядок результату повторює порядок переданих ID: у списку обраного це
	 * порядок, у якому відвідувач додавав обʼєкти, і мінятись він не мусить.
	 *
	 * @param string[] $publicationIds Ідентифікатори публікацій
	 * @param int      $limit          Скільки обʼєктів віддати щонайбільше
	 *
	 * @return array[]
	 */
	public function byIds(array $publicationIds, int $limit = 100)
	{
		// Ідентифікатори приходять із браузера, тому перевіряємо формат: у
		// CRM це шістнадцяткові рядки, і все інше навіть не питаємо в базі.
		$ids = [];

		foreach ($publicationIds as $one) {
			$one = trim((string) $one);

			if (preg_match('~^[A-Za-z0-9]{1,24}$~', $one) && !in_array($one, $ids, true)) {
				$ids[] = $one;
			}
		}

		if ($ids === []) {
			return [];
		}

		$ids = array_slice($ids, 0, max(1, $limit));

		$rows = $this->db->fetchAll(
			'SELECT o.* FROM {objects} o'
			. ' WHERE o.objectPublicationId IN (' . $this->placeholders($ids) . ')',
			$ids
		);

		// Розкладаємо результат за ідентифікаторами й збираємо назад у
		// порядку, у якому ID прийшли з браузера.
		$indexed = [];

		foreach ($rows as $row) {
			$indexed[$row['objectPublicationId']] = $this->hydrate($row);
		}

		$result = [];

		foreach ($ids as $one) {
			if (isset($indexed[$one])) {
				$result[] = $indexed[$one];
			}
		}

		return $result;
	}

	/**
	 * Значення, які взагалі є у базі — для побудови списків фільтра.
	 *
	 * Показувати у фільтрі те, чого в базі немає, немає сенсу: відвідувач
	 * вибере «автомісце» і отримає порожній список. Тому перелік для кожного
	 * списку будується з самої бази, а якщо значень менше двох — шаблон
	 * такий список просто не показує.
	 *
	 * @param string   $column   Стовпець: operation, type, rooms...
	 * @param string[] $statuses Які статуси враховувати
	 *
	 * @return array Масив «значення => кількість обʼєктів»
	 */
	public function distinct($column, array $statuses)
	{
		if (!$this->isKnownColumn($column)) {
			return [];
		}

		$rows = $this->db->fetchAll(
			'SELECT ' . $this->db->quoteIdentifier($column) . ' AS value, COUNT(*) AS total'
			. ' FROM {objects}'
			. ' WHERE status IN (' . $this->placeholders($statuses) . ')'
			. ' AND ' . $this->db->quoteIdentifier($column) . ' IS NOT NULL'
			. ' GROUP BY value'
			. ' ORDER BY value',
			$statuses
		);

		$values = [];

		foreach ($rows as $row) {
			$values[(string) $row['value']] = (int) $row['total'];
		}

		return $values;
	}

	/**
	 * Населені пункти, у яких є обʼєкти.
	 *
	 * @param string[] $statuses Які статуси враховувати
	 *
	 * @return array[] Список [id, nameUk, nameRu, total]
	 */
	public function cities(array $statuses)
	{
		return $this->locations('cityId', 'city', $statuses, []);
	}

	/**
	 * Адміністративні райони міст, у яких є обʼєкти.
	 *
	 * Якщо відвідувач вибрав місто — показуємо райони лише цього міста:
	 * інакше в списку виявиться «Шевченківський район» від трьох різних міст.
	 *
	 * @param string[] $statuses Які статуси враховувати
	 * @param string[] $cityIds  Обмежити вибраними містами
	 *
	 * @return array[] Список [id, nameUk, nameRu, total]
	 */
	public function districts(array $statuses, array $cityIds = [])
	{
		return $this->locations('districtId', 'district', $statuses, $cityIds);
	}

	/**
	 * Загальний запит списку локацій.
	 *
	 * @param string   $idColumn Стовпець з ідентифікатором
	 * @param string   $prefix   Префікс стовпців із назвами: city або district
	 * @param string[] $statuses Які статуси враховувати
	 * @param string[] $cityIds  Обмежити вибраними містами
	 *
	 * @return array[]
	 */
	private function locations($idColumn, $prefix, array $statuses, array $cityIds)
	{
		$bindings = $statuses;
		$sql = 'SELECT ' . $this->db->quoteIdentifier($idColumn) . ' AS id,'
		       . ' MAX(' . $this->db->quoteIdentifier($prefix . 'Uk') . ') AS nameUk,'
		       . ' MAX(' . $this->db->quoteIdentifier($prefix . 'Ru') . ') AS nameRu,'
		       . ' COUNT(*) AS total'
		       . ' FROM {objects}'
		       . ' WHERE status IN (' . $this->placeholders($statuses) . ')'
		       . ' AND ' . $this->db->quoteIdentifier($idColumn) . ' IS NOT NULL';

		if ($cityIds !== []) {
			$sql .= ' AND cityId IN (' . $this->placeholders($cityIds) . ')';
			$bindings = array_merge($bindings, $cityIds);
		}

		$sql .= ' GROUP BY id ORDER BY nameUk';

		$items = $this->db->fetchAll($sql, $bindings);

		// Переміщуємо обласний центр на перше місце
		if ($idColumn === 'cityId' and !empty($items)) {

			foreach ($items as $index => &$item) {

				$item['originalIndex'] = $index;

			}

			usort($items, function ($a, $b) {

				$stateCenters = [
					'knefpmfs4xvgy6b6ohjhgyykv8oivt3i', // Київ
					'jrpecwwq6mrgp2vdpzeigjbw45paarfe', // Вінниця
					'e6nwyxr0hep2hztm8em0kbxix14piek4', // Дніпро
					'0ve8nazpx9uvrd2tuf1iwmletd84tvnw', // Донецьк
					'xiuydxunvhcuvlyl2s42if82wcicojhf', // Житомир
					'xprhm0gj6srre8ta0qtlvpgcjuxzesja', // Запоріжжя
					'mauu7vnbnjvudy1ukpgrzsspthgtdt0p', // Івано-Франківськ
					'jefndsdiagfi8l8c5sjfvxedxmw8wknp', // Кропивницький
					'bhdmquaglsiux1kzddtblhy87taivqzu', // Луганськ
					'pxscp2ynbmml2wcgqoaawxai0cgwi0zm', // Луцьк
					'jpfnx114ofbjvwcjasgipx9p5p0sq8v7', // Львів
					'y7ct4qchqxdzednvko8nxwih3ksfvuxs', // Миколаїв
					'recuuelhipbq45zxc7qolkr6l9bhsnlh', // Одеса
					'6vqeu4mbyqjelkwxtecl0mqavgnqczda', // Полтава
					'3nkw9b8auv3qi8km386zysj2ema2ltig', // Рівне
					'6s3iebn8gitpczl5bsrtn0fu2ciwc6ex', // Суми
					'zqawxnoclauabjaoxua8ycodjs40svlp', // Тернопіль
					'83vhczwlz3vz4xzquiagzenfbz6gxf4x', // Ужгород
					'jv47htx5do1vewfh4hbfmqbmtnxwnezo', // Харків
					'jfiok3kymnfl3h9m8p5jflvhthntclpo', // Херсон
					'0ye5or2ztzr7ml7wowayuivmyq8j3cvr', // Хмельницький
					'51capgvqbneqkyhsw7qn2oh7xmkdiitx', // Черкаси
					'6kmm2kcz1iw1rnsprtxduofcrxaktkpw', // Чернівці
					'6tlexe0k7oheettlzrdjnnvekcgyszem'  // Чернігів
				];

				$aPos = array_search($a['id'], $stateCenters, true);
				$bPos = array_search($b['id'], $stateCenters, true);

				$aHasPriority = ($aPos !== false);
				$bHasPriority = ($bPos !== false);

				if ($aHasPriority && $bHasPriority) {
					return $aPos <=> $bPos;
				}

				if ($aHasPriority !== $bHasPriority) {
					return $aHasPriority ? -1 : 1;
				}

				return $a['originalIndex'] <=> $b['originalIndex'];
			});

			foreach ($items as &$item) {

				unset($item['originalIndex']);

			}

		}

		return $items;
	}

	/**
	 * Складає умови WHERE із фільтрів запиту.
	 *
	 * @param Query $query Розібраний запит
	 *
	 * @return array Масив [sql, bindings]; sql починається з ' WHERE ...'
	 */
	private function where(Query $query)
	{
		$conditions = [];
		$bindings = [];

		// Статус — завжди перша умова: саме з нього починається індекс
		// idxSearch, тому MySQL відсікає приховані обʼєкти найдешевше.
		$statuses = $query->statuses();
		$conditions[] = 'o.status IN (' . $this->placeholders($statuses) . ')';
		$bindings = array_merge($bindings, $statuses);

		foreach ($query->filters() as $key => $value) {
			$definition = Filters::definition($key);

			if ($definition === null) {
				continue;
			}

			// Метро — не стовпець, а приєднання окремої таблиці.
			if ($key === 'metroId') {
				$metro = $this->metroCondition($value, $query->filter('metroDistance'));

				$conditions[] = $metro['sql'];
				$bindings = array_merge($bindings, $metro['bindings']);

				continue;
			}

			// Відстань до метро вже врахована разом зі станціями.
			if ($key === 'metroDistance' || !isset($definition['column'])) {
				continue;
			}

			$column = 'o.' . $this->db->quoteIdentifier($definition['column']);

			if ($definition['kind'] === 'range') {
				if (isset($value['from']) && $value['from'] !== null) {
					$conditions[] = $column . ' >= ?';
					$bindings[] = $value['from'];
				}

				if (isset($value['to']) && $value['to'] !== null) {
					$conditions[] = $column . ' <= ?';
					$bindings[] = $value['to'];
				}

				continue;
			}

			// Логічні фільтри в адресі записані словами ('whole-part'), а в
			// стовпці лежать числа — перекладаємо.
			$values = Filters::toColumn($key, (array) $value);

			if ($values === []) {
				continue;
			}

			$conditions[] = $column . ' IN (' . $this->placeholders($values) . ')';
			$bindings = array_merge($bindings, $values);
		}

		return [
			'sql'      => ' WHERE ' . implode(' AND ', $conditions),
			'bindings' => $bindings,
		];
	}

	/**
	 * Умова «поруч із вибраними станціями метро».
	 *
	 * Відвідувач вибирає до пʼяти станцій і, за бажанням, максимальну
	 * відстань. Обʼєкт підходить, якщо він у межах цієї відстані ХОЧА Б ВІД
	 * ОДНОЇ з вибраних станцій — тому EXISTS, а не JOIN: приєднання дало б
	 * дублі рядків, якщо обʼєкт стоїть між двома вибраними станціями.
	 *
	 * @param string[] $metroIds Ідентифікатори станцій
	 * @param int|null $distance Максимальна відстань у метрах
	 *
	 * @return array Масив [sql, bindings]
	 */
	private function metroCondition(array $metroIds, $distance)
	{
		$bindings = $metroIds;

		$sql = 'EXISTS (SELECT 1 FROM {objectMetro} m'
		       . ' WHERE m.objectPublicationId = o.objectPublicationId'
		       . ' AND m.metroId IN (' . $this->placeholders($metroIds) . ')';

		if ($distance !== null) {
			$sql .= ' AND m.distance IS NOT NULL AND m.distance <= ?';
			$bindings[] = (int) $distance;
		}

		return ['sql' => $sql . ')', 'bindings' => $bindings];
	}

	/**
	 * Складає ORDER BY.
	 *
	 * Реалізовані обʼєкти завжди йдуть після актуальних — незалежно від
	 * вибраного сортування. Інакше «спочатку дешеві» вивело б на перший
	 * екран те, що вже продано.
	 *
	 * Ціна сортується стовпцем поточної валюти: усі три ціни лежать у базі,
	 * тому перемикання валюти не потребує ні перерахунку, ні курсу.
	 *
	 * @param Query $query Розібраний запит
	 *
	 * @return string
	 */
	private function orderBy(Query $query)
	{
		$rule = $query->sortRule();
		$column = $rule['column'] === 'price'
			? 'price' . ucfirst($query->currency())
			: $rule['column'];

		if (!$this->isKnownColumn($column)) {
			$column = 'createdAt';
		}

		$order = [];

		if ($query->soldLast()) {
			// Порівняння дає 1 для реалізованих і 0 для решти, тому
			// зростаючий порядок ставить їх у кінець.
			$order[] = "(o.status = 'sold') ASC";
		}

		$direction = $rule['direction'] === 'ASC' ? 'ASC' : 'DESC';

		// Обʼєкти без ціни чи площі не мусять опинятись на першому екрані
		// при сортуванні «від меншого»: NULL у MySQL сортується першим.
		if ($direction === 'ASC' && $column !== 'createdAt') {
			$order[] = 'o.' . $this->db->quoteIdentifier($column) . ' IS NULL ASC';
		}

		$order[] = 'o.' . $this->db->quoteIdentifier($column) . ' ' . $direction;

		// Останній ключ сортування — первинний ключ. Без нього MySQL може
		// видати рядки з однаковою ціною у різному порядку на різних
		// сторінках, і один обʼєкт покажеться двічі, а інший зникне.
		$order[] = 'o.objectPublicationId ASC';

		return ' ORDER BY ' . implode(', ', $order);
	}

	/**
	 * Готує рядок із бази до передачі в шаблон.
	 *
	 * Розбирає два поля JSON — повні дані обʼєкта та станції метро — і
	 * дістає з даних список фотографій: саме він потрібен галереї.
	 *
	 * @param array $row Рядок із бази
	 *
	 * @return array
	 */
	private function hydrate(array $row)
	{
		$data = [];

		if (isset($row['data']) && $row['data'] !== '') {
			$decoded = json_decode($row['data'], true);

			if (is_array($decoded)) {
				$data = $decoded;
			}
		}

		$row['data'] = $data;
		$row['metro'] = $this->decodeList(isset($row['metro']) ? $row['metro'] : null);
		$row['photos'] = isset($data['photos']) && is_array($data['photos']) ? $data['photos'] : [];

		return $row;
	}

	/**
	 * Дані агента у вигляді, зручному для шаблону.
	 *
	 * @param array $row Рядок публікації з приєднаними стовпцями агента
	 *
	 * @return array|null null, якщо агента немає в базі
	 */
	private function agent(array $row)
	{
		if (!isset($row['agentSurname']) && !isset($row['agentName'])) {
			return null;
		}

		$data = [];

		if (isset($row['agentData']) && $row['agentData'] !== '') {
			$decoded = json_decode($row['agentData'], true);

			if (is_array($decoded)) {
				$data = $decoded;
			}
		}

		return [
			'agentId'    => $row['agentId'],
			'surname'    => $row['agentSurname'],
			'name'       => $row['agentName'],
			'patronymic' => $row['agentPatronymic'],
			'sex'        => $row['agentSex'],
			'photo'      => $row['agentPhoto'],
			'phones'     => isset($data['phones']) && is_array($data['phones']) ? $data['phones'] : [],
			'links'      => isset($data['links']) && is_array($data['links']) ? $data['links'] : [],
			'data'       => $data,
		];
	}

	/**
	 * Розбирає стовпець із JSON-списком.
	 *
	 * @param string|null $json Вміст стовпця
	 *
	 * @return array
	 */
	private function decodeList($json)
	{
		if ($json === null || $json === '') {
			return [];
		}

		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Рядок заповнювачів «?, ?, ?» потрібної довжини.
	 *
	 * @param array $values Значення
	 *
	 * @return string
	 */
	private function placeholders(array $values)
	{
		return implode(', ', array_fill(0, max(1, count($values)), '?'));
	}

	/**
	 * Чи існує такий стовпець у таблиці обʼєктів.
	 *
	 * Перелік явний, а не з information_schema: стовпець потрапляє в текст
	 * SQL, тому список допустимих назв мусить бути в коді, а не приходити
	 * звідкись іззовні.
	 *
	 * @param string $column Назва стовпця
	 *
	 * @return bool
	 */
	private function isKnownColumn($column)
	{
		return in_array($column, [
			'operation', 'type', 'whole', 'commerceType', 'landType', 'parkingType',
			'rooms', 'roomsOverall', 'areaOverall', 'areaTotal', 'areaLiving', 'areaKitchen',
			'areaLand', 'floor', 'floors', 'newBuilding', 'commissioning',
			'priceUsd', 'priceUah', 'priceEur', 'unitUsd', 'unitUah', 'unitEur',
			'cityId', 'districtId', 'complexId', 'metroDistanceMin',
			'photosCount', 'multimediaCount', 'status', 'createdAt', 'updatedAt', 'seenAt',
		], true);
	}
}
