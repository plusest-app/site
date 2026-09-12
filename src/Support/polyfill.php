<?php

/**
 * Поліфіли для PHP 7.3.
 *
 * Заготовка має працювати як на PHP 7.3 (у багатьох користувачів саме така
 * версія), так і на PHP 8.x. Тому в коді ядра ми користуємось функціями
 * str_contains() / str_starts_with() / str_ends_with(), які зʼявились лише
 * у PHP 8.0, а тут дописуємо їх для старих версій.
 *
 * Файл підключається автоматично через секцію "files" у composer.json —
 * вручну його require робити не потрібно.
 */

if (!function_exists('str_contains')) {
    /**
     * Чи міститься підрядок $needle усередині рядка $haystack.
     *
     * @param string $haystack Рядок, у якому шукаємо
     * @param string $needle   Що шукаємо (порожній рядок завжди знайдено)
     *
     * @return bool
     */
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * Чи починається рядок $haystack з підрядка $needle.
     *
     * @param string $haystack Рядок, у якому шукаємо
     * @param string $needle   Очікуваний початок рядка
     *
     * @return bool
     */
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Чи закінчується рядок $haystack підрядком $needle.
     *
     * @param string $haystack Рядок, у якому шукаємо
     * @param string $needle   Очікуване закінчення рядка
     *
     * @return bool
     */
    function str_ends_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }

        $length = strlen($needle);

        return $length <= strlen($haystack)
            && substr_compare($haystack, $needle, -$length) === 0;
    }
}
