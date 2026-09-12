<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Arr;

/**
 * Перетворює співробітника з фіда в рядок таблиці staff.
 *
 * Співробітники приходять окремим масивом у тому самому фіді. Звʼязок з
 * обʼєктами — через agentId.
 *
 * Структура елемента у фіді:
 *
 *     {
 *       "agentId": "000000000000000000000002",
 *       "surname": "Хіміч", "name": "Генріх", "patronymic": "Романович",
 *       "sex": "man",
 *       "photo": "1745110821-4nBIQ",
 *       "phones": [{"phone": "(098) 403-56-43", "phoneFull": "...", "telegram": "...", ...}],
 *       "links":  [{"platform": "facebook", "link": "https://..."}]
 *     }
 *
 * Телефони й посилання на соцмережі зберігаються в стовпці data цілим JSON:
 * фільтрувати за ними не потрібно, а полів там багато і вони можуть
 * поповнюватись.
 */
class StaffMapper
{
    /**
     * Формує рядок для таблиці staff.
     *
     * @param array  $person Співробітник із фіда
     * @param string $now    Поточний час у формі 'Y-m-d H:i:s'
     *
     * @return array Масив «стовпець => значення»
     *
     * @throws SiteException Якщо немає agentId
     */
    public static function toRow(array $person, $now)
    {
        $agentId = Arr::str($person, 'agentId', 24);

        if ($agentId === null) {
            throw new SiteException('У співробітника немає agentId.');
        }

        return [
            'agentId'    => $agentId,
            'surname'    => Arr::str($person, 'surname', 96),
            'name'       => Arr::str($person, 'name', 96),
            'patronymic' => Arr::str($person, 'patronymic', 96),
            'sex'        => Arr::str($person, 'sex', 8),
            'photo'      => Arr::str($person, 'photo', 64),
            'data'       => self::encode($person),
            'hashData'   => self::dataHash($person),
            'seenAt'     => $now,
            'createdAt'  => $now,
            'updatedAt'  => $now,
        ];
    }

    /**
     * Хеш даних співробітника.
     *
     * Поле photo із хешування НЕ виключаємо: у співробітника лише одна
     * фотографія, і якщо вона змінилась, рядок усе одно треба перезаписати.
     *
     * @param array $person Співробітник із фіда
     *
     * @return string 32 символи md5
     */
    public static function dataHash(array $person)
    {
        return md5(self::encode(Arr::ksortRecursive($person)));
    }

    /**
     * Кодує дані співробітника в JSON.
     *
     * @param mixed $value Значення
     *
     * @return string
     *
     * @throws SiteException Якщо значення не кодується
     */
    private static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new SiteException('Не вдалося закодувати дані співробітника в JSON: ' . json_last_error_msg());
        }

        return $json;
    }
}
