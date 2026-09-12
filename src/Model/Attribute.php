<?php

namespace Plusest\Site\Model;

/**
 * Перетворює машинні значення обʼєкта у підписи для відвідувача.
 *
 * Задача
 * ------
 * У фіді обʼєкт описаний ключами, а не текстом:
 *
 *     "heating": "autonomous-gas"
 *     "areaTotal": 250
 *     "whole": true
 *     "characteristics": {"territory": {"fence": ["stone", "picket"]}}
 *
 * Модель даних (див. ModelStore) знає для кожного ключа його назву двома
 * мовами, тип значення і — для типу `values` — назви всіх допустимих значень.
 * Цей клас поєднує одне з іншим і повертає готову пару «назва — значення»:
 *
 *     ['name' => 'Опалення', 'value' => 'автономне газове', 'postfix' => null]
 *     ['name' => 'Площа загальна', 'value' => '250', 'postfix' => 'м<sup>2</sup>']
 *
 * Чому postfix віддається окремо
 * ------------------------------
 * У моделі одиниці виміру записані з HTML — `м<sup>2</sup>`. Значення ж
 * приходить із CRM і в шаблоні мусить бути екранованим. Тому це два різних
 * поля: `value` шаблон виводить через esc(), а `postfix` — як є.
 *
 * Клас складається лише зі статичних методів: він нічого не зберігає, а
 * модель отримує аргументом.
 */
class Attribute
{
    /**
     * Переклад логічних значень.
     *
     * Тримаємо тут, а не у файлі перекладів інтерфейсу: це частина
     * розшифровки моделі даних, а не текст сторінки.
     */
    const YES = ['uk' => 'так', 'ru' => 'да'];
    const NO = ['uk' => 'ні', 'ru' => 'нет'];

    /**
     * Пара «назва — значення» для одного поля.
     *
     * Це прямий порт функції getAttributeValue() з шаблонів CRM, тому
     * поведінка сайту й кабінету збігається.
     *
     * @param array  $items Розділ моделі: масив «ключ => опис поля»
     * @param string $name  Ключ поля, напр. 'heating'
     * @param mixed  $value Значення з фіда
     * @param string $lang  Мова: 'uk' або 'ru'
     *
     * @return array|null Масив [name, value, postfix] або null, якщо показувати нічого
     */
    public static function value(array $items, $name, $value, $lang = 'uk')
    {
        if ($name === '' || $name === null || $value === null) {
            return null;
        }

        // Поля, якого в моделі немає (CRM додала його раніше, ніж ми оновили
        // модель), не ховаємо: показуємо машинний ключ і значення як є. Краще
        // некрасивий підпис, ніж загублена інформація.
        if (!isset($items[$name]) || !is_array($items[$name])) {
            return [
                'name'    => (string) $name,
                'value'   => self::plain($value),
                'postfix' => null,
            ];
        }

        $item = $items[$name];
        $type = isset($item['type']) ? $item['type'] : 'string';

        $result = [
            'name'    => self::translate($item, $lang, (string) $name),
            'value'   => null,
            'postfix' => isset($item['postfix']) ? (string) $item['postfix'] : null,
        ];

        if ($type === 'string') {
            $result['value'] = self::plain($value);
        } elseif ($type === 'number') {
            // Нуль для числових полів означає «не заповнено», а не «нуль».
            if (is_numeric($value) && $value > 0) {
                $result['value'] = self::number($value);
            }
        } elseif ($type === 'boolean') {
            $result['value'] = self::boolean($item, $value, $lang);
        } elseif ($type === 'values') {
            $result['value'] = self::fromValues($item, $value, $lang);
        }

        if ($result['value'] === null || $result['value'] === '') {
            return null;
        }

        return $result;
    }

    /**
     * Назва поля з моделі — без значення.
     *
     * Потрібна фільтру: підписи «Операція», «Тип нерухомості», «Кімнат»
     * беруться з тієї самої моделі, тому їх не треба перекладати вручну.
     *
     * @param array  $items    Розділ моделі
     * @param string $name     Ключ поля
     * @param string $lang     Мова
     * @param string $fallback Що повернути, якщо поля в моделі немає
     *
     * @return string
     */
    public static function label(array $items, $name, $lang = 'uk', $fallback = null)
    {
        if (isset($items[$name]) && is_array($items[$name])) {
            return self::translate($items[$name], $lang, $fallback === null ? (string) $name : $fallback);
        }

        return $fallback === null ? (string) $name : $fallback;
    }

    /**
     * Допустимі значення поля у вигляді «ключ => назва».
     *
     * Так фільтр отримує список операцій і типів нерухомості: перелік і його
     * переклад лежать у моделі, а не в коді сайту.
     *
     * @param array  $items Розділ моделі
     * @param string $name  Ключ поля
     * @param string $lang  Мова
     *
     * @return array Порожній масив, якщо поле не типу values
     */
    public static function options(array $items, $name, $lang = 'uk')
    {
        if (!isset($items[$name]['values']) || !is_array($items[$name]['values'])) {
            return [];
        }

        $options = [];

        foreach ($items[$name]['values'] as $key => $translations) {
            $options[(string) $key] = self::translate($translations, $lang, (string) $key);
        }

        return $options;
    }

    /**
     * Готовий список параметрів обʼєкта для сторінки публікації.
     *
     * @param array    $model Модель параметрів (parameters.json)
     * @param array    $data  Повні дані обʼєкта з фіда
     * @param string[] $keys  Які поля показувати і в якому порядку
     * @param string   $lang  Мова
     *
     * @return array[] Список [key, name, value, postfix]; порожні поля пропущені
     */
    public static function parameters(array $model, array $data, array $keys, $lang = 'uk')
    {
        $rows = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $row = self::value($model, $key, $data[$key], $lang);

            if ($row !== null) {
                $rows[] = ['key' => $key] + $row;
            }
        }

        return $rows;
    }

    /**
     * Готові розділи характеристик для сторінки публікації.
     *
     * Порядок розділів і полів усередині них береться з моделі, а не з фіда:
     * у моделі він осмислений («Комунікації», «Приміщення», «Оздоблення»...),
     * а у фіді — випадковий.
     *
     * @param array  $model           Модель характеристик (consolidated.json)
     * @param array  $characteristics Розділ characteristics обʼєкта з фіда
     * @param string $lang            Мова
     *
     * @return array[] Список [key, name, items[]]; порожні розділи пропущені
     */
    public static function characteristics(array $model, array $characteristics, $lang = 'uk')
    {
        $sections = [];

        foreach ($model as $sectionKey => $section) {
            if (!isset($characteristics[$sectionKey]) || !is_array($characteristics[$sectionKey])) {
                continue;
            }

            $items = isset($section['items']) && is_array($section['items']) ? $section['items'] : [];
            $rows = [];

            // Йдемо по моделі, а не по даних обʼєкта — так порядок полів
            // усередині розділу теж визначає модель.
            foreach ($items as $key => $description) {
                if (!array_key_exists($key, $characteristics[$sectionKey])) {
                    continue;
                }

                $row = self::value($items, $key, $characteristics[$sectionKey][$key], $lang);

                if ($row !== null) {
                    $rows[] = ['key' => $key] + $row;
                }
            }

            // Поля, яких у моделі ще немає: CRM могла додати їх після того, як
            // ми останній раз завантажували модель.
            foreach ($characteristics[$sectionKey] as $key => $value) {
                if (isset($items[$key])) {
                    continue;
                }

                $row = self::value($items, $key, $value, $lang);

                if ($row !== null) {
                    $rows[] = ['key' => $key] + $row;
                }
            }

            if ($rows === []) {
                continue;
            }

            $sections[] = [
                'key'   => (string) $sectionKey,
                'name'  => self::translate($section, $lang, (string) $sectionKey),
                'items' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * Число з розділювачем розрядів.
     *
     * Розділювач — нерозривний пробіл: інакше «1 250 000» перенесеться на
     * два рядки посередині ціни.
     *
     * @param mixed $value     Число
     * @param int   $precision Скільки знаків після коми залишити
     *
     * @return string
     */
    public static function number($value, $precision = 0)
    {
        if (!is_numeric($value)) {
            return '';
        }

        $value = round((float) $value, $precision);

        // Дробову частину показуємо лише якщо вона справді є: «40.5 м²» —
        // потрібно, «40.00 м²» — зайвий шум.
        $decimals = $precision;

        if ($precision > 0 && $value === floor($value)) {
            $decimals = 0;
        }

        return number_format($value, $decimals, ',', "\xC2\xA0");
    }

    /**
     * Переклад значення логічного поля.
     *
     * Ключ `reverse` у моделі означає, що поле сформульоване «навпаки»:
     * наприклад `whole` називається «Частина обʼєкта», тому whole = true
     * має показуватись як «ні».
     *
     * @param array  $item  Опис поля з моделі
     * @param mixed  $value Значення з фіда
     * @param string $lang  Мова
     *
     * @return string|null
     */
    private static function boolean(array $item, $value, $lang)
    {
        if (!is_bool($value)) {
            // CRM віддає справжні true/false, але про запас обробляємо і
            // рядкові «1»/«0», які могли зʼявитись у власних доробках.
            if ($value === '' || $value === null) {
                return null;
            }

            $value = !in_array(strtolower((string) $value), ['0', 'false', 'no'], true);
        }

        $reverse = isset($item['reverse']) && $item['reverse'];

        if ($value) {
            return $reverse ? self::word(self::NO, $lang) : self::word(self::YES, $lang);
        }

        return $reverse ? self::word(self::YES, $lang) : self::word(self::NO, $lang);
    }

    /**
     * Переклад значення поля типу values.
     *
     * Значення може бути як одиничним, так і масивом — у фіді те саме поле
     * характеристик приходить то рядком, то списком.
     *
     * @param array  $item  Опис поля з моделі
     * @param mixed  $value Значення з фіда
     * @param string $lang  Мова
     *
     * @return string|null
     */
    private static function fromValues(array $item, $value, $lang)
    {
        $known = isset($item['values']) && is_array($item['values']) ? $item['values'] : [];

        if (is_array($value)) {
            $names = [];

            foreach ($value as $one) {
                if (is_array($one)) {
                    continue;
                }

                $names[] = isset($known[$one])
                    ? self::translate($known[$one], $lang, (string) $one)
                    : self::plain($one);
            }

            return $names === [] ? null : implode(', ', $names);
        }

        if (isset($known[$value])) {
            return self::translate($known[$value], $lang, (string) $value);
        }

        // Значення, якого в моделі немає, показуємо як є — див. коментар
        // до відсутніх полів у value().
        return self::plain($value);
    }

    /**
     * Текст потрібною мовою з масиву перекладів вигляду ['uk' => ..., 'ru' => ...].
     *
     * @param mixed  $translations Масив перекладів
     * @param string $lang         Мова
     * @param string $fallback     Що повернути, якщо перекладу немає
     *
     * @return string
     */
    private static function translate($translations, $lang, $fallback = '')
    {
        if (!is_array($translations)) {
            return $fallback;
        }

        if (isset($translations[$lang]) && $translations[$lang] !== '') {
            return (string) $translations[$lang];
        }

        // Другої мови може не бути в моделі — тоді краще показати ту, що є.
        foreach (['uk', 'ru'] as $other) {
            if (isset($translations[$other]) && $translations[$other] !== '') {
                return (string) $translations[$other];
            }
        }

        return $fallback;
    }

    /**
     * Слово з пари перекладів.
     *
     * @param array  $pair Масив ['uk' => ..., 'ru' => ...]
     * @param string $lang Мова
     *
     * @return string
     */
    private static function word(array $pair, $lang)
    {
        return isset($pair[$lang]) ? $pair[$lang] : $pair['uk'];
    }

    /**
     * Значення у вигляді простого рядка.
     *
     * Масиви склеюються комою: у фіді трапляються списки без опису в моделі
     * (наприклад кадастрові номери).
     *
     * @param mixed $value Значення
     *
     * @return string
     */
    private static function plain($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $one) {
                if (!is_array($one)) {
                    $parts[] = (string) $one;
                }
            }

            return implode(', ', $parts);
        }

        return trim((string) $value);
    }
}
