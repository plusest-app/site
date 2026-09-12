<?php

/**
 * Збірка релізного ZIP-архіву.
 *
 * Навіщо це потрібно
 * ------------------
 * Заготовка розповсюджується двома шляхами:
 *
 *   1. Composer — для розробників: composer require plusest/site
 *   2. ZIP-архів — для решти: скачав, розпакував на хостинг, відкрив у браузері
 *
 * Другий шлях головний за кількістю користувачів: рієлтор чи власник агентства
 * зазвичай не має SSH, отже не може виконати composer require. Тому в архів
 * складається все відразу, разом із каталогом vendor/ — щоб після розпакування
 * нічого доставляти вже не треба було.
 *
 * Запуск (з кореня пакета):
 *
 *     composer install --no-dev --optimize-autoloader
 *     php build/makeRelease.php
 *
 * Результат: build/plusestSite-{версія}.zip
 *
 * Структура архіву — готовий до роботи каталог:
 *
 *     plusestSite/
 *     ├── config.php            згенерований із шаблону, з порожніми значеннями
 *     ├── src/                  ядро заготовки
 *     ├── resources/            шаблон конфігу
 *     ├── stubs/                недоторкані копії файлів, які публікуються
 *     ├── install/
 *     │   └── schema.sql
 *     ├── data/
 *     ├── docs/nginx.md
 *     ├── public/
 *     │   ├── install/          веб-установник (видаляється після встановлення)
 *     │   └── photos/
 *     ├── templates/
 *     ├── bin/
 *     └── vendor/               сторонні залежності та автозавантажувач
 *
 *  Чому src/ лежить у корені, а не у vendor/plusest/site/
 *  ------------------------------------------------------
 *  Composer генерує автозавантажувач з точки зору КОРЕНЕВОГО пакета. Для нас
 *  кореневий пакет — це plusest/site, тому в autoload_psr4.php записано
 *  "Plusest\Site\" => baseDir . "/src", де baseDir — це каталог поруч
 *  із vendor. Отже в ZIP-збірці ядро мусить лежати саме там, інакше
 *  автозавантажувач його не знайде.
 */

// Скрипт лише для розробки пакета, не для користувача.
if (PHP_SAPI !== 'cli') {
    exit('Цей скрипт запускається лише з командного рядка.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Plusest\Site\Console\Cli;
use Plusest\Site\Installer\ConfigTemplate;
use Plusest\Site\Support\Fs;
use Plusest\Site\Support\Package;

$root = Package::root();
$buildDir = Fs::join($root, 'build');
$stageDir = Fs::join($buildDir, 'stage');
$innerName = 'plusestSite';
$stageInner = Fs::join($stageDir, $innerName);

echo 'Збірка релізу Plusest Site ' . Cli::VERSION . PHP_EOL;
echo 'Корінь пакета: ' . $root . PHP_EOL . PHP_EOL;

// --- 1. Перевірки перед збіркою ---------------------------------------------

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, 'Потрібне розширення PHP zip.' . PHP_EOL);

    exit(1);
}

$vendorDir = Fs::join($root, 'vendor');

if (!is_dir($vendorDir)) {
    fwrite(
        STDERR,
        'Каталог vendor/ не знайдено. Спочатку виконайте:' . PHP_EOL
        . '    composer install --no-dev --optimize-autoloader' . PHP_EOL
    );

    exit(1);
}

// Якщо в vendor лежать dev-залежності, архів роздується без користі.
if (is_dir(Fs::join($vendorDir, 'phpunit')) || is_dir(Fs::join($vendorDir, 'squizlabs'))) {
    echo 'УВАГА: у vendor/ є dev-залежності. Перезберіть з --no-dev.' . PHP_EOL . PHP_EOL;
}

// --- 2. Готуємо чистий каталог для збірки ------------------------------------

echo 'Готую каталог збірки...' . PHP_EOL;

Fs::removeDir($stageDir);
Fs::ensureDir($stageInner);

// --- 3. Складаємо вміст ------------------------------------------------------

// Те, що користувач бачить і править: копіюється як є.
echo 'Копіюю заготовки (stubs)...' . PHP_EOL;
$stubs = Fs::copyDir(Package::stubs(), $stageInner, true);
echo '  файлів: ' . count($stubs['copied']) . PHP_EOL;

// Сторонні залежності разом із згенерованим автозавантажувачем.
echo 'Копіюю vendor/...' . PHP_EOL;
$vendor = Fs::copyDir($vendorDir, Fs::join($stageInner, 'vendor'), true);
echo '  файлів: ' . count($vendor['copied']) . PHP_EOL;

// Ядро заготовки — у корінь архіву, поруч із vendor. Саме там його очікує
// автозавантажувач, згенерований Composer-ом для кореневого пакета.
echo 'Копіюю ядро пакета...' . PHP_EOL;

foreach (['src', 'resources', 'stubs', 'bin'] as $directory) {
    $copied = Fs::copyDir(Fs::join($root, $directory), Fs::join($stageInner, $directory), true);
    echo '  ' . $directory . '/: ' . count($copied['copied']) . ' файлів' . PHP_EOL;
}

// composer.json потрібен, щоб користувач при бажанні міг виконати
// composer update і підтягнути свіжі версії залежностей.
copy(Fs::join($root, 'composer.json'), Fs::join($stageInner, 'composer.json'));

// Документація — поруч із конфігом, щоб її точно знайшли.
echo 'Копіюю документацію...' . PHP_EOL;
Fs::copyDir(Fs::join($root, 'docs'), Fs::join($stageInner, 'docs'), true);
copy(Fs::join($root, 'README.md'), Fs::join($stageInner, 'README.md'));

// config.php з порожніми значеннями: веб-установник перезапише його
// відповідями користувача, а до того файл уже валідний і читається.
echo 'Генерую config.php із шаблону...' . PHP_EOL;
ConfigTemplate::write(Package::configTemplate(), Fs::join($stageInner, 'config.php'), [], true);

// Каталоги, які мусять існувати, але порожні: ZipArchive не зберігає
// порожні каталоги, тому кладемо в кожен .gitignore-заглушку.
foreach (['data', 'public/photos'] as $directory) {
    Fs::ensureDir(Fs::join($stageInner, $directory));
}

// --- 4. Прибираємо зайве -----------------------------------------------------

echo 'Прибираю зайве з vendor/...' . PHP_EOL;
$removed = pruneVendor(Fs::join($stageInner, 'vendor'));
echo '  видалено файлів: ' . $removed . PHP_EOL;

// --- 5. Пакуємо --------------------------------------------------------------

$archiveFile = Fs::join($buildDir, 'plusestSite-' . Cli::VERSION . '.zip');

echo PHP_EOL . 'Пакую архів...' . PHP_EOL;

if (is_file($archiveFile)) {
    unlink($archiveFile);
}

$zip = new ZipArchive();

if ($zip->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, 'Не вдалося створити архів: ' . $archiveFile . PHP_EOL);

    exit(1);
}

$count = addDirToZip($zip, $stageDir, '');
$zip->close();

// --- 6. Прибираємо за собою --------------------------------------------------

Fs::removeDir($stageDir);

$size = filesize($archiveFile);

echo PHP_EOL;
echo 'Готово.' . PHP_EOL;
echo 'Архів:  ' . $archiveFile . PHP_EOL;
echo 'Файлів: ' . $count . PHP_EOL;
echo 'Розмір: ' . round($size / 1024 / 1024, 2) . ' МБ' . PHP_EOL;

exit(0);


/**
 * Рекурсивно додає каталог у ZIP-архів.
 *
 * @param ZipArchive $zip    Відкритий архів
 * @param string     $source Каталог-джерело
 * @param string     $prefix Префікс шляху всередині архіву
 *
 * @return int Скільки файлів додано
 */
function addDirToZip(ZipArchive $zip, $source, $prefix)
{
    $count = 0;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $prefixLength = strlen(rtrim($source, '/\\')) + 1;

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        // Усередині ZIP розділювач завжди "/", навіть якщо збирали на Windows.
        $relative = str_replace('\\', '/', substr($item->getPathname(), $prefixLength));
        $entry = $prefix === '' ? $relative : $prefix . '/' . $relative;

        if ($item->isDir()) {
            $zip->addEmptyDir($entry);

            continue;
        }

        $zip->addFile($item->getPathname(), $entry);
        $count++;
    }

    return $count;
}

/**
 * Видаляє з vendor/ те, що на робочому сайті не потрібне.
 *
 * Тести, документація та приклади сторонніх бібліотек можуть займати більше,
 * ніж сам код. Користувачу вони не потрібні, а архів через них розростається.
 *
 * @param string $vendorDir Каталог vendor у збірці
 *
 * @return int Скільки файлів видалено
 */
function pruneVendor($vendorDir)
{
    if (!is_dir($vendorDir)) {
        return 0;
    }

    $junkDirs = ['tests', 'test', 'Tests', 'docs', 'doc', 'examples', 'example', 'benchmarks', '.github'];
    $junkFiles = [
        'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
        '.travis.yml', '.editorconfig', '.gitattributes', '.gitignore',
        'CHANGELOG.md', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'SECURITY.md',
    ];

    $removed = 0;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendorDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $name = $item->getBasename();

        if ($item->isDir() && in_array($name, $junkDirs, true)) {
            $removed += countFiles($item->getPathname());
            Fs::removeDir($item->getPathname());

            continue;
        }

        if ($item->isFile() && in_array($name, $junkFiles, true)) {
            @unlink($item->getPathname());
            $removed++;
        }
    }

    return $removed;
}

/**
 * Рахує файли в каталозі рекурсивно.
 *
 * @param string $path Каталог
 *
 * @return int
 */
function countFiles($path)
{
    if (!is_dir($path)) {
        return 0;
    }

    $count = 0;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isFile()) {
            $count++;
        }
    }

    return $count;
}
