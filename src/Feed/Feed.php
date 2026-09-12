<?php

namespace Plusest\Site\Feed;

use Plusest\Site\Exception\FeedException;
use Plusest\Site\Exception\FeedRefusedException;
use Plusest\Site\Support\Arr;

/**
 * Розібраний фід з CRM.
 *
 * Структура відповіді CRM:
 *
 *     {
 *       "status": "success",
 *       "data": {
 *         "total": 9,
 *         "items": [ ... публікації обʼєктів ... ],
 *         "staff": [ ... співробітники агентства ... ],
 *         "cloud": "https://plusest.blob.core.windows.net/",
 *         "watermark": true
 *       }
 *     }
 *
 * Клас перевіряє цю структуру один раз при створенні, щоб далі по коду
 * синхронізації не думати про те, чи існують потрібні ключі.
 */
class Feed
{
    /**
     * Вміст ключа data з відповіді.
     *
     * @var array
     */
    private $data;

    /**
     * @param array $data Вміст ключа data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Розбирає JSON-відповідь CRM.
     *
     * @param string $json Тіло відповіді
     *
     * @return self
     *
     * @throws FeedException Якщо це не JSON, або CRM повідомила про помилку,
     *                       або структура не така, як ми очікуємо
     */
    public static function fromJson($json)
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new FeedException(
                'CRM повернула не JSON. Початок відповіді: '
                . self::preview($json)
            );
        }

        $status = isset($decoded['status']) ? $decoded['status'] : null;

        // CRM повідомила про помилку — дістаємо з відповіді її текст.
        //
        //     { "status": "error", "message": "Access denied",
        //       "data": { "reason": "Invalid ID" } }
        //
        // У message приходять різні причини: невірне посилання, не оплачений
        // доступ, забагато запитів. Іноді в data.reason є уточнення. Поле debug
        // буває дуже великим, тому його не читаємо взагалі.
        if ($status !== 'success') {
            $message = Arr::str($decoded, 'message');
            $reason = Arr::str($decoded, 'data.reason');

			if ($reason === 'Banned forever') {

				file_put_contents(__DIR__ . '/../../disallowed.lock', 'Banned forever');

			}

            throw new FeedRefusedException(
                'CRM відмовила у видачі даних: ' . ($message === null ? 'без пояснення' : $message)
                . ($reason === null ? '' : ' (' . $reason . ')') . "\n"
                . 'Найчастіші причини — невірне чи вимкнене посилання публікації, '
                . 'не оплачений доступ до CRM або забагато запитів. Перевірте кабінет '
                . 'CRM і налаштування feed.url.'
            );
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new FeedException('У відповіді CRM немає розділу "data".');
        }

        $data = $decoded['data'];

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new FeedException('У відповіді CRM немає списку обʼєктів "data.items".');
        }

        return new self($data);
    }

    /**
     * Публікації обʼєктів.
     *
     * @return array[]
     */
    public function items()
    {
        $items = [];

        foreach ($this->data['items'] as $item) {
            // Пропускаємо сміття: елемент без ідентифікатора публікації
            // усе одно нікуди не запишеться.
            if (is_array($item) && Arr::str($item, 'objectPublicationId') !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Співробітники агентства.
     *
     * @return array[]
     */
    public function staff()
    {
        $staff = [];

        foreach (Arr::toArray($this->data, 'staff') as $person) {
            if (is_array($person) && Arr::str($person, 'agentId') !== null) {
                $staff[] = $person;
            }
        }

        return $staff;
    }

    /**
     * Базова адреса хмарного сховища CRM, з якого качаються фотографії.
     *
     * @return string Із завершальним слешем, напр. 'https://host/'
     */
    public function cloud()
    {
        $cloud = Arr::str($this->data, 'cloud');

        return $cloud === null ? '' : rtrim($cloud, '/') . '/';
    }

    /**
     * Чи мають фотографії віддаватися з водяним знаком.
     *
     * Впливає на URL фотографії: варіант '-publish' уже містить нанесений
     * знак, варіант '-2500x2500' — оригінал без нього.
     *
     * @return bool
     */
    public function watermark()
    {
        return Arr::bool($this->data, 'watermark', false);
    }

    /**
     * Скільки публікацій, за словами CRM, має бути у фіді.
     *
     * Порівнюємо з фактичною кількістю: розбіжність означає, що частина
     * елементів не пройшла перевірку в items().
     *
     * @return int
     */
    public function total()
    {
        $total = Arr::int($this->data, 'total', 0);

        return $total === null ? count($this->items()) : $total;
    }

    /**
     * Ідентифікатори всіх публікацій у фіді.
     *
     * Потрібні синхронізації, щоб визначити, які публікації зникли.
     *
     * @return string[]
     */
    public function publicationIds()
    {
        $ids = [];

        foreach ($this->items() as $item) {
            $ids[] = Arr::str($item, 'objectPublicationId');
        }

        return $ids;
    }

    /**
     * Початок рядка для повідомлення про помилку.
     *
     * @param string $text Текст
     *
     * @return string
     */
    private static function preview($text)
    {
        $text = trim(preg_replace('~\s+~', ' ', (string) $text));

        if ($text === '') {
            return '(порожньо)';
        }

        return mb_strlen($text, 'UTF-8') > 200
            ? mb_substr($text, 0, 200, 'UTF-8') . '...'
            : $text;
    }
}
