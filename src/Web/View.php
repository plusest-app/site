<?php

namespace Plusest\Site\Web;

use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Fs;

/**
 * Найпростіший рендерер шаблонів: звичайні PHP-файли.
 *
 * Жодного власного синтаксису — шаблон це PHP-файл, у якому доступні
 * передані змінні:
 *
 *     <h1><?= $view->esc($title) ?></h1>
 *     <?php foreach ($objects as $object): ?>
 *         <?= $view->partial('partials/card', ['object' => $object]) ?>
 *     <?php endforeach ?>
 *
 * Так зроблено свідомо. Сайти в користувачів різні — на WordPress, Bitrix,
 * саморобні, — і в кожного своя верстка. Заготовка не має нав'язувати ще
 * один шаблонізатор: PHP уміє все, що потрібно, і його знає будь-хто, кому
 * доведеться правити ці файли.
 *
 * Що доступно в кожному шаблоні
 * -----------------------------
 *   $view    — цей обʼєкт: esc(), partial(), t(), url(), present()
 *   $t       — переклади інтерфейсу (обʼєкт Translator)
 *   $url     — побудовник адрес (обʼєкт Url)
 *   $present — форматування даних обʼєкта (обʼєкт Present)
 *   $lang    — поточна мова, 'uk' або 'ru'
 *   $config  — налаштування (обʼєкт Config)
 *
 * Плюс те, що передано в render() або partial().
 *
 * Про екранування
 * ---------------
 * Автоматичного екранування немає, тому все, що прийшло з CRM, виводьте
 * через $view->esc(). Виняток — поля, у яких HTML передбачений моделлю
 * (одиниці виміру на кшталт «м<sup>2</sup>»); у шаблонах пакета такі місця
 * позначені комментарем.
 */
class View
{
    /**
     * Розширення файлів шаблонів.
     */
    const EXTENSION = '.php';

    /**
     * Каталог із шаблонами.
     *
     * @var string
     */
    private $dir;

    /**
     * Змінні, доступні в усіх шаблонах.
     *
     * @var array
     */
    private $shared = [];

    /**
     * @param string $templatesDir Каталог із шаблонами
     * @param array  $shared       Змінні для всіх шаблонів
     */
    public function __construct($templatesDir, array $shared = [])
    {
        $this->dir = $templatesDir;
        $this->shared = $shared;
        $this->shared['view'] = $this;
    }

    /**
     * Додає змінну, доступну в усіх шаблонах.
     *
     * @param string $name  Назва змінної (без знака $)
     * @param mixed  $value Значення
     *
     * @return $this
     */
    public function share($name, $value)
    {
        $this->shared[$name] = $value;

        return $this;
    }

    /**
     * Рендерить шаблон і повертає результат рядком.
     *
     * @param string $template Назва шаблону без розширення, напр. 'object'
     * @param array  $data     Змінні шаблону
     *
     * @return string
     *
     * @throws SiteException Якщо файл шаблону не знайдено
     */
    public function render($template, array $data = [])
    {
        $file = $this->file($template);

        if (!is_file($file)) {
            throw new SiteException(
                'Шаблон не знайдено: ' . $file . "\n"
                . 'Перевірте налаштування paths.templates у config.php.'
            );
        }

        // Змінні шаблону: спочатку спільні, потім передані — щоб виклик
        // partial() міг перекрити спільне значення.
        $variables = $this->shared;

        foreach ($data as $name => $value) {
            $variables[$name] = $value;
        }

        // Рендеримо у буфер: шаблон друкує напряму, а нам потрібен рядок,
        // щоб вкласти результат у layout.
        ob_start();

        try {
            (static function (array $plusestVariables, $plusestFile) {
                // extract() у функції без інших локальних змінних: так шаблон
                // не бачить нічого лишнього і не може випадково перекрити
                // службові імена.
                extract($plusestVariables, EXTR_SKIP);

                /** @noinspection PhpIncludeInspection */
                require $plusestFile;
            })($variables, $file);
        } catch (\Exception $e) {
            // Незакритий буфер зіпсував би вивід усієї сторінки.
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Рендерить вкладений шаблон.
     *
     * Те саме, що render(), але назва коротша й читабельніша всередині
     * шаблонів.
     *
     * @param string $template Назва шаблону
     * @param array  $data     Змінні шаблону
     *
     * @return string
     */
    public function partial($template, array $data = [])
    {
        return $this->render($template, $data);
    }

    /**
     * Рендерить шаблон, якщо він існує, інакше повертає порожній рядок.
     *
     * Так підключаються header.php і footer.php: користувач може їх видалити,
     * якщо вставляє верстку свого сайту іншим способом.
     *
     * @param string $template Назва шаблону
     * @param array  $data     Змінні шаблону
     *
     * @return string
     */
    public function optional($template, array $data = [])
    {
        return $this->exists($template) ? $this->render($template, $data) : '';
    }

    /**
     * Чи існує такий шаблон.
     *
     * @param string $template Назва шаблону
     *
     * @return bool
     */
    public function exists($template)
    {
        return is_file($this->file($template));
    }

    /**
     * Екранує текст для вставки в HTML.
     *
     * @param mixed $value Значення
     *
     * @return string
     */
    public function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Текст інтерфейсу.
     *
     * Коротший запис для $t->get() — у шаблонах він трапляється в кожному рядку.
     *
     * @param string $key     Ключ тексту
     * @param array  $replace Значення заповнювачів
     *
     * @return string
     */
    public function t($key, array $replace = [])
    {
        if (!isset($this->shared['t'])) {
            return $key;
        }

        return $this->shared['t']->get($key, $replace);
    }

    /**
     * Побудовник адрес.
     *
     * @return Url|null
     */
    public function url()
    {
        return isset($this->shared['url']) ? $this->shared['url'] : null;
    }

    /**
     * Форматування даних обʼєкта.
     *
     * @return Present|null
     */
    public function present()
    {
        return isset($this->shared['present']) ? $this->shared['present'] : null;
    }

    /**
     * Каталог із шаблонами.
     *
     * @return string
     */
    public function dir()
    {
        return $this->dir;
    }

    /**
     * Повний шлях до файлу шаблону.
     *
     * @param string $template Назва шаблону
     *
     * @return string
     */
    private function file($template)
    {
        // Назва шаблону приходить із нашого ж коду, але вихід за межі
        // каталогу шаблонів все одно забороняємо.
        $template = str_replace(['..', '\\'], ['', '/'], (string) $template);

        return Fs::join($this->dir, ltrim($template, '/') . self::EXTENSION);
    }
}
