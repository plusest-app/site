<?php

/**
 * ============================================================================
 *  Запуск синхронізації через браузер
 * ============================================================================
 *
 *  Потрібен лише тим, у кого немає доступу до cron через SSH, а панель
 *  хостингу вміє тільки «викликати URL за розкладом». Якщо ви запускаєте
 *  bin/sync.php звичайним cron — цей файл можна видалити.
 *
 *  Як увімкнути
 *  ------------
 *  Заповніть у config.php секцію cron:
 *
 *      'cron' => [
 *          'token'       => 'довгий випадковий рядок',
 *          'minInterval' => 300,
 *      ],
 *
 *  і вкажіть у панелі хостингу адресу:
 *
 *      https://ваш-сайт/base/cron.php?token=довгий випадковий рядок
 *
 *  Поки token порожній, файл відповідає 404 — так само, як на будь-яку іншу
 *  неіснуючу адресу. Це не помилка, а захист: без токена ніхто не має
 *  дізнатися навіть про те, що такий файл існує.
 *
 *  Обмеження за часом
 *  ------------------
 *  Між двома веб-запусками мусить пройти щонайменше cron.minInterval секунд.
 *  Інакше досить було б відкрити цю адресу сотню разів у циклі, щоб
 *  завалити і сервер, і ліміти CRM. Час останнього запуску зберігається у
 *  файлі data/cronWeb.txt.
 *
 *  Розклад звернень до самої CRM цей файл не обходить: як і cron, він лише
 *  дає скрипту можливість спрацювати, а вирішує все секція sync у config.php.
 * ============================================================================
 */

// --- Пошук автозавантажувача Composer ---------------------------------------
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
    exit('Не знайдено автозавантажувач Composer.');
}

require $plusestAutoload;

use Plusest\Site\Config;
use Plusest\Site\Support\Fs;
use Plusest\Site\Sync\Synchronizer;

header('Content-Type: text/plain; charset=utf-8');

/**
 * Відповідає так, ніби файлу не існує.
 *
 * Саме 404, а не 403: сторонньому не потрібно знати, що тут щось є.
 *
 * @return void
 */
function plusestCronNotFound()
{
    http_response_code(404);

    exit("Not Found\n");
}

$plusestConfigFile = __DIR__ . '/../config.php';

if (!is_file($plusestConfigFile)) {
    plusestCronNotFound();
}

try {
    $config = Config::load($plusestConfigFile);
} catch (Exception $e) {
    http_response_code(500);
    exit("Помилка конфігурації.\n");
}

$token = (string) $config->get('cron.token', '');

// Веб-запуск вимкнений.
if ($token === '') {
    plusestCronNotFound();
}

$given = isset($_GET['token']) && !is_array($_GET['token']) ? (string) $_GET['token'] : '';

// hash_equals — порівняння за постійний час, щоб токен не можна було
// підібрати посимвольно за часом відповіді.
if ($given === '' || !hash_equals($token, $given)) {
    plusestCronNotFound();
}

// --- Захист від частих запусків ----------------------------------------------
$minInterval = max(0, (int) $config->get('cron.minInterval', 300));
$stampFile = Fs::join($config->path('paths.data'), 'cronWeb.txt');

if ($minInterval > 0 && is_file($stampFile)) {
    $last = (int) @file_get_contents($stampFile);
    $wait = $last + $minInterval - time();

    if ($wait > 0) {
        // 429 — «забагато запитів». Панель хостингу з такою відповіддю
        // зазвичай не надсилає листа, а от у логах видно, що сталось.
        http_response_code(429);

        exit('Минуло замало часу з попереднього запуску. Наступний можливий через '
            . $wait . " с.\n");
    }
}

@Fs::writeAtomic($stampFile, (string) time());

// --- Прогін -------------------------------------------------------------------
//  Синхронізація може тривати довго: перший прогін завантажує всі
//  фотографії. Знімаємо обмеження часу, наскільки хостинг це дозволяє, а
//  решту доберуть наступні запуски — робота продовжується з того місця, де
//  її перервали.
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

// Відвідувач цієї адреси — планувальник, а не людина: обірваний звʼязок не
// повинен зупиняти прогін на середині.
ignore_user_abort(true);

try {
    $synchronizer = new Synchronizer($config);

    $synchronizer->logger()->setOutput(function ($message, $level) {
        echo ($level === 'info' ? '' : strtoupper($level) . ': ') . $message . "\n";
    });

    $result = $synchronizer->run(isset($_GET['force']));

    foreach ($result->lines() as $line) {
        echo $line . "\n";
    }

    if ($result->hasErrors()) {
        // Код 500 потрібен, щоб панель хостингу надіслала листа: помилки
        // на окремих обʼєктах видно лише тут і в логу.
        http_response_code(500);

        echo "\nПомилки:\n";

        foreach ($result->errors() as $error) {
            echo '  - ' . $error . "\n";
        }

        exit;
    }

    echo "\nГотово.\n";
} catch (Exception $e) {
    http_response_code(500);

    echo 'ПОМИЛКА: ' . $e->getMessage() . "\n";
}
