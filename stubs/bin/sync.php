#!/usr/bin/env php
<?php

/**
 * ============================================================================
 *  Синхронізація бази нерухомості з CRM Plusest
 * ============================================================================
 *
 *  Цей скрипт запускає cron. Він забирає фід із CRM і оновлює локальну базу:
 *  додає нові обʼєкти, оновлює змінені, а ті, що зникли з фіда, обробляє за
 *  правилом із налаштування sync.missingMode.
 *
 *  Запуск руками (видно хід роботи):
 *
 *      php bin/sync.php
 *
 *  Запуск з cron (готові рядки — у кінці config.php):
 *
 *      17,47 * * * * /usr/bin/php /шлях/до/plusestSite/bin/sync.php >> /dev/null 2>&1
 *
 *  Ключі запуску:
 *
 *      --quiet     не виводити нічого, крім помилок. Для cron
 *      --verbose   показувати повний текст помилок зі стеком викликів
 *      --force     запитати фід негайно, не чекаючи розкладу
 *      --config=   шлях до config.php, якщо він лежить не поруч
 *
 *  Про розклад: CRM обмежує частоту звернень, тому скрипт вирішує сам, чи
 *  запитувати фід цього разу. У будні дні — раз на дві години, уночі та у
 *  вихідні — раз на шість (налаштування секції 'sync' у config.php), а після
 *  відмови CRM — не раніше ніж через 8 годин. Прогони, що на розклад не
 *  потрапили, не марні: вони продовжують завантажувати фотографії. Ключ
 *  --force потрібен, коли ви щойно виправили налаштування й не хочете чекати.
 *
 *  Код виходу: 0 — успіх, 1 — помилка. Це важливо для cron: за кодом виходу
 *  панель хостингу розуміє, чи потрібно надсилати вам лист про збій.
 *
 *  Цей файл можна правити: наприклад, дописати надсилання листа при помилці
 *  або виклик власного коду після успішного прогону.
 * ============================================================================
 */

// Через браузер скрипт не запускають: для цього є public/cron.php із токеном.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Цей скрипт запускається лише з командного рядка.');
}

if (is_file(__DIR__ . '/../../disallowed.lock')) {

    http_response_code(403);
    exit('Заборонено виконання.');

}

// --- Пошук автозавантажувача Composer ---------------------------------------
//  Перший шлях — заготовка з ZIP-архіву (vendor лежить поруч).
//  Другий і третій — встановлення через Composer, коли plusestSite/ є
//  підкаталогом проєкту, а vendor/ лежить у його корені.
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloadFound = false;

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        $autoloadFound = true;

        break;
    }
}

if (!$autoloadFound) {
    fwrite(STDERR, 'Не знайдено автозавантажувач Composer. Перевірте, чи є каталог vendor.' . PHP_EOL);

    exit(1);
}

// --- Розбір ключів запуску ---------------------------------------------------
$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--') !== 0) {
        continue;
    }

    $argument = substr($argument, 2);

    if (strpos($argument, '=') !== false) {
        list($name, $value) = explode('=', $argument, 2);
        $options[$name] = $value;
    } else {
        $options[$argument] = true;
    }
}

$quiet = isset($options['quiet']);
$verbose = isset($options['verbose']);
$force = isset($options['force']);
$configFile = isset($options['config']) ? $options['config'] : __DIR__ . '/../config.php';

// --- Прогін ------------------------------------------------------------------
try {
    $config = Plusest\Site\Config::load($configFile);

    // Синхронізація може тривати довго, а обмеження часу для cron не потрібне.
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $synchronizer = new Plusest\Site\Sync\Synchronizer($config);

    // Виводимо хід роботи в термінал. У режимі --quiet показуємо лише
    // попередження та помилки — щоб cron не надсилав листа на кожен прогін.
    $synchronizer->logger()->setOutput(function ($message, $level) use ($quiet) {
        if ($quiet && $level === 'info') {
            return;
        }

        $stream = $level === 'error' ? STDERR : STDOUT;

        fwrite($stream, ($level === 'info' ? '' : strtoupper($level) . ': ') . $message . PHP_EOL);
    });

    $result = $synchronizer->run($force);

    // Якщо на окремих обʼєктах були помилки, прогін у цілому вдався, але
    // повертаємо код 1: тоді cron надішле вам листа, і проблему буде видно.
    // Тиха втрата даних гірша за зайвий лист.
    if ($result->hasErrors()) {
        fwrite(STDERR, 'Помилок на окремих обʼєктах: ' . count($result->errors()) . PHP_EOL);

        foreach ($result->errors() as $error) {
            fwrite(STDERR, '  - ' . $error . PHP_EOL);
        }

        exit(1);
    }

    exit(0);
} catch (Plusest\Site\Exception\SiteException $e) {
    // Наші власні помилки вже сформульовані для людини — стек не потрібен.
    fwrite(STDERR, 'ПОМИЛКА: ' . $e->getMessage() . PHP_EOL);

    exit(1);
} catch (Exception $e) {
    fwrite(STDERR, 'ПОМИЛКА: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);

    if ($verbose) {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }

    exit(1);
}
