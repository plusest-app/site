<?php

namespace Plusest\Site\Exception;

/**
 * CRM відповіла на запит і відмовила у видачі даних.
 *
 * Саме відмова, а не збій звʼязку. Так виглядає відповідь:
 *
 *     {
 *       "status": "error",
 *       "message": "Access denied",
 *       "data": { "reason": "Invalid ID" }
 *     }
 *
 * Причини бувають різні: невірне посилання на JSON-feed, вимкнена інтеграція,
 * не оплачений доступ до CRM, забагато запитів. Спільне в них те, що повторити
 * запит через п'ять хвилин не допоможе — потрібна дія людини.
 *
 * Тому цей тип виділений окремо: отримавши його, синхронізація відкладає
 * наступне звернення до CRM на кілька годин. Немає сенсу довбати сервер
 * щочверть години, якщо доступ не оплачено.
 *
 * Окремо стоїть причина 'Banned forever': агентство заблоковане остаточно, і
 * пауза в кілька годин тут не допоможе — повторювати запит не треба ніколи.
 * Такі відмови позначає isPermanent(), а синхронізація, побачивши її, вимикає
 * себе назовсім (див. клас Sync\Ban).
 */
class FeedRefusedException extends FeedException
{
    /**
     * Значення data.reason, яке означає остаточне блокування агентства.
     */
    const BANNED_FOREVER = 'Banned forever';

    /**
     * Машинна причина відмови з поля data.reason відповіді CRM.
     *
     * Саме машинна: у getMessage() лежить пояснення для людини, і покладатись
     * на його текст у коді не можна.
     *
     * @var string|null
     */
    private $reason;

    /**
     * @param string      $message Пояснення для людини
     * @param string|null $reason  Значення data.reason, якщо CRM його передала
     */
    public function __construct($message = '', $reason = null)
    {
        parent::__construct($message);

        $reason = $reason === null ? '' : trim((string) $reason);

        $this->reason = $reason === '' ? null : $reason;
    }

    /**
     * Машинна причина відмови.
     *
     * @return string|null
     */
    public function reason()
    {
        return $this->reason;
    }

    /**
     * Чи закрито доступ назавжди.
     *
     * @return bool
     */
    public function isPermanent()
    {
        return self::isPermanentReason($this->reason);
    }

    /**
     * Чи означає причина остаточне блокування.
     *
     * Статичний варіант потрібен там, де виключення ще не створене, —
     * наприклад у розборі відповіді CRM, де від цього залежить текст
     * повідомлення.
     *
     * @param string|null $reason Значення data.reason
     *
     * @return bool
     */
    public static function isPermanentReason($reason)
    {
        return $reason !== null && strcasecmp(trim((string) $reason), self::BANNED_FOREVER) === 0;
    }
}
