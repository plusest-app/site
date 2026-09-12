<?php

namespace Plusest\Site\Repository;

use Plusest\Site\Db;

/**
 * Робота з таблицею staff.
 */
class StaffRepository
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
     * Службові дані всіх співробітників у базі — одним запитом.
     *
     * @return array Масив «agentId => [hashData, photo]»
     */
    public function syncIndex()
    {
        $rows = $this->db->fetchAll('SELECT agentId, hashData, photo FROM {staff}');

        $index = [];

        foreach ($rows as $row) {
            $index[$row['agentId']] = [
                'hashData' => $row['hashData'],
                'photo'    => $row['photo'],
            ];
        }

        return $index;
    }

    /**
     * Записує співробітника: вставляє нового або оновлює наявного.
     *
     * @param array $row Рядок, підготовлений StaffMapper
     *
     * @return bool true — співробітник новий, false — оновлено наявного
     */
    public function save(array $row)
    {
        return $this->db->upsert('staff', $row, ['createdAt']) === 1;
    }

    /**
     * Позначає співробітника як побаченого у фіді, не змінюючи даних.
     *
     * @param string $agentId ID співробітника
     * @param string $now     Поточний час
     *
     * @return void
     */
    public function touch($agentId, $now)
    {
        $this->db->execute('UPDATE {staff} SET seenAt = ? WHERE agentId = ?', [$now, $agentId]);
    }

    /**
     * Видаляє співробітників.
     *
     * @param string[] $agentIds Ідентифікатори
     *
     * @return int Скільки рядків видалено
     */
    public function delete(array $agentIds)
    {
        if ($agentIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($agentIds), '?'));

        return $this->db->execute(
            'DELETE FROM {staff} WHERE agentId IN (' . $placeholders . ')',
            $agentIds
        );
    }

    /**
     * Загальна кількість співробітників у базі.
     *
     * @return int
     */
    public function count()
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM {staff}');
    }

    /**
     * Співробітники, у яких у CRM є фотографія.
     *
     * @return array[] Список рядків «agentId, photo»
     */
    public function withPhotos()
    {
        return $this->db->fetchAll(
            "SELECT agentId, photo FROM {staff} WHERE photo IS NOT NULL AND photo <> ''"
        );
    }

    /**
     * Ідентифікатори всіх співробітників у базі.
     *
     * Потрібні синхронізації фотографій, щоб прибрати каталоги тих, кого вже
     * немає в агентстві.
     *
     * @return string[]
     */
    public function allAgentIds()
    {
        return $this->db->fetchColumn('SELECT agentId FROM {staff}');
    }
}
