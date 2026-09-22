<?php

namespace Plusest\Site\Console;

use Plusest\Site\Config;
use Plusest\Site\Exception\ConfigException;
use Plusest\Site\Model\ModelStore;
use Plusest\Site\Support\Fs;
use Plusest\Site\Sync\Ban;
use Plusest\Site\Sync\Schedule;
use Plusest\Site\Sync\StateStore;
use Plusest\Site\Sync\Synchronizer;

/**
 * Команди sync і state.
 *
 *   sync  — виконує прогін синхронізації з CRM;
 *   state — показує, коли синхронізація виконувалась останній раз, з якими
 *           підсумками і чи були помилки.
 *
 * Ті самі дії робить скрипт bin/sync.php у проєкті користувача. Різниця лише
 * в тому, звідки їх зручніше запускати: цю команду — при налагодженні, а
 * bin/sync.php — із cron, бо для нього не потрібно знати шлях до vendor.
 */
class SyncCommand
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
     * Виконує синхронізацію.
     *
     * @return int Код виходу
     */
    public function run()
    {
        $config = $this->loadConfig();

        $this->cli->title('Синхронізація з CRM');

        $synchronizer = new Synchronizer($config);
        $cli = $this->cli;

        // Підписуємось на хід роботи, щоб було видно, що відбувається.
        $synchronizer->logger()->setOutput(function ($message, $level) use ($cli) {
            if ($level === 'error') {
                $cli->error($message);

                return;
            }

            if ($level === 'warn') {
                $cli->warn($message);

                return;
            }

            $cli->line('  ' . $message);
        });

        // --force змушує запитати фід негайно, не чекаючи розкладу.
        $result = $synchronizer->run((bool) $this->cli->option('force', false));

        $this->cli->line('');

        if ($result->hasErrors()) {
            $this->cli->warn('Прогін завершено, але були помилки на окремих обʼєктах:');

            foreach ($result->errors() as $error) {
                $this->cli->line('    - ' . $error);
            }

            return 1;
        }

        $this->cli->success('Прогін завершено за ' . $result->duration() . ' с.');

        return 0;
    }

    /**
     * Показує стан останньої синхронізації.
     *
     * @return int Код виходу
     */
    public function state()
    {
        $config = $this->loadConfig();

        $synchronizer = new Synchronizer($config);
        $db = $synchronizer->db();
        $state = new StateStore($db);

        $this->cli->title('Стан синхронізації');

        // Про остаточне блокування кажемо першим рядком: без цього дані нижче
        // виглядають просто застарілими, і незрозуміло, чому вони не
        // оновлюються.
        $ban = Ban::fromConfig($config);

        if ($ban->exists()) {
            $reason = $ban->reason();

            $this->cli->warn('Синхронізацію вимкнено: CRM закрила доступ агентству назавжди'
                . ($reason === null ? '' : ' (' . $reason . ')') . '.');
            $this->cli->line('Файл блокування: ' . $ban->file());
            $this->cli->line('Видаліть його, якщо доступ до CRM відновили.');
            $this->cli->line('');
        }

        // Час і пояс — першими після можливого блокування: усі значення нижче
        // записані в цьому ж поясі, і саме з ними їх порівнюють із логами CRM.
        // Розбіжність тут — найчастіша причина питання «чому розклад поїхав».
        $this->cli->line('Час сервера:     ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')');

        $lastSync = $state->get(StateStore::LAST_SYNC_AT);

        if ($lastSync === null) {
            $this->cli->warn('Синхронізація ще не виконувалась.');
        } else {
            $this->cli->line('Останній прогін: ' . $lastSync);

            // Час звернення до фіда показуємо окремо: він може бути помітно
            // старішим за прогін, бо до CRM звертаємось за розкладом, а
            // прогони між тим продовжують працювати з фотографіями.
            $lastFeed = $state->get(StateStore::LAST_FEED_AT);

            $this->cli->line('Запит фіда:      ' . ($lastFeed === null ? 'ще не було' : $lastFeed));
            $this->cli->line('Наступний:       ' . $this->nextFeedText($config, $state));

            $summary = $state->getArray(StateStore::LAST_SYNC_RESULT);

            // Показуємо лише те, де щось відбувалось: два десятки нульових
            // лічильників читати важко, а нуль і так означає «нічого».
            // Час виконання і кількість помилок показуємо завжди.
            foreach ($summary as $name => $value) {
                if ((float) $value === 0.0 && !in_array($name, ['duration', 'errors'], true)) {
                    continue;
                }

                $this->cli->line('  ' . $this->cli->pad($name, 28) . $value);
            }
        }

        // --- Що зараз у базі ------------------------------------------------
        $this->cli->title('Локальна база');

        $counts = $db->fetchAll('SELECT status, COUNT(*) AS total FROM {objects} GROUP BY status');

        if ($counts === []) {
            $this->cli->line('Публікацій ще немає.');
        } else {
            foreach ($counts as $row) {
                $this->cli->line('  ' . $this->cli->pad($row['status'], 18) . $row['total']);
            }
        }

        $this->cli->line('  ' . $this->cli->pad('співробітників', 18)
            . $db->fetchValue('SELECT COUNT(*) FROM {staff}'));

        // --- Помилки --------------------------------------------------------
        $error = $state->get(StateStore::LAST_ERROR);

        if ($error !== null) {
            $this->cli->title('Остання помилка');
            $this->cli->line('Час: ' . $state->get(StateStore::LAST_ERROR_AT));
            $this->cli->line($error);
        }

        // --- Останні рядки логу ---------------------------------------------
        $logFile = $config->path('paths.data', 'sync.log');

        if (is_file($logFile)) {
            $this->cli->title('Останні рядки логу');

            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach (array_slice($lines === false ? [] : $lines, -15) as $line) {
                $this->cli->line('  ' . $line);
            }

            $this->cli->line('');
            $this->cli->line('Повний лог: ' . $logFile);
        }

        return 0;
    }

    /**
     * Опис того, коли скрипт наступного разу звернеться до CRM.
     *
     * @param Config     $config Налаштування
     * @param StateStore $state  Службові значення
     *
     * @return string
     */
    private function nextFeedText(Config $config, StateStore $state)
    {
        $decision = Schedule::fromConfig($config)->due(
            $this->stateTime($state, StateStore::LAST_FEED_AT),
            $this->stateTime($state, StateStore::FEED_RETRY_AFTER)
        );

        if ($decision['due']) {
            return 'наступним прогоном cron';
        }

        $wait = 'через ' . Schedule::formatDuration($decision['wait']);

        return $decision['reason'] === Schedule::REASON_ERROR
            ? $wait . ' — CRM відмовила у видачі даних'
            : $wait . ' (інтервал зараз ' . Schedule::formatDuration($decision['interval']) . ')';
    }

    /**
     * Читає з syncState значення-час як unix-час.
     *
     * @param StateStore $state Службові значення
     * @param string     $name  Назва значення
     *
     * @return int|null
     */
    private function stateTime(StateStore $state, $name)
    {
        $value = $state->get($name);

        if ($value === null) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * Команда models — оновлює моделі даних обʼєкта.
     *
     * Ті самі дві JSON-моделі, які підтягує звичайний прогін синхронізації
     * раз на добу (ModelStore::TTL). Окрема команда потрібна у двох випадках:
     * одразу після встановлення, щоб не чекати першого прогону cron, і коли
     * в CRM додали нове поле, а показувати його треба вже зараз.
     *
     * @return int Код виходу
     */
    public function models()
    {
        $config = $this->loadConfig();
        $models = new ModelStore($config);

        $this->cli->title('Моделі даних обʼєкта');

        // Без --force свіжі копії не перезавантажуються: моделі змінюються
        // рідко, а зайвий запит до plusest.app нікому не потрібен.
        $force = (bool) $this->cli->option('force', false);
        $problems = 0;

        foreach ($models->refresh($force) as $name => $status) {
            $file = $models->file($name);

            if ($status === 'downloaded') {
                $this->cli->success('Завантажено: ' . $name . ' -> ' . $file);

                continue;
            }

            if ($status === 'fresh') {
                $this->cli->line('  = ' . $name . ' — копія свіжа (від '
                    . $models->updatedAt($name) . '). Щоб оновити, додайте --force');

                continue;
            }

            $this->cli->error($name . ': ' . $status);
            $problems++;
        }

        if ($problems > 0) {
            return 1;
        }

        return 0;
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
            if (!preg_match('~^(/|\\\\|[A-Za-z]:[\\\\/])~', $path)) {
                $path = Fs::join(getcwd(), $path);
            }

            return Config::load($path);
        }

        $candidates = [
            Fs::join(getcwd(), 'config.php'),
            Fs::join(getcwd(), 'plusestSite', 'config.php'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->cli->line('Використовую конфіг: ' . $candidate);

                return Config::load($candidate);
            }
        }

        throw new ConfigException(
            'Не вказано шлях до конфігу. Додайте опцію --config=/шлях/до/config.php'
        );
    }
}
