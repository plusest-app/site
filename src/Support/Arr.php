<?php

namespace Plusest\Site\Support;

/**
 * Помічники для читання значень із масивів, що прийшли з CRM.
 *
 * Навіщо окремий клас
 * -------------------
 * Фід — це JSON, у якому майже кожне поле може бути null, може бути відсутнім
 * зовсім, а деякі приходять то рядком, то масивом (наприклад
 * characteristics.communications.water). Якщо читати такі дані напряму,
 * код синхронізації перетворюється на суцільні isset() та перевірки типів.
 *
 * Тому все читання винесене сюди: методи ніколи не кидають попереджень і
 * завжди повертають або значення потрібного типу, або null.
 */
class Arr
{
    /**
     * Значення за «точковим» ключем.
     *
     * Arr::get($item, 'location.complex.nameUk') пройде вглиб масиву і не
     * впаде, якщо якогось рівня немає.
     *
     * @param mixed  $array   Масив (інший тип поверне $default)
     * @param string $key     Ключ, рівні розділені точкою
     * @param mixed  $default Що повернути, якщо значення немає
     *
     * @return mixed
     */
    public static function get($array, $key, $default = null)
    {
        if (!is_array($array)) {
            return $default;
        }

        // Найчастіший випадок — ключ без точок. Обробляємо його окремо,
        // щоб не робити explode() на кожне звернення.
        if (strpos($key, '.') === false) {
            return array_key_exists($key, $array) && $array[$key] !== null
                ? $array[$key]
                : $default;
        }

        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value === null ? $default : $value;
    }

    /**
     * Ціле число або null.
     *
     * Порожній рядок, false і null дають null — а не 0, як зробило б звичайне
     * приведення типу. Для бази це важливо: «поверху немає» і «нульовий
     * поверх» — різні речі.
     *
     * @param mixed  $array Масив
     * @param string $key   Точковий ключ
     * @param int    $min   Мінімально допустиме значення
     * @param int    $max   Максимально допустиме значення
     *
     * @return int|null
     */
    public static function int($array, $key, $min = null, $max = null)
    {
        $value = self::get($array, $key);

        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        // Обрізаємо за межами діапазону стовпця: краще записати граничне
        // значення, ніж отримати помилку MySQL і зламати весь прогін.
        if ($min !== null && $value < $min) {
            return $min;
        }

        if ($max !== null && $value > $max) {
            return $max;
        }

        return $value;
    }

    /**
     * Дробове число або null.
     *
     * @param mixed  $array     Масив
     * @param string $key       Точковий ключ
     * @param int    $precision Скільки знаків після коми залишити
     * @param float  $max       Максимально допустиме значення за модулем
     *
     * @return float|null
     */
    public static function float($array, $key, $precision = 2, $max = null)
    {
        $value = self::get($array, $key);

        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $value = round((float) $value, $precision);

        if ($max !== null && abs($value) > $max) {
            return $value < 0 ? -$max : $max;
        }

        return $value;
    }

    /**
     * Логічне значення.
     *
     * @param mixed  $array   Масив
     * @param string $key     Точковий ключ
     * @param bool   $default Значення, якщо поля немає
     *
     * @return bool
     */
    public static function bool($array, $key, $default = false)
    {
        $value = self::get($array, $key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        // CRM віддає справжні true/false, але на випадок рядкових "0"/"false"
        // обробляємо і їх.
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no'], true);
        }

        return (bool) $value;
    }

    /**
     * Рядок або null, обрізаний до довжини стовпця в базі.
     *
     * @param mixed    $array     Масив
     * @param string   $key       Точковий ключ
     * @param int|null $maxLength Максимальна довжина в символах
     *
     * @return string|null
     */
    public static function str($array, $key, $maxLength = null)
    {
        $value = self::get($array, $key);

        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Ріжемо по символах, а не байтах: інакше український текст обірветься
        // посередині літери й у базу піде некоректний UTF-8.
        if ($maxLength !== null && mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return $value;
    }

    /**
     * Гарантовано масив.
     *
     * Головний випадок, для якого метод існує: у фіді одне й те саме поле
     * характеристик приходить то рядком, то масивом рядків. Далі по коду
     * зручно завжди мати масив.
     *
     * @param mixed  $array Масив
     * @param string $key   Точковий ключ
     *
     * @return array
     */
    public static function toArray($array, $key)
    {
        $value = self::get($array, $key);

        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Перший елемент списку, у якого вказане поле дорівнює потрібному значенню.
     *
     * Використовується для розбору location.components: щоб дістати
     * населений пункт, шукаємо елемент із class = 'city'.
     *
     * @param mixed  $list  Список масивів
     * @param string $field Назва поля
     * @param mixed  $value Очікуване значення поля
     *
     * @return array|null
     */
    public static function firstWhere($list, $field, $value)
    {
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $element) {
            if (is_array($element) && isset($element[$field]) && $element[$field] === $value) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Рекурсивно сортує масив за ключами.
     *
     * Потрібно для стабільного хешування: якщо CRM віддасть ті самі дані, але
     * з полями в іншому порядку, хеш не має змінитись — інакше ми даремно
     * перезапишемо рядок і перекачаємо фотографії.
     *
     * @param array $array Масив, який сортуємо
     *
     * @return array Новий масив із упорядкованими ключами
     */
    public static function ksortRecursive(array $array)
    {
        // Списки (0,1,2...) не сортуємо: у масиві фотографій чи характеристик
        // порядок елементів змістовний, і його ламати не можна.
        if (!self::isAssoc($array)) {
            foreach ($array as $index => $value) {
                if (is_array($value)) {
                    $array[$index] = self::ksortRecursive($value);
                }
            }

            return $array;
        }

        ksort($array, SORT_STRING);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::ksortRecursive($value);
            }
        }

        return $array;
    }

    /**
     * Чи є масив асоціативним (тобто не списком 0..n).
     *
     * @param array $array Масив
     *
     * @return bool
     */
    public static function isAssoc(array $array)
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
