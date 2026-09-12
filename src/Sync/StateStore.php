<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Db;

/**
 * Службові значення синхронізації — таблиця syncState.
 *
 * Проста пара «ключ — значення». Тут зберігається час останнього успішного
 * прогону, його підсумки та версії завантажених моделей даних. Великому вмісту
 * тут не місце: для логів є файл data/sync.log.
 */
class StateStore
{
    /**
     * Час завершення останнього успішного прогону.
     */
    const LAST_SYNC_AT = 'lastSyncAt';

    /**
     * Час першого успішного звернення до фіда — коли база тільки наповнилась.
     *
     * Потрібен сторінкам сайту, а не синхронізації. Позначку «NEW» ставимо
     * обʼєктам, які зʼявились у базі менш ніж тиждень тому, — але при першому
     * прогоні в базу разом падає вся тисяча публікацій агентства, і без цього
     * значення весь список тиждень стояв би в синіх позначках.
     *
     * Записується один раз і більше не змінюється.
     */
    const FIRST_SYNC_AT = 'firstSyncAt';

    /**
     * Час останнього звернення до фіда CRM.
     *
     * CRM обмежує частоту запитів, тому синхронізація звертається до неї за
     * розкладом (див. Schedule). Фотографії при цьому качаються кожним
     * прогоном — вони лежать у хмарному сховищі, а не в CRM.
     */
    const LAST_FEED_AT = 'lastFeedAt';

    /**
     * Час, до якого звертатись до CRM заборонено.
     *
     * Заповнюється після відмови сервера: невірне посилання, не оплачений
     * доступ, забагато запитів. Знімається першим же успішним запитом.
     */
    const FEED_RETRY_AFTER = 'feedRetryAfter';

    /**
     * Підсумки останнього прогону в JSON.
     */
    const LAST_SYNC_RESULT = 'lastSyncResult';

    /**
     * Текст останньої помилки синхронізації.
     */
    const LAST_ERROR = 'lastError';

    /**
     * Час, коли сталася остання помилка.
     */
    const LAST_ERROR_AT = 'lastErrorAt';

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
     * Читає значення.
     *
     * @param string $name    Назва
     * @param mixed  $default Що повернути, якщо значення немає
     *
     * @return string|mixed
     */
    public function get($name, $default = null)
    {
        $value = $this->db->fetchValue('SELECT value FROM {syncState} WHERE name = ?', [$name]);

        return $value === null ? $default : $value;
    }

    /**
     * Записує значення.
     *
     * @param string $name  Назва
     * @param mixed  $value Значення. Масиви автоматично кодуються в JSON
     *
     * @return void
     */
    public function set($name, $value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        $this->db->upsert('syncState', [
            'name'      => $name,
            'value'     => $value,
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Читає значення, збережене як JSON.
     *
     * @param string $name Назва
     *
     * @return array Порожній масив, якщо значення немає або воно не JSON
     */
    public function getArray($name)
    {
        $value = $this->get($name);

        if ($value === null) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Видаляє значення.
     *
     * @param string $name Назва
     *
     * @return void
     */
    public function forget($name)
    {
        $this->db->execute('DELETE FROM {syncState} WHERE name = ?', [$name]);
    }

    /**
     * Усі збережені значення — для сторінки стану чи налагодження.
     *
     * @return array Масив «назва => [value, updatedAt]»
     */
    public function all()
    {
        $rows = $this->db->fetchAll('SELECT name, value, updatedAt FROM {syncState} ORDER BY name');

        $state = [];

        foreach ($rows as $row) {
            $state[$row['name']] = [
                'value'     => $row['value'],
                'updatedAt' => $row['updatedAt'],
            ];
        }

        return $state;
    }
}
