<?php

/**
 * ============================================================================
 *  Front controller розділу нерухомості
 * ============================================================================
 *
 *  Єдиний файл, який віддає вебсервер: усі адреси розділу переписуються на
 *  нього (див. docs/nginx.md). Сам він майже нічого не робить — знаходить
 *  автозавантажувач Composer, читає config.php і передає роботу класу
 *  Plusest\Site\Web\Application.
 *
 *  Правити цей файл зазвичай не потрібно. Верстка живе в каталозі templates,
 *  налаштування — у config.php.
 *
 *  Якщо ж потрібно щось додати саме тут — наприклад, свій обробник для
 *  адреси /base/contacts/ — робіть це до виклику run().
 * ============================================================================
 */

// --- Пошук автозавантажувача Composer ---------------------------------------
//  Перший шлях — заготовка з ZIP-архіву (vendor лежить у корені каталогу
//  plusestSite). Наступні — встановлення через Composer, коли plusestSite/
//  є підкаталогом проєкту, а vendor/ лежить вище.
$plusestAutoload = null;

foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
] as $plusestCandidate) {
    if (is_file($plusestCandidate)) {
        $plusestAutoload = $plusestCandidate;

        break;
    }
}

if ($plusestAutoload === null) {
    http_response_code(500);
    exit('Не знайдено автозавантажувач Composer. Перевірте, чи є каталог vendor.');
}

require $plusestAutoload;

// --- Налаштування ------------------------------------------------------------
$plusestConfigFile = __DIR__ . '/../config.php';

if (!is_file($plusestConfigFile)) {
    http_response_code(503);

    exit(
        '<!doctype html><html lang="uk"><meta charset="utf-8">'
        . '<title>Не налаштовано</title>'
        . '<p>Розділ нерухомості ще не налаштований: немає файлу config.php.</p>'
        . '<p><a href="install/">Відкрити установник</a></p>'
    );
}

try {
    $plusestConfig = Plusest\Site\Config::load($plusestConfigFile);

    // Без доступу до бази сторінку показати неможливо, а найчастіша причина
    // цього — установник не доведений до кінця. Тому перевіряємо це окремо
    // й показуємо зрозуміле повідомлення замість помилки PDO.
    if ((string) $plusestConfig->get('db.name', '') === '') {
        http_response_code(503);

        exit(
            '<!doctype html><html lang="uk"><meta charset="utf-8">'
            . '<title>Не налаштовано</title>'
            . '<p>У config.php не заповнені дані доступу до бази даних.</p>'
            . '<p><a href="install/">Відкрити установник</a></p>'
        );
    }

    $plusestApp = new Plusest\Site\Web\Application($plusestConfig);
    $plusestApp->run();
} catch (Exception $plusestError) {
    // Application перехоплює свої помилки сам і показує сторінку 500. Сюди
    // ми потрапляємо лише коли зламався сам конфіг — тоді шаблонів у нас
    // може й не бути.
    http_response_code(500);

    error_log('PlusestSite: ' . $plusestError->getMessage());

    $plusestDebug = isset($plusestConfig) && $plusestConfig->get('debug.enabled', false);

    exit(
        '<!doctype html><html lang="uk"><meta charset="utf-8">'
        . '<title>Помилка</title><p>'
        . htmlspecialchars(
            $plusestDebug ? $plusestError->getMessage() : 'Сторінку не вдалося показати.',
            ENT_QUOTES
        )
        . '</p>'
    );
}
