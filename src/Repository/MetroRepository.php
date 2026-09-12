<?php

namespace Plusest\Site\Repository;

use Plusest\Site\Db;
use Plusest\Site\Support\Arr;

/**
 * Робота з таблицею objectMetro — станціями метро публікацій.
 *
 * Навіщо ця таблиця
 * -----------------
 * Станції вже лежать у стовпці objects.metro цілим JSON — цього достатньо,
 * щоб показати «поруч: Святошин, 700 м» на сторінці обʼєкта. Але фільтр
 * «поруч зі станціями A, B, C у межах 800 метрів» по JSON робиться лише
 * через LIKE, тобто повним перебором таблиці. Тому та сама інформація
 * дублюється тут рядок-на-станцію, і фільтр стає звичайним JOIN по індексу.
 *
 * Хто заповнює таблицю
 * --------------------
 * Синхронізація: щоразу, коли перезаписується рядок публікації, її станції
 * переписуються повністю (replace). Додатково кожен прогін викликає
 * deleteOrphans() і backfill() — вони приводять таблицю до ладу, якщо вона
 * розʼїхалась із objects: наприклад коли заготовку оновили на версію з цією
 * таблицею, а публікації в базі вже були.
 */
class MetroRepository
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
     * Переписує станції однієї публікації.
     *
     * Спочатку видаляємо все, що було, потім вставляємо те, що прийшло у
     * фіді: станцію могли не додати, а забрати, і оновленням «на місці»
     * такого не зловити.
     *
     * @param string $publicationId ID публікації
     * @param array  $stations      Список станцій із фіда (location.metroStations)
     * @param string|null $cityId   ID населеного пункту публікації
     *
     * @return int Скільки станцій записано
     */
    public function replace($publicationId, array $stations, $cityId = null)
    {
        $this->db->execute(
            'DELETE FROM {objectMetro} WHERE objectPublicationId = ?',
            [$publicationId]
        );

        if ($stations === []) {
            return 0;
        }

        $written = 0;
        $seen = [];

        foreach ($stations as $station) {
            $metroId = Arr::str($station, 'id', 32);

            if ($metroId === null || isset($seen[$metroId])) {
                continue;
            }

            $seen[$metroId] = true;

            $this->db->insert('objectMetro', [
                'objectPublicationId' => $publicationId,
                'metroId'             => $metroId,
                'nameUk'              => Arr::str($station, 'nameUk', 128),
                'nameRu'              => Arr::str($station, 'nameRu', 128),
                'distance'            => Arr::int($station, 'distance', 0, 4294967295),
                'cityId'              => $cityId,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Прибирає рядки, яким уже нічого не відповідає в таблиці публікацій.
     *
     * Спрацьовує у двох випадках: публікацію видалили (sync.missingMode =
     * delete або звільнили агента) і публікацію оновили так, що метро в неї
     * більше немає.
     *
     * @return int Скільки рядків видалено
     */
    public function deleteOrphans()
    {
        return $this->db->execute(
            'DELETE m FROM {objectMetro} m'
            . ' LEFT JOIN {objects} o ON o.objectPublicationId = m.objectPublicationId'
            . ' WHERE o.objectPublicationId IS NULL OR o.metro IS NULL'
        );
    }

    /**
     * Заповнює станції публікацій, яких у цій таблиці ще немає.
     *
     * Потрібно рівно один раз — після оновлення заготовки на версію з
     * таблицею objectMetro: публікації в базі вже лежать, їхні хеші
     * збігаються з фідом, тому синхронізація рядки не перезаписує і
     * replace() для них ніколи б не викликався.
     *
     * Джерело даних — стовпець objects.metro, тобто повторний запит до CRM
     * не потрібен.
     *
     * @return int Скільки публікацій заповнено
     */
    public function backfill()
    {
        $rows = $this->db->fetchAll(
            'SELECT objectPublicationId, cityId, metro FROM {objects}'
            . ' WHERE metro IS NOT NULL'
            . ' AND objectPublicationId NOT IN (SELECT objectPublicationId FROM {objectMetro})'
        );

        $filled = 0;

        foreach ($rows as $row) {
            $stations = json_decode((string) $row['metro'], true);

            if (!is_array($stations) || $stations === []) {
                continue;
            }

            $this->replace($row['objectPublicationId'], $stations, $row['cityId']);
            $filled++;
        }

        return $filled;
    }

    /**
     * Станції, які є хоча б в одній публікації — для списку у фільтрі.
     *
     * Метро в Україні є лише в Києві, Харкові та Дніпрі, тому окремого
     * переліку «міста з метро» не потрібно: якщо запит нічого не повернув,
     * фільтр просто не показуємо.
     *
     * @param string[] $cityIds  Обмежити вибраними населеними пунктами
     * @param string[] $statuses Які статуси публікацій враховувати
     *
     * @return array[] Список [metroId, nameUk, nameRu, total]
     */
    public function stations(array $cityIds = [], array $statuses = ['active'])
    {
        $bindings = [];
        $where = [];

        if ($statuses !== []) {
            $where[] = 'o.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $bindings = array_merge($bindings, $statuses);
        }

        if ($cityIds !== []) {
            $where[] = 'm.cityId IN (' . implode(', ', array_fill(0, count($cityIds), '?')) . ')';
            $bindings = array_merge($bindings, $cityIds);
        }

        return $this->db->fetchAll(
            'SELECT m.metroId, m.nameUk, m.nameRu, COUNT(*) AS total'
            . ' FROM {objectMetro} m'
            . ' JOIN {objects} o ON o.objectPublicationId = m.objectPublicationId'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' GROUP BY m.metroId, m.nameUk, m.nameRu'
            . ' ORDER BY m.nameUk',
            $bindings
        );
    }

    /**
     * Загальна кількість рядків — для сторінки стану й перевірок.
     *
     * @return int
     */
    public function count()
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM {objectMetro}');
    }
}
