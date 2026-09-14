<?php

namespace Plusest\Site\Console;

use Plusest\Site\Exception\SiteException;

/**
 * Мінімальний обробник команд командного рядка.
 *
 * Свідомо без symfony/console: заготовці потрібні лише дві команди, а зайва
 * залежність ускладнила б встановлення на старі хостинги.
 *
 * Використання:
 *
 *     php vendor/bin/plusestSite install [каталог] [--force]
 *     php vendor/bin/plusestSite migrate --config=config.php
 *     php vendor/bin/plusestSite check   --config=config.php
 */
class Cli
{
    /**
     * Версія заготовки — показується в довідці.
     */
    const VERSION = '1.0.1';

    /**
     * Позиційні аргументи (усе, що не починається з "--").
     *
     * @var string[]
     */
    private $arguments = [];

    /**
     * Прапорці та опції у вигляді «назва => значення».
     *
     * Для --force значенням буде true, для --config=x — рядок 'x'.
     *
     * @var array
     */
    private $options = [];

    /**
     * Чи підтримує термінал кольори.
     *
     * @var bool
     */
    private $colors;

    /**
     * Точка входу: розбирає аргументи, запускає команду, повертає код виходу.
     *
     * @param array $argv Масив $argv, як його отримав скрипт
     *
     * @return int Код виходу: 0 — успіх, 1 — помилка
     */
    public function run(array $argv)
    {
        // Перший елемент $argv — шлях до самого скрипта, він нам не потрібен.
        array_shift($argv);

        $this->parse($argv);
        $this->colors = $this->supportsColors();

        $command = isset($this->arguments[0]) ? $this->arguments[0] : 'help';

        try {
            switch ($command) {
                case 'install':
                    $installer = new InstallCommand($this);

                    return $installer->run();

                case 'migrate':
                    $migrate = new MigrateCommand($this);

                    return $migrate->run();

                case 'check':
                    $migrate = new MigrateCommand($this);

                    return $migrate->check();

                case 'sync':
                    $sync = new SyncCommand($this);

                    return $sync->run();

                case 'state':
                    $sync = new SyncCommand($this);

                    return $sync->state();

                case 'models':
                    $sync = new SyncCommand($this);

                    return $sync->models();

                case 'help':
                case '--help':
                case '-h':
                    $this->showHelp();

                    return 0;

                case 'version':
                case '--version':
                    $this->line('Plusest Site ' . self::VERSION);

                    return 0;

                default:
                    $this->error('Невідома команда: ' . $command);
                    $this->line('');
                    $this->showHelp();

                    return 1;
            }
        } catch (SiteException $e) {
            // Наші власні помилки показуємо як зрозумілий текст без стеку.
            $this->error($e->getMessage());

            return 1;
        } catch (\Exception $e) {
            // Усе інше — з класом виключення, щоб було з чим прийти по допомогу.
            $this->error(get_class($e) . ': ' . $e->getMessage());

            if ($this->option('verbose', false)) {
                $this->line($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Позиційний аргумент за номером.
     *
     * @param int         $index   Номер, починаючи з 0 (0 — це назва команди)
     * @param string|null $default Значення за замовчуванням
     *
     * @return string|null
     */
    public function argument($index, $default = null)
    {
        return isset($this->arguments[$index]) ? $this->arguments[$index] : $default;
    }

    /**
     * Значення опції --назва або --назва=значення.
     *
     * @param string $name    Назва опції без "--"
     * @param mixed  $default Значення за замовчуванням
     *
     * @return mixed
     */
    public function option($name, $default = null)
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    /**
     * Виводить рядок тексту.
     *
     * @param string $text Текст
     *
     * @return void
     */
    public function line($text = '')
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    /**
     * Доповнює рядок пробілами до потрібної ширини.
     *
     * Потрібен замість str_pad, бо той рахує байти: український текст у UTF-8
     * займає по два байти на літеру, і колонки в таблицях роз'їжджаються.
     *
     * @param string $text  Текст
     * @param int    $width Потрібна ширина в символах
     *
     * @return string
     */
    public function pad($text, $width)
    {
        $text = (string) $text;
        $length = mb_strlen($text, 'UTF-8');

        return $length >= $width ? $text : $text . str_repeat(' ', $width - $length);
    }

    /**
     * Виводить заголовок розділу.
     *
     * @param string $text Текст заголовка
     *
     * @return void
     */
    public function title($text)
    {
        $this->line('');
        $this->line($this->paint($text, '1;36'));
        $this->line($this->paint(str_repeat('-', mb_strlen($text)), '1;36'));
    }

    /**
     * Повідомлення про успіх.
     *
     * @param string $text Текст
     *
     * @return void
     */
    public function success($text)
    {
        $this->line($this->paint('  OK  ', '42;30') . ' ' . $text);
    }

    /**
     * Попередження — щось пропущено, але робота продовжується.
     *
     * @param string $text Текст
     *
     * @return void
     */
    public function warn($text)
    {
        $this->line($this->paint(' УВАГА ', '43;30') . ' ' . $text);
    }

    /**
     * Повідомлення про помилку. Пишеться у STDERR.
     *
     * @param string $text Текст
     *
     * @return void
     */
    public function error($text)
    {
        fwrite(STDERR, $this->paint(' ПОМИЛКА ', '41;97') . ' ' . $text . PHP_EOL);
    }

    /**
     * Розбирає масив аргументів на позиційні та опції.
     *
     * @param array $argv Аргументи без назви скрипта
     *
     * @return void
     */
    private function parse(array $argv)
    {
        foreach ($argv as $argument) {
            if (!str_starts_with($argument, '--')) {
                $this->arguments[] = $argument;

                continue;
            }

            $argument = substr($argument, 2);

            if (str_contains($argument, '=')) {
                list($name, $value) = explode('=', $argument, 2);
                $this->options[$name] = $value;
            } else {
                $this->options[$argument] = true;
            }
        }
    }

    /**
     * Обгортає текст ANSI-кодом кольору, якщо термінал це підтримує.
     *
     * @param string $text Текст
     * @param string $code ANSI-код, напр. '1;36'
     *
     * @return string
     */
    private function paint($text, $code)
    {
        return $this->colors ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }

    /**
     * Чи вміє поточний термінал показувати кольори.
     *
     * @return bool
     */
    private function supportsColors()
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        // Windows до Windows 10 не розуміє ANSI-кодів, тому там кольори
        // включаємо лише за наявності ANSICON або запуску в новому терміналі.
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('WT_SESSION') !== false
                || getenv('TERM') === 'xterm';
        }

        return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
    }

    /**
     * Виводить довідку по командах.
     *
     * @return void
     */
    private function showHelp()
    {
        $this->line('Plusest Site ' . self::VERSION . ' — заготовка сайту-бази нерухомості');
        $this->line('');
        $this->line('Команди:');
        $this->line('');
        $this->line('  install [каталог] [--force]');
        $this->line('      Копіює конфіг, шаблони та скрипти в ваш проєкт.');
        $this->line('      Каталог за замовчуванням — ./plusestSite');
        $this->line('      --force перезаписує вже наявні файли (обережно: затре ваші правки).');
        $this->line('');
        $this->line('  migrate --config=ШЛЯХ');
        $this->line('      Створює таблиці в базі даних за файлом schema.sql.');
        $this->line('      Безпечно запускати повторно.');
        $this->line('');
        $this->line('  check --config=ШЛЯХ');
        $this->line('      Перевіряє налаштування, підключення до бази і наявність таблиць.');
        $this->line('');
        $this->line('  sync --config=ШЛЯХ [--force]');
        $this->line('      Забирає фід із CRM, оновлює локальну базу обʼєктів');
        $this->line('      і завантажує фотографії.');
        $this->line('      --force запитує фід негайно, не чекаючи розкладу.');
        $this->line('      З cron зручніше запускати bin/sync.php у вашому проєкті.');
        $this->line('');
        $this->line('  models --config=ШЛЯХ [--force]');
        $this->line('      Оновлює моделі даних, за якими шаблони підписують');
        $this->line('      параметри та характеристики обʼєктів.');
        $this->line('      Без --force свіжі копії не перезавантажуються.');
        $this->line('');
        $this->line('  state --config=ШЛЯХ');
        $this->line('      Показує час останньої синхронізації, її підсумки та лог.');
        $this->line('');
        $this->line('  version');
        $this->line('      Показує версію заготовки.');
        $this->line('');
    }
}
