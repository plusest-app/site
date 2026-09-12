<?php

namespace Plusest\Site\Support;

use Plusest\Site\Exception\SiteException;

/**
 * Невеликі помічники для роботи з файловою системою.
 *
 * Клас навмисно складається лише зі статичних методів — це не сервіс,
 * а набір коротких утиліт, які використовуються по всьому пакету.
 */
class Fs
{
    /**
     * Склеює частини шляху через один розділювач.
     *
     * Приймає будь-яку кількість аргументів і прибирає зайві слеші на стиках,
     * тому Fs::join('/var/www/', '/data', 'photos') дасть '/var/www/data/photos'.
     *
     * @param string ...$parts Частини шляху
     *
     * @return string
     */
    public static function join()
    {
        $parts = func_get_args();
        $clean = [];

        foreach ($parts as $index => $part) {
            $part = (string) $part;

            if ($part === '') {
                continue;
            }

            // Приводимо розділювачі до одного вигляду: у конфізі шляхи пишуть
            // через "/", і на Windows не хочеться бачити в логах "target\install/schema.sql".
            $part = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $part);

            // Прибираємо префікси "./" — вони нічого не означають, але псують
            // вигляд шляху в повідомленнях для користувача.
            while (str_starts_with($part, '.' . DIRECTORY_SEPARATOR)) {
                $part = substr($part, 2);
            }

            // У першої частини початковий слеш зберігаємо (абсолютний шлях),
            // у наступних — прибираємо, щоб не отримати подвійний розділювач.
            $part = $index === 0 ? rtrim($part, '/\\') : trim($part, '/\\');

            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $clean);
    }

    /**
     * Створює каталог разом з усіма батьківськими, якщо його ще немає.
     *
     * @param string $path Шлях до каталогу
     * @param int    $mode Права доступу (за замовчуванням 0775)
     *
     * @return string Той самий шлях — щоб виклик можна було вбудовувати у вираз
     *
     * @throws SiteException Якщо каталог не існує і створити його не вдалося
     */
    public static function ensureDir($path, $mode = 0775)
    {
        if (is_dir($path)) {
            return $path;
        }

        // Третій аргумент true — рекурсивне створення батьківських каталогів.
        // Помилку глушимо через @, бо каталог могли створити паралельно
        // (наприклад, два прогони синхронізації), і тоді warning нам не потрібен.
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new SiteException('Не вдалося створити каталог: ' . $path);
        }

        return $path;
    }

    /**
     * Атомарний запис у файл.
     *
     * Спочатку пишемо в тимчасовий файл у тому самому каталозі, а потім
     * перейменовуємо його. Так читач ніколи не побачить напівзаписаний файл —
     * це критично для моделей даних, які читаються під час рендерингу сторінок.
     *
     * @param string $path     Кінцевий шлях до файлу
     * @param string $contents Вміст
     *
     * @return void
     *
     * @throws SiteException Якщо записати або перейменувати не вдалося
     */
    public static function writeAtomic($path, $contents)
    {
        self::ensureDir(dirname($path));

        $temp = $path . '.tmp' . getmypid();

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            throw new SiteException('Не вдалося записати файл: ' . $temp);
        }

        // На Windows rename() не перезаписує існуючий файл, тому прибираємо його заздалегідь.
        if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
            @unlink($path);
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);

            throw new SiteException('Не вдалося перейменувати файл у: ' . $path);
        }
    }

    /**
     * Рекурсивно копіює каталог.
     *
     * Використовується установником для публікації stubs у проєкт користувача.
     * Файли, які вже існують, за замовчуванням НЕ перезаписуються — щоб
     * повторний запуск установника не затер відредаговані шаблони й конфіг.
     *
     * @param string $source    Каталог-джерело
     * @param string $target    Каталог-призначення
     * @param bool   $overwrite Чи перезаписувати наявні файли
     *
     * @return array{copied: string[], skipped: string[]} Списки відносних шляхів
     */
    public static function copyDir($source, $target, $overwrite = false)
    {
        $result = ['copied' => [], 'skipped' => []];

        if (!is_dir($source)) {
            throw new SiteException('Каталог-джерело не знайдено: ' . $source);
        }

        self::ensureDir($target);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $prefixLength = strlen(rtrim($source, '/\\')) + 1;

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), $prefixLength);
            $destination = self::join($target, $relative);

            if ($item->isDir()) {
                self::ensureDir($destination);

                continue;
            }

            if (!$overwrite && is_file($destination)) {
                $result['skipped'][] = $relative;

                continue;
            }

            self::ensureDir(dirname($destination));

            if (!@copy($item->getPathname(), $destination)) {
                throw new SiteException('Не вдалося скопіювати файл у: ' . $destination);
            }

            $result['copied'][] = $relative;
        }

        return $result;
    }

    /**
     * Рекурсивно видаляє каталог з усім вмістом.
     *
     * Потрібно на етапі синхронізації: коли публікація зникла з фіда і
     * налаштування вимагають видалення — прибираємо і каталог з її фото.
     *
     * @param string $path Шлях до каталогу
     *
     * @return bool true, якщо каталогу вже немає або він успішно видалений
     */
    public static function removeDir($path)
    {
        if (!is_dir($path)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($path);
    }
}
