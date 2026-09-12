<?php

namespace Plusest\Site\Photo;

use Exception;
use Intervention\Image\Constraint;
use Intervention\Image\ImageManager;
use Plusest\Site\Config;
use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Fs;

/**
 * Масштабує завантажену фотографію в усі розміри з налаштувань.
 *
 * Як виконується масштабування
 * ----------------------------
 * Розміри обробляються від найбільшого до найменшого, і кожен наступний
 * робиться з результату попереднього, а не з оригіналу:
 *
 *     оригінал 2500x2500  ->  large 1200  ->  medium 300  ->  preview 45x35
 *
 * Це дає дві переваги. По-перше, зменшення картинки 300px замість 2500px
 * виконується в рази швидше. По-друге, пропорції, обчислені на найбільшому
 * розмірі, автоматично зберігаються в усіх наступних.
 *
 * Порядок розмірів у конфізі не має значення — клас сортує їх сам.
 *
 * Назви файлів
 * ------------
 * Для фотографії з ідентифікатором '1754841958-TjwOj' і розмірів large,
 * medium, preview у каталозі публікації зʼявиться:
 *
 *     1754841958-TjwOj-large.jpg
 *     1754841958-TjwOj-medium.jpg
 *     1754841958-TjwOj-preview.jpg
 *
 * Саме за цією схемою шаблони будують посилання на картинки, тому назви
 * розмірів у конфізі й у шаблонах повинні збігатися.
 */
class Resizer
{
    /**
     * Бібліотека обробки зображень: 'gd' або 'imagick'.
     *
     * @var string
     */
    private $driver;

    /**
     * Якість JPEG на виході, 0-100.
     *
     * @var int
     */
    private $quality;

    /**
     * Розміри, впорядковані від найбільшого до найменшого.
     *
     * @var array Масив «назва => [width, height, aspectRatio, upsize]»
     */
    private $sizes;

    /**
     * Менеджер Intervention Image. Створюється при першому використанні.
     *
     * @var ImageManager|null
     */
    private $manager;

    /**
     * @param string $driver  'gd' або 'imagick'
     * @param int    $quality Якість JPEG, 0-100
     * @param array  $sizes   Розміри з налаштування photos.sizes
     *
     * @throws SiteException Якщо жоден розмір не заданий коректно
     */
    public function __construct($driver, $quality, array $sizes)
    {
        $driver = strtolower(trim((string) $driver));

        $this->driver = in_array($driver, ['gd', 'imagick'], true) ? $driver : 'gd';
        $this->quality = max(1, min(100, (int) $quality));
        $this->sizes = self::normalizeSizes($sizes);
    }

    /**
     * Створює обробник із налаштувань.
     *
     * @param Config $config Налаштування
     *
     * @return self
     *
     * @throws SiteException Якщо розміри в конфізі задані неправильно
     */
    public static function fromConfig(Config $config)
    {
        return new self(
            $config->get('photos.driver', 'gd'),
            $config->get('photos.quality', 85),
            (array) $config->get('photos.sizes', [])
        );
    }

    /**
     * Робить із файлу всі потрібні розміри.
     *
     * @param string $sourceFile Завантажений оригінал
     * @param string $targetDir  Каталог, куди складати результат
     * @param string $baseName   Основа назви файлу — ідентифікатор фотографії
     *
     * @return int Скільки файлів створено
     *
     * @throws SiteException Якщо файл не є зображенням або запис не вдався
     */
    public function process($sourceFile, $targetDir, $baseName)
    {
        $baseName = self::safeName($baseName);

        if ($baseName === '') {
            throw new SiteException('Порожня назва файлу фотографії.');
        }

        Fs::ensureDir($targetDir);

        try {
            $image = $this->manager()->make($sourceFile);
        } catch (Exception $e) {
            // Найчастіша причина — замість фотографії завантажилась
            // HTML-сторінка помилки або файл обрізався на півдорозі.
            throw new SiteException(
                'Не вдалося прочитати зображення ' . basename($sourceFile) . ': ' . $e->getMessage()
            );
        }

        try {
            // Фотографії з телефонів бувають записані «боком»: правильна
            // орієнтація лежить у EXIF. Без exif-розширення просто пропускаємо.
            if (function_exists('exif_read_data')) {
                try {
                    $image->orientate();
                } catch (Exception $ignored) {
                    // Зіпсований EXIF не причина відмовлятись від фотографії.
                }
            }

            $written = 0;

            foreach ($this->sizes as $name => $size) {
                $image->resize($size['width'] ?? null, $size['height'] ?? null, function (Constraint $constraint) use ($size) {
                    if ($size['aspectRatio']) {
                        $constraint->aspectRatio();
                    }

                    // upsize у Intervention означає «не збільшувати те, що
                    // менше за заданий розмір» — саме так це поле й назване
                    // в налаштуваннях.
                    if ($size['upsize']) {
                        $constraint->upsize();
                    }
                });

                $image->save($this->fileName($targetDir, $baseName, $name), $this->quality, 'jpg');

                $written++;
            }
        } catch (Exception $e) {
            $image->destroy();

            throw new SiteException(
                'Не вдалося зберегти розміри фотографії ' . $baseName . ': ' . $e->getMessage()
            );
        }

        // Звільняємо памʼять одразу: GD тримає розпаковане зображення
        // цілком, і на фотографії 2500x2500 це близько 25 МБ.
        $image->destroy();

        return $written;
    }

    /**
     * Чи всі розміри цієї фотографії вже лежать на диску.
     *
     * Перевірка потрібна, щоб не завантажувати повторно те, що вже є: у
     * публікації змінилась одна фотографія, а решта двадцять лишились ті самі.
     *
     * @param string $targetDir Каталог публікації
     * @param string $baseName  Ідентифікатор фотографії
     *
     * @return bool
     */
    public function hasAll($targetDir, $baseName)
    {
        $baseName = self::safeName($baseName);

        if ($baseName === '') {
            return false;
        }

        foreach ($this->sizes as $name => $size) {
            $file = $this->fileName($targetDir, $baseName, $name);

            // Нульовий розмір означає, що попередній прогін перервали
            // посередині запису — такий файл вважаємо відсутнім.
            if (!is_file($file) || filesize($file) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Назви файлів, які має мати одна фотографія в усіх розмірах.
     *
     * Використовується для прибирання зайвого: усе, чого немає в цьому
     * списку, з каталогу публікації видаляється.
     *
     * @param string $baseName Ідентифікатор фотографії
     *
     * @return string[] Назви файлів без каталогу
     */
    public function fileNames($baseName)
    {
        $baseName = self::safeName($baseName);

        if ($baseName === '') {
            return [];
        }

        $names = [];

        foreach ($this->sizes as $name => $size) {
            $names[] = $baseName . '-' . $name . '.jpg';
        }

        return $names;
    }

    /**
     * Назви розмірів у порядку від найбільшого до найменшого.
     *
     * @return string[]
     */
    public function sizeNames()
    {
        return array_keys($this->sizes);
    }

    /**
     * Розміри в нормалізованому вигляді — для сторінки стану й налагодження.
     *
     * @return array
     */
    public function sizes()
    {
        return $this->sizes;
    }

    /**
     * Повний шлях до файлу конкретного розміру.
     *
     * @param string $targetDir Каталог
     * @param string $baseName  Ідентифікатор фотографії
     * @param string $sizeName  Назва розміру
     *
     * @return string
     */
    public function fileName($targetDir, $baseName, $sizeName)
    {
        return Fs::join($targetDir, $baseName . '-' . $sizeName . '.jpg');
    }

    /**
     * Прибирає з назви все, чого не має бути в імені файлу.
     *
     * Ідентифікатори фотографій і назви розмірів приходять з фіда та з
     * конфігу, а потім потрапляють у шлях на диску. Тому залишаємо лише
     * латиницю, цифри, дефіс і підкреслення: так ні точки-точки, ні слеш
     * не зможуть вивести запис за межі каталогу з фотографіями.
     *
     * @param mixed $value Вихідне значення
     *
     * @return string Порожній рядок, якщо не залишилось жодного символу
     */
    public static function safeName($value)
    {
        $value = preg_replace('~[^A-Za-z0-9_-]~', '', (string) $value);

        return $value === null ? '' : $value;
    }

    /**
     * Приводить розміри з конфігу до єдиного вигляду й сортує їх.
     *
     * @param array $sizes Значення налаштування photos.sizes
     *
     * @return array Масив «назва => [width, height, aspectRatio, upsize]»
     *
     * @throws SiteException Якщо не залишилось жодного придатного розміру
     */
    private static function normalizeSizes(array $sizes)
    {
        $normalized = [];

        foreach ($sizes as $name => $size) {
            $name = self::safeName($name);

            if ($name === '' || !is_array($size)) {
                continue;
            }

            $width = isset($size['width']) ? (int) $size['width'] : 0;
            $height = isset($size['height']) ? (int) $size['height'] : 0;

            // Хоча б одна зі сторін мусить бути задана, інакше масштабувати
            // нікуди. Мовчки пропускати такий розмір не можна: шаблони
            // очікують на файл із цією назвою.
            if ($width <= 0 && $height <= 0) {
                throw new SiteException(
                    'Розмір фотографій "' . $name . '" у налаштуваннях photos.sizes'
                    . ' не має ні width, ні height.'
                );
            }

            $normalized[$name] = [
                'width'  => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,

                // За замовчуванням пропорції зберігаємо: спотворена
                // фотографія виглядає гірше за будь-яке кадрування.
                'aspectRatio' => !isset($size['aspectRatio']) || (bool) $size['aspectRatio'],
                'upsize'      => isset($size['upsize']) && $size['upsize'],
            ];
        }

        if ($normalized === []) {
            throw new SiteException(
                'У налаштуваннях photos.sizes немає жодного розміру фотографій.'
            );
        }

        // Сортуємо від найбільшого до найменшого — саме в такому порядку
        // виконується масштабування.
        uasort($normalized, function (array $first, array $second) {
            return self::weight($second) - self::weight($first);
        });

        return $normalized;
    }

    /**
     * «Вага» розміру для сортування — найбільша з двох сторін.
     *
     * @param array $size Нормалізований розмір
     *
     * @return int
     */
    private static function weight(array $size)
    {
        return max((int) $size['width'], (int) $size['height']);
    }

    /**
     * Менеджер Intervention Image.
     *
     * @return ImageManager
     *
     * @throws SiteException Якщо вибрана бібліотека недоступна
     */
    private function manager()
    {
        if ($this->manager !== null) {
            return $this->manager;
        }

        if (!extension_loaded($this->driver)) {
            throw new SiteException(
                'Для обробки фотографій потрібне розширення PHP ' . $this->driver . '.' . "\n"
                . 'Перевірте налаштування photos.driver — доступні варіанти: gd, imagick.'
            );
        }

        $this->manager = new ImageManager(['driver' => $this->driver]);

        return $this->manager;
    }
}
