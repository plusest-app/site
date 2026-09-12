<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Config;

/**
 * Вирішує, чи час знову запитувати фід у CRM.
 *
 * Навіщо це потрібно
 * ------------------
 * CRM обмежує частоту звернень, щоб її сервер не перевантажували. Тому
 * завдання cron ставиться часто — раз на 15 хвилин, — а вирішує, звертатись
 * до CRM цього разу чи ні, саме цей клас. Прогони, які на розклад не
 * потрапили, не марні: вони продовжують завантажувати фотографії зі
 * хмарного сховища, а на нього обмежень немає.
 *
 * Розклад
 * -------
 * Уночі та у вихідні обʼєкти майже не публікують — усі відпочивають, — тому
 * інтервал там більший:
 *
 *     пн-пт у денні години   — sync.intervalDay   (за замовчуванням 2 години)
 *     ніч і вихідні          — sync.intervalNight (за замовчуванням 6 годин)
 *
 * Межі «денних годин» задаються налаштуваннями sync.dayFrom і sync.dayTo.
 *
 * Окреме правило для відмов
 * -------------------------
 * Якщо CRM відповіла відмовою — невірне посилання, не оплачений доступ,
 * забагато запитів, — наступне звернення відкладається на ERROR_INTERVAL
 * (8 годин). Це правило свідомо не виноситься в конфіг: зменшувати цю
 * паузу немає жодного сенсу, а от нашкодити нею — легко. Якщо доступ до CRM
 * не оплачено, запити щогодини не допоможуть ні нам, ні серверу.
 */
class Schedule
{
    /**
     * Пауза після відмови CRM, секунди. 8 годин.
     */
    const ERROR_INTERVAL = 28800;

    /**
     * Причина рішення: ще жодного разу не запитували.
     */
    const REASON_FIRST = 'first';

    /**
     * Причина рішення: спрацював звичайний розклад.
     */
    const REASON_SCHEDULE = 'schedule';

    /**
     * Причина рішення: триває пауза після відмови CRM.
     */
    const REASON_ERROR = 'error';

    /**
     * Причина рішення: розклад вимкнений (інтервал 0).
     */
    const REASON_DISABLED = 'disabled';

    /**
     * Інтервал у робочі години будніх днів, секунди.
     *
     * @var int
     */
    private $intervalDay;

    /**
     * Інтервал уночі та у вихідні, секунди.
     *
     * @var int
     */
    private $intervalNight;

    /**
     * Година, з якої починаються «денні» години (0-23).
     *
     * @var int
     */
    private $dayFrom;

    /**
     * Година, на якій «денні» години закінчуються (1-24).
     *
     * @var int
     */
    private $dayTo;

    /**
     * @param array $options Ключі intervalDay, intervalNight, dayFrom, dayTo
     */
    public function __construct(array $options = [])
    {
        $this->intervalDay = isset($options['intervalDay']) ? max(0, (int) $options['intervalDay']) : 7200;
        $this->intervalNight = isset($options['intervalNight']) ? max(0, (int) $options['intervalNight']) : 21600;
        $this->dayFrom = isset($options['dayFrom']) ? min(23, max(0, (int) $options['dayFrom'])) : 8;
        $this->dayTo = isset($options['dayTo']) ? min(24, max(1, (int) $options['dayTo'])) : 21;
    }

    /**
     * Створює розклад із налаштувань.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self((array) $config->get('sync', []));
    }

    /**
     * Чи час запитувати фід.
     *
     * @param int|null $lastFeedAt Час останнього звернення до CRM, unix-час
     * @param int|null $retryAfter Час, до якого звертатись заборонено, unix-час
     * @param int|null $now        Поточний час; null — зараз
     *
     * @return array{due: bool, wait: int, reason: string, interval: int}
     *                due      — чи можна звертатись;
     *                wait     — скільки секунд ще чекати, якщо не можна;
     *                reason   — котре з правил спрацювало;
     *                interval — інтервал розкладу, що діє зараз
     */
    public function due($lastFeedAt, $retryAfter, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $interval = $this->interval($now);

        // Пауза після відмови старша за розклад: сервер прямо сказав «ні».
        if ($retryAfter !== null && $retryAfter > $now) {
            return [
                'due'      => false,
                'wait'     => $retryAfter - $now,
                'reason'   => self::REASON_ERROR,
                'interval' => $interval,
            ];
        }

        if ($lastFeedAt === null) {
            return ['due' => true, 'wait' => 0, 'reason' => self::REASON_FIRST, 'interval' => $interval];
        }

        if ($interval <= 0) {
            return ['due' => true, 'wait' => 0, 'reason' => self::REASON_DISABLED, 'interval' => 0];
        }

        $elapsed = $now - $lastFeedAt;

        // Відʼємне значення означає, що час на сервері перевели назад —
        // тоді краще запитати фід, ніж чекати невідомо скільки.
        if ($elapsed < 0 || $elapsed >= $interval) {
            return ['due' => true, 'wait' => 0, 'reason' => self::REASON_SCHEDULE, 'interval' => $interval];
        }

        return [
            'due'      => false,
            'wait'     => $interval - $elapsed,
            'reason'   => self::REASON_SCHEDULE,
            'interval' => $interval,
        ];
    }

    /**
     * Інтервал, який діє в указану мить.
     *
     * @param int|null $now Unix-час; null — зараз
     *
     * @return int Секунди
     */
    public function interval($now = null)
    {
        return $this->isWorkingTime($now) ? $this->intervalDay : $this->intervalNight;
    }

    /**
     * Чи припадає ця мить на робочі години будня.
     *
     * @param int|null $now Unix-час; null — зараз
     *
     * @return bool
     */
    public function isWorkingTime($now = null)
    {
        $now = $now === null ? time() : (int) $now;

        // 'N' повертає день тижня числом, де 1 — понеділок, 7 — неділя.
        if ((int) date('N', $now) > 5) {
            return false;
        }

        $hour = (int) date('G', $now);

        return $hour >= $this->dayFrom && $hour < $this->dayTo;
    }

    /**
     * Тривалість у зручному для читання вигляді.
     *
     * Потрібно для повідомлень у логу: «через 1 год 12 хв» читається краще,
     * ніж «через 4320 с».
     *
     * @param int $seconds Секунди
     *
     * @return string
     */
    public static function formatDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 60) {
            return $seconds . ' с';
        }

        $minutes = (int) floor($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' хв';
        }

        $hours = (int) floor($minutes / 60);
        $minutes -= $hours * 60;

        return $minutes === 0 ? $hours . ' год' : $hours . ' год ' . $minutes . ' хв';
    }
}
