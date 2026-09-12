<?php

namespace Plusest\Site\Console;

use Plusest\Site\Installer\ConfigTemplate;
use Plusest\Site\Support\Fs;
use Plusest\Site\Support\Package;

/**
 * Команда install — публікує заготовку в проєкт користувача.
 *
 * Копіює вміст каталогу stubs/ пакета в каталог, вказаний користувачем
 * (за замовчуванням ./plusestSite), і генерує config.php із шаблону.
 * Файли, які вже існують, НЕ перезаписуються, якщо не передано --force:
 * інакше повторний запуск затер би відредаговані шаблони й заповнений конфіг.
 *
 * Розділення на «ядро в vendor/» і «шаблони у проєкті» зроблене свідомо:
 * ядро оновлюється через composer update, а все, що ви правите під свій
 * сайт, лежить у вашому проєкті й ніколи не переписується оновленням.
 *
 * Ця команда — консольний шлях встановлення, для тих, у кого є SSH.
 * Другий шлях — веб-установник у браузері; обидва користуються одним і тим
 * самим кодом із src/Installer.
 */
class InstallCommand
{
    /**
     * @var Cli
     */
    private $cli;

    /**
     * @param Cli $cli Обробник командного рядка — через нього виводимо текст
     */
    public function __construct(Cli $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Виконує публікацію.
     *
     * @return int Код виходу
     */
    public function run()
    {
        $target = $this->resolveTarget();
        $source = Package::stubs();
        $force = (bool) $this->cli->option('force', false);

        $this->cli->title('Встановлення заготовки Plusest Site');
        $this->cli->line('Джерело:     ' . $source);
        $this->cli->line('Призначення: ' . $target);

        if ($force) {
            $this->cli->warn('Режим --force: наявні файли будуть перезаписані.');
        }

        $result = Fs::copyDir($source, $target, $force);

        $this->cli->line('');

        foreach ($result['copied'] as $file) {
            $this->cli->line('  + ' . $file);
        }

        foreach ($result['skipped'] as $file) {
            $this->cli->line('  = ' . $file . '  (уже існує, пропущено)');
        }

        // config.php не копіюється, а генерується з шаблону: так у ньому
        // залишаються всі коментарі, а значення підставляються за замовчуванням.
        $configWritten = $this->writeConfig($target, $force);

        // Каталоги, які мають існувати, але у stubs лежать порожніми —
        // git та composer порожні каталоги не переносять, тому створюємо їх тут.
        foreach (['data', 'public/photos'] as $directory) {
            $path = Fs::join($target, $directory);

            if (!is_dir($path)) {
                Fs::ensureDir($path);
                $this->cli->line('  + ' . $directory . '/');
            }
        }

        $written = count($result['copied']) + ($configWritten ? 1 : 0);
        $skipped = count($result['skipped']) + ($configWritten ? 0 : 1);

        $this->cli->line('');
        $this->cli->success('Записано файлів: ' . $written . ', пропущено: ' . $skipped);

        $this->showNextSteps($target);

        return 0;
    }

    /**
     * Генерує config.php із шаблону пакета.
     *
     * @param string $target Каталог встановлення
     * @param bool   $force  Дозволити перезапис наявного конфігу
     *
     * @return bool Чи був файл записаний (false — уже існував і пропущений)
     */
    private function writeConfig($target, $force)
    {
        $configFile = Fs::join($target, 'config.php');

        if (is_file($configFile) && !$force) {
            $this->cli->line('  = config.php  (уже існує, пропущено)');

            return false;
        }

        // Підставляємо в підказку про cron справжній шлях до скрипта —
        // щоб рядок для crontab можна було скопіювати без правок.
        ConfigTemplate::write(Package::configTemplate(), $configFile, [
            'cronScriptPath' => Fs::join($target, 'bin', 'sync.php'),
        ], true);

        $this->cli->line('  + config.php');

        return true;
    }

    /**
     * Каталог, у який ставимо заготовку.
     *
     * Береться з першого позиційного аргументу, інакше — ./plusestSite
     * у поточному робочому каталозі.
     *
     * @return string
     */
    private function resolveTarget()
    {
        $target = $this->cli->argument(1);

        if ($target === null || $target === '') {
            $target = 'plusestSite';
        }

        // Відносний шлях розкриваємо від каталогу, з якого запустили команду.
        if (!preg_match('~^(/|\\\\|[A-Za-z]:[\\\\/])~', $target)) {
            $target = Fs::join(getcwd(), $target);
        }

        return $target;
    }

    /**
     * Підказка «що робити далі» — щоб користувач не шукав інструкцію.
     *
     * @param string $target Каталог, у який поставили заготовку
     *
     * @return void
     */
    private function showNextSteps($target)
    {
        $config = Fs::join($target, 'config.php');

        $this->cli->title('Що робити далі');
        $this->cli->line('1. Відкрийте ' . $config);
        $this->cli->line('   і заповніть секції db (доступ до MySQL) та feed.url');
        $this->cli->line('   (посилання публікації з кабінету CRM).');
        $this->cli->line('');
        $this->cli->line('2. Створіть таблиці в базі:');
        $this->cli->line('   php vendor/bin/plusestSite migrate --config=' . $config);
        $this->cli->line('');
        $this->cli->line('3. Перевірте налаштування:');
        $this->cli->line('   php vendor/bin/plusestSite check --config=' . $config);
        $this->cli->line('');
        $this->cli->line('4. Налаштуйте вебсервер. Інструкція з трьома варіантами');
        $this->cli->line('   підключення і розділом про типові помилки:');
        $this->cli->line('   ' . Fs::join(Package::root(), 'docs', 'nginx.md'));
        $this->cli->line('');
        $this->cli->line('5. Додайте завдання cron — готові рядки наведені');
        $this->cli->line('   в кінці файлу config.php');
        $this->cli->line('');
    }
}
