<?php

namespace Plusest\Site\Repository;

use Plusest\Site\Db;

/**
 * Робота з таблицею objects.
 *
 * Тут лише операції, потрібні синхронізації. Запити для сторінок сайту
 * (пошук, фільтри, сортування) додаються на етапі фронтенду — вони добре
 * читаються окремо і їх часто правлять під власний дизайн.
 */
class ObjectRepository
{
    /**
     * Статус: обʼєкт актуальний, показуємо в пошуку.
     */
    const STATUS_ACTIVE = 'active';

    /**
     * Статус: обʼєкт реалізований. Залишається на сайті з позначкою.
     */
    const STATUS_SOLD = 'sold';

    /**
     * Статус: обʼєкт прихований з пошуку, доступний лише за прямим посиланням.
     */
    const STATUS_HIDDEN = 'hidden';

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
     * Службові дані всіх публікацій, які вже є в базі.
     *
     * Один запит замість запиту на кожну публікацію з фіда: для агентства з
     * кількома тисячами обʼєктів різниця в часі прогону — десятки разів.
     *
     * @return array Масив «objectPublicationId => [objectId, hashData, status]»
     */
    public function syncIndex()
    {
        $rows = $this->db->fetchAll(
            'SELECT objectPublicationId, objectId, hashData, status FROM {objects}'
        );

        $index = [];

        foreach ($rows as $row) {
            $index[$row['objectPublicationId']] = [
                'objectId' => $row['objectId'],
                'hashData' => $row['hashData'],
                'status'   => $row['status'],
            ];
        }

        return $index;
    }

    /**
     * Записує публікацію: вставляє нову або оновлює наявну.
     *
     * Стовпець createdAt при оновленні не перезаписується — це дата першої
     * появи обʼєкта в локальній базі, і вона має залишатись незмінною.
     *
     * Стовпці hashPhotosDisk і photosCount теж не торкаються: за них
     * відповідає синхронізація фотографій, і затирати їх даними з фіда не
     * можна — інакше ми вважали б завантаженим те, чого на диску немає.
     *
     * @param array $row Рядок, підготовлений ObjectMapper
     *
     * @return bool true — публікація нова, false — оновлено наявну
     */
    public function save(array $row)
    {
        // MySQL повертає 1 для вставки, 2 для оновлення і 0, якщо оновлювати
        // нічого не довелося.
        $affected = $this->db->upsert('objects', $row, ['createdAt']);

        return $affected === 1;
    }

    /**
     * Позначає публікацію як побачену у фіді, не змінюючи її даних.
     *
     * Викликається, коли хеш даних збігся: перезаписувати рядок немає сенсу.
     *
     * Якщо публікація раніше була позначена реалізованою або прихованою, а
     * тепер знову зʼявилась у фіді — повертаємо їй статус active. Без цього
     * обʼєкт, який ненадовго зник із фіда, залишився б реалізованим назавжди.
     *
     * @param string $publicationId ID публікації
     * @param string $now           Поточний час
     * @param bool   $restoreStatus Чи потрібно повертати статус active
     *
     * @return void
     */
    public function touch($publicationId, $now, $restoreStatus = false)
    {
        if ($restoreStatus) {
            $this->db->execute(
                'UPDATE {objects} SET seenAt = ?, status = ?, updatedAt = ?'
                . ' WHERE objectPublicationId = ?',
                [$now, self::STATUS_ACTIVE, $now, $publicationId]
            );

            return;
        }

        // Нічого, крім seenAt, не змінюємо — updatedAt має показувати час
        // останньої справжньої зміни даних, а не час останнього прогону.
        $this->db->execute(
            'UPDATE {objects} SET seenAt = ? WHERE objectPublicationId = ?',
            [$now, $publicationId]
        );
    }

    /**
     * Змінює статус переліку публікацій.
     *
     * @param string[] $publicationIds Ідентифікатори
     * @param string   $status         Новий статус
     * @param string   $now            Поточний час
     *
     * @return int Скільки рядків змінено
     */
    public function setStatus(array $publicationIds, $status, $now)
    {
        if ($publicationIds === []) {
            return 0;
        }

        $changed = 0;

        // Розбиваємо на порції: запит з десятками тисяч заповнювачів
        // упирається в ліміт max_allowed_packet.
        foreach (array_chunk($publicationIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $changed += $this->db->execute(
                'UPDATE {objects} SET status = ?, updatedAt = ?'
                . ' WHERE objectPublicationId IN (' . $placeholders . ') AND status <> ?',
                array_merge([$status, $now], $chunk, [$status])
            );
        }

        return $changed;
    }

    /**
     * Видаляє публікації.
     *
     * Фотографії при цьому не чіпаються: каталоги, яким уже нічого не
     * відповідає в базі, прибирає синхронізація фотографій — вона порівнює
     * вміст каталогу з фотографіями зі списком публікацій у базі.
     *
     * @param string[] $publicationIds Ідентифікатори
     *
     * @return int Скільки рядків видалено
     */
    public function delete(array $publicationIds)
    {
        if ($publicationIds === []) {
            return 0;
        }

        $deleted = 0;

        foreach (array_chunk($publicationIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $deleted += $this->db->execute(
                'DELETE FROM {objects} WHERE objectPublicationId IN (' . $placeholders . ')',
                $chunk
            );
        }

        return $deleted;
    }

    /**
     * Кількість публікацій у розрізі статусів.
     *
     * @return array Масив «статус => кількість»
     */
    public function countByStatus()
    {
        $rows = $this->db->fetchAll('SELECT status, COUNT(*) AS total FROM {objects} GROUP BY status');

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Загальна кількість публікацій у базі.
     *
     * @return int
     */
    public function count()
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM {objects}');
    }

    /**
     * Видаляє публікації, автора яких більше немає в таблиці співробітників.
     *
     * Співробітник зник із фіда — значить його звільнили. Показувати на сайті
     * обʼєкт, у контактах якого стоїть звільнений агент, не можна: відвідувач
     * зателефонує в нікуди. Тому такі публікації видаляються безповоротно,
     * незалежно від налаштування sync.missingMode.
     *
     * Викликати ЛИШЕ після оновлення таблиці співробітників: метод вважає, що
     * в ній уже точний склад агентства з фіда.
     *
     * Публікації без агента (agentId = NULL) не чіпаємо: контактів у них і
     * так немає, а видаляти те, чого нам не доручали, не варто.
     *
     * @return int Скільки рядків видалено
     */
    public function deleteWithoutStaff()
    {
        return $this->db->execute(
            'DELETE FROM {objects}'
            . ' WHERE agentId IS NOT NULL'
            . ' AND agentId NOT IN (SELECT agentId FROM {staff})'
        );
    }

    /**
     * Публікації, у яких фотографії на диску не відповідають фіду.
     *
     * Порівняння двох стовпців у самій базі: hashPhotos — список фотографій із
     * фіда, hashPhotosDisk — те, що реально завантажено. Стовпець data тут
     * навмисно не читається: на кількох тисячах публікацій він важив би
     * десятки мегабайтів, а потрібен лише для тих, де справді є робота.
     *
     * Спочатку йдуть свіжозмінені публікації: якщо sync.photoLimit обірве
     * прогін, першими завантажаться фотографії нових обʼєктів.
     *
     * @return array[] Список рядків «objectPublicationId, hashPhotos»
     */
    public function photoCandidates()
    {
        return $this->db->fetchAll(
            'SELECT objectPublicationId, hashPhotos FROM {objects}'
            . ' WHERE hashPhotos IS NOT NULL'
            . ' AND (hashPhotosDisk IS NULL OR hashPhotosDisk <> hashPhotos)'
            . ' ORDER BY updatedAt DESC'
        );
    }

    /**
     * JSON із даними однієї публікації — джерело списку фотографій.
     *
     * @param string $publicationId ID публікації
     *
     * @return string|null null, якщо рядок уже видалили
     */
    public function photoData($publicationId)
    {
        return $this->db->fetchValue(
            'SELECT data FROM {objects} WHERE objectPublicationId = ?',
            [$publicationId]
        );
    }

    /**
     * Позначає, що фотографії публікації завантажені на диск.
     *
     * Викликається лише після успішного запису файлів — саме тому стовпець
     * hashPhotosDisk не заповнює ніхто інший.
     *
     * Стовпець updatedAt не змінюємо: він показує час останньої зміни даних
     * обʼєкта, а не час роботи з файлами.
     *
     * @param string $publicationId ID публікації
     * @param string $hashPhotos    Хеш списку фотографій, який щойно оброблено
     * @param int    $count         Скільки фотографій лежить на диску
     *
     * @return void
     */
    public function markPhotosDone($publicationId, $hashPhotos, $count)
    {
        $this->db->execute(
            'UPDATE {objects} SET hashPhotosDisk = ?, photosCount = ?'
            . ' WHERE objectPublicationId = ?',
            [$hashPhotos, (int) $count, $publicationId]
        );
    }

    /**
     * Ідентифікатори всіх публікацій у базі.
     *
     * Потрібні синхронізації фотографій, щоб знайти каталоги, яким уже
     * нічого не відповідає, і прибрати їх.
     *
     * @return string[]
     */
    public function allPublicationIds()
    {
        return $this->db->fetchColumn('SELECT objectPublicationId FROM {objects}');
    }
}
