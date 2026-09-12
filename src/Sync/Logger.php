<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Support\Fs;

/**
 * Запис ходу синхронізації.
 *
 * Пише одночасно у два місця:
 *   * у файл data/sync.log — щоб потім розібратись, що сталося вночі;
 *   * у переданий обробник — щоб при запуску руками все було видно в терміналі.
 *
 * Файл не росте безкінечно: коли рядків стає помітно більше за ліміт із
 * налаштувань, старі відкидаються. Обрізка робиться не на кожен запис, а лише
 * при закритті логу — інакше на кожному рядку перечитувався б увесь файл.
 */
class Logger
{
    /**
     * Шлях до файлу логу або null, якщо запис у файл вимкнений.
     *
     * @var string|null
     */
    private $file;

    /**
     * Скільки рядків зберігати у файлі.
     *
     * @var int
     */
    private $maxLines;

    /**
     * Обробник для виводу в термінал.
     *
     * @var callable|null
     */
    private $output;

    /**
     * Відкритий дескриптор файлу.
     *
     * @var resource|null
     */
    private $handle;

    /**
     * Скільки рядків записано за цей прогін.
     *
     * @var int
     */
    private $written = 0;

    /**
     * @param string|null $file     Шлях до файлу логу, null — не писати у файл
     * @param int         $maxLines Скільки рядків зберігати
     */
    public function __construct($file = null, $maxLines = 5000)
    {
        $this->file = $file;
        $this->maxLines = max(100, (int) $maxLines);
    }

    /**
     * Призначає обробник для виводу в термінал.
     *
     * @param callable|null $output Функція, що приймає рядок
     *
     * @return $this
     */
    public function setOutput($output)
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Записує рядок.
     *
     * @param string $message Текст
     * @param string $level   Позначка рівня: info, warn, error
     *
     * @return void
     */
    public function write($message, $level = 'info')
    {
        $message = trim((string) $message);

        if ($message === '') {
            return;
        }

        if ($this->output !== null) {
            call_user_func($this->output, $message, $level);
        }

        if ($this->file === null) {
            return;
        }

        if ($this->handle === null) {
            Fs::ensureDir(dirname($this->file));
            $this->handle = @fopen($this->file, 'a');

            // Не можемо писати у файл — просто продовжуємо без нього.
            // Синхронізація важливіша за лог.
            if ($this->handle === false) {
                $this->handle = null;
                $this->file = null;

                return;
            }
        }

        $line = date('Y-m-d H:i:s') . ' [' . $level . '] ' . $message . "\n";

        // LOCK_EX на випадок, коли лог одночасно пише веб-запуск і cron.
        flock($this->handle, LOCK_EX);
        fwrite($this->handle, $line);
        flock($this->handle, LOCK_UN);

        $this->written++;
    }

    /**
     * Попередження — щось пропущено, але робота продовжується.
     *
     * @param string $message Текст
     *
     * @return void
     */
    public function warn($message)
    {
        $this->write($message, 'warn');
    }

    /**
     * Помилка.
     *
     * @param string $message Текст
     *
     * @return void
     */
    public function error($message)
    {
        $this->write($message, 'error');
    }

    /**
     * Закриває файл і обрізає його, якщо він розріс понад ліміт.
     *
     * @return void
     */
    public function close()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        $this->trim();
    }

    /**
     * Скільки рядків записано за цей прогін.
     *
     * @return int
     */
    public function written()
    {
        return $this->written;
    }

    /**
     * Останні рядки логу — для показу на сторінці стану.
     *
     * @param int $lines Скільки рядків повернути
     *
     * @return string[]
     */
    public function tail($lines = 50)
    {
        if ($this->file === null || !is_file($this->file)) {
            return [];
        }

        $all = @file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($all === false) {
            return [];
        }

        return array_slice($all, -$lines);
    }

    /**
     * Обрізає файл логу до ліміту рядків.
     *
     * Читаємо файл повністю лише коли він справді розріс — із запасом у
     * півтора рази, щоб не перезаписувати його після кожного прогону.
     *
     * @return void
     */
    private function trim()
    {
        if ($this->file === null || !is_file($this->file)) {
            return;
        }

        $lines = @file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || count($lines) <= $this->maxLines * 1.5) {
            return;
        }

        $kept = array_slice($lines, -$this->maxLines);

        Fs::writeAtomic($this->file, implode("\n", $kept) . "\n");
    }
}
