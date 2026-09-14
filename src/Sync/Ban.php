<?php

namespace Plusest\Site\Sync;

use Exception;
use Plusest\Site\Config;
use Plusest\Site\Support\Fs;

/**
 * Позначка «CRM закрила доступ агентству назавжди».
 *
 * CRM може заблокувати агентство остаточно: у відповіді на запит фіда приходить
 * status = error і data.reason = 'Banned forever'. Повторний запит нічого не
 * змінить ніколи, тому синхронізація має не просто відкласти наступну спробу
 * (як після звичайної відмови), а припинити звертатись до CRM зовсім — інакше
 * кожні п'ятнадцять хвилин на її сервер летітимуть запити, які завжди
 * повертатимуть ту саму відмову.
 *
 * Позначка — це файл у службовому каталозі (paths.data). Поки він існує, прогін
 * синхронізації завершується одразу. Знімається вручну: досить видалити файл.
 *
 * Використання:
 *
 *     $ban = Ban::fromConfig($config);
 *
 *     if ($ban->exists()) {
 *         echo 'Синхронізацію вимкнено: ' . $ban->reason();
 *         exit(0);
 *     }
 */
class Ban
{
    /**
     * Назва файлу-позначки у службовому каталозі.
     */
    const FILE = 'disallowed.lock';

    /**
     * Шлях до файлу-позначки.
     *
     * @var string
     */
    private $file;

    /**
     * @param string $file Шлях до файлу-позначки
     */
    public function __construct($file)
    {
        $this->file = (string) $file;
    }

    /**
     * Створює позначку за налаштуваннями.
     *
     * Шлях беремо саме з конфігу, а не рахуємо від __DIR__: заготовка буває
     * і самостійним проєктом із ZIP-архіву, і підкаталогом чужого проєкту,
     * встановленим через Composer. Каталог paths.data у цих випадках лежить
     * у різних місцях, і знає про це лише config.php.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self($config->path('paths.data', self::FILE));
    }

    /**
     * Чи закритий доступ до CRM назавжди.
     *
     * @return bool
     */
    public function exists()
    {
        return is_file($this->file);
    }

    /**
     * Причина, записана у файл.
     *
     * @return string|null Перший рядок файлу або null, якщо позначки немає
     *                     чи прочитати її не вдалося
     */
    public function reason()
    {
        if (!$this->exists()) {
            return null;
        }

        $contents = @file_get_contents($this->file);

        if ($contents === false) {
            return null;
        }

        $lines = preg_split('~\R~', trim($contents));
        $reason = $lines === false ? '' : trim($lines[0]);

        return $reason === '' ? null : $reason;
    }

    /**
     * Ставить позначку.
     *
     * Помилку запису не кидає далі: втрата позначки не привід ламати прогін,
     * але про неї треба сказати гучно — тому повертаємо false, і викликач
     * пише про це в лог.
     *
     * @param string $reason Причина від CRM, наприклад 'Banned forever'
     *
     * @return bool true, якщо файл записано
     */
    public function set($reason)
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            $reason = 'banned';
        }

        // Перший рядок читається програмою, решта — пояснення для людини,
        // яка знайде цей файл у службовому каталозі й не зрозуміє, що це.
        $contents = $reason . "\n"
            . 'Створено: ' . date('Y-m-d H:i:s') . "\n"
            . 'CRM закрила доступ агентству назавжди.' . "\n"
            . 'Поки цей файл існує, синхронізація не виконується і запити до CRM не надсилаються.' . "\n"
            . 'Якщо доступ відновили — видаліть файл, і синхронізація запрацює наступним прогоном cron.' . "\n";

        try {
            Fs::writeAtomic($this->file, $contents);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Знімає позначку.
     *
     * Потрібно, якщо доступ до CRM відновили: користувач може видалити файл
     * руками, а може зробити це з коду.
     *
     * @return bool true, якщо позначки більше немає
     */
    public function lift()
    {
        if (!$this->exists()) {
            return true;
        }

        return @unlink($this->file);
    }

    /**
     * Шлях до файлу-позначки — щоб показати його користувачу.
     *
     * @return string
     */
    public function file()
    {
        return $this->file;
    }
}
