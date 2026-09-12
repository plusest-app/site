<?php

namespace Plusest\Site\Console;

use Plusest\Site\Config;
use Plusest\Site\Db;
use Plusest\Site\Exception\ConfigException;
use Plusest\Site\Installer\Requirements;
use Plusest\Site\Migrator;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Support\Fs;
use Plusest\Site\Sync\Schedule;

/**
 * Команди migrate і check.
 *
 *   migrate — створює таблиці в базі за файлом schema.sql;
 *   check   — перевіряє налаштування, підключення до бази й наявність таблиць,
 *             нічого не змінюючи.
 *
 * Обидві потребують шляху до config.php: він передається через --config,
 * або, якщо опцію не вказали, шукається у типових місцях поруч.
 */
class MigrateCommand
{
    /**
     * @var Cli
     */
    private $cli;

    /**
     * @param Cli $cli Обробник командного рядка
     */
    public function __construct(Cli $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Створює таблиці в базі даних.
     *
     * @return int Код виходу
     */
    public function run()
    {
        $config = $this->loadConfig();
        $db = new Db((array) $config->get('db', []));
        $migrator = new Migrator($db);

        $schemaFile = Migrator::resolveSchemaFile($config);

        $this->cli->title('Створення таблиць');
        $this->cli->line('Схема:   ' . $schemaFile);
        $this->cli->line('База:    ' . $config->get('db.name'));
        $this->cli->line('Префікс: ' . ($db->prefix() === '' ? '(без префікса)' : $db->prefix()));

        $missingBefore = $migrator->missingTables();

        if ($missingBefore === []) {
            $this->cli->line('');
            $this->cli->success('Усі таблиці вже існують — нічого робити не потрібно.');

            return 0;
        }

        $result = $migrator->apply($schemaFile);

        $this->cli->line('');
        $this->cli->line('Виконано інструкцій: ' . $result['statements']);

        foreach ($result['tables'] as $table) {
            $this->cli->line('  * ' . $db->table($table));
        }

        $missingAfter = $migrator->missingTables();

        $this->cli->line('');

        if ($missingAfter !== []) {
            $this->cli->error('Не створені таблиці: ' . implode(', ', $missingAfter));

            return 1;
        }

        $this->cli->success('Таблиці готові.');

        return 0;
    }

    /**
     * Перевіряє готовність заготовки до роботи, нічого не змінюючи.
     *
     * @return int Код виходу: 0 — усе гаразд, 1 — є проблеми
     */
    public function check()
    {
        $config = $this->loadConfig();
        $problems = 0;

        // --- Налаштування ---------------------------------------------------
        $this->cli->title('Налаштування');

        $issues = $config->validate();

        if ($issues === []) {
            $this->cli->success('Конфіг заповнений коректно.');
        } else {
            foreach ($issues as $issue) {
                $this->cli->error($issue);
                $problems++;
            }
        }

        $this->cli->line('Мови:            ' . implode(', ', $config->languages())
            . ' (за замовчуванням ' . $config->defaultLanguage() . ')');
        $this->cli->line('Валюти:          ' . implode(', ', (array) $config->get('currency.available', [])));
        $this->cli->line('Розміри фото:    ' . implode(', ', array_keys((array) $config->get('photos.sizes', []))));
        $this->cli->line('Зникла публікація: ' . $config->get('sync.missingMode'));

        // Розклад показуємо готовими інтервалами, а не числами з конфігу:
        // «2 год» зрозуміліше за «7200», а заодно видно, котре правило діє
        // саме зараз.
        $schedule = Schedule::fromConfig($config);

        $this->cli->line('Запит фіда:      будні дні '
            . Schedule::formatDuration($schedule->interval(strtotime('monday 12:00')))
            . ', ніч і вихідні '
            . Schedule::formatDuration($schedule->interval(strtotime('sunday 12:00')))
            . '; зараз діє ' . Schedule::formatDuration($schedule->interval()));

        // --- Оточення PHP ---------------------------------------------------
        //  Ті самі перевірки, що показує веб-установник: список формує
        //  Requirements, тут ми лише виводимо його в термінал.
        $this->cli->title('Оточення PHP і каталоги');

        $requirements = new Requirements([
            'Службовий каталог: моделі даних, лог' => $config->path('paths.data'),
            'Каталог для завантажених фотографій'  => $config->path('paths.photos'),
        ]);

        foreach ($requirements->all() as $check) {
            $line = $check['name'] . ' — ' . $check['actual'];

            if ($check['passed']) {
                $this->cli->success($line);

                continue;
            }

            // Некритичні перевірки не заважають працювати, тому це попередження.
            if (!$check['critical']) {
                $this->cli->warn($line);
                $this->cli->line('         ' . $check['hint']);

                continue;
            }

            $this->cli->error($line);
            $this->cli->line('           ' . $check['hint']);
            $problems++;
        }

        // Драйвер зображень перевіряємо окремо: Requirements лише каже, які
        // взагалі доступні, а тут важливо, чи є саме той, що вибраний у конфізі.
        $driver = (string) $config->get('photos.driver', 'gd');

        if (in_array($driver, $requirements->availableImageDrivers(), true)) {
            $this->cli->success('Обробка зображень із конфігу — ' . $driver);
        } else {
            $this->cli->error(
                'У налаштуваннях вибрано photos.driver = ' . $driver
                . ', але відповідне розширення PHP не підключене. Доступні: '
                . (implode(', ', $requirements->availableImageDrivers()) ?: 'жодного')
            );
            $problems++;
        }

        // --- Моделі даних ---------------------------------------------------
        //  Без них сторінки обʼєктів показуватимуть службові назви полів
        //  замість підписів, тому стан моделей перевіряємо окремо.
        $this->cli->title('Моделі даних обʼєкта');

        $models = new ModelStore($config);

        foreach ([ModelStore::PARAMETERS, ModelStore::CHARACTERISTICS] as $name) {
            if ($models->get($name) === []) {
                $this->cli->error(
                    'Модель "' . $name . '" не завантажена. Виконайте:'
                    . ' php vendor/bin/plusestSite models --config=...'
                );
                $problems++;

                continue;
            }

            $this->cli->success(
                'Модель "' . $name . '": полів ' . count($models->get($name))
                . ', оновлена ' . $models->updatedAt($name)
                . ($models->isFresh($name) ? '' : ' (термін свіжості вийшов, оновиться наступним прогоном)')
            );
        }

        // --- База даних -----------------------------------------------------
        $this->cli->title('База даних');

        $db = new Db((array) $config->get('db', []));

        try {
            $version = $db->fetchValue('SELECT VERSION()');
            $this->cli->success('Підключення встановлено. MySQL ' . $version);

            $migrator = new Migrator($db);
            $missing = $migrator->missingTables();

            if ($missing === []) {
                $objects = (int) $db->fetchValue('SELECT COUNT(*) FROM {objects}');
                $staff = (int) $db->fetchValue('SELECT COUNT(*) FROM {staff}');

                $this->cli->success('Таблиці на місці. Публікацій: ' . $objects . ', співробітників: ' . $staff);
            } else {
                $this->cli->error(
                    'Немає таблиць: ' . implode(', ', $missing)
                    . '. Виконайте: php vendor/bin/plusestSite migrate --config=...'
                );
                $problems++;
            }
        } catch (\Exception $e) {
            $this->cli->error($e->getMessage());
            $problems++;
        }

        // --- Підсумок -------------------------------------------------------
        $this->cli->line('');

        if ($problems === 0) {
            $this->cli->success('Усе готово до синхронізації.');

            return 0;
        }

        $this->cli->error('Знайдено проблем: ' . $problems);

        return 1;
    }

    /**
     * Завантажує конфіг за шляхом з --config або з типових місць.
     *
     * @return Config
     *
     * @throws ConfigException Якщо файл не знайдено
     */
    private function loadConfig()
    {
        $path = $this->cli->option('config');

        if (is_string($path) && $path !== '') {
            return Config::load($this->absolutePath($path));
        }

        // Опцію не передали — пробуємо знайти конфіг самостійно.
        $candidates = [
            Fs::join(getcwd(), 'config.php'),
            Fs::join(getcwd(), 'plusestSite', 'config.php'),
            Fs::join(getcwd(), 'plusest', 'config.php'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->cli->line('Використовую конфіг: ' . $candidate);

                return Config::load($candidate);
            }
        }

        throw new ConfigException(
            'Не вказано шлях до конфігу. Додайте опцію --config=/шлях/до/config.php' . "\n"
            . 'Шукали автоматично тут:' . "\n  " . implode("\n  ", $candidates)
        );
    }

    /**
     * Робить шлях абсолютним відносно поточного каталогу.
     *
     * @param string $path Шлях, можливо відносний
     *
     * @return string
     */
    private function absolutePath($path)
    {
        if (preg_match('~^(/|\\\\|[A-Za-z]:[\\\\/])~', $path)) {
            return $path;
        }

        return Fs::join(getcwd(), $path);
    }
}
