<?php

namespace Plusest\Site\Sync;

use Plusest\Site\Support\Fs;

/**
 * Блокування, щоб два прогони синхронізації не накладались.
 *
 * Ситуація, від якої захищаємось: cron запускає синхронізацію кожні 30 хвилин,
 * а перший прогін на великій базі триває годину. Без блокування другий прогін
 * почне качати ті самі фотографії паралельно з першим — і замість користі
 * отримаємо подвійне навантаження та зіпсовані файли.
 *
 * Використовується flock: він працює і на Linux, і на Windows, знімається
 * автоматично при завершенні процесу — навіть якщо той упав з фатальною
 * помилкою, і не залишає «залиплого» блокування.
 *
 * Використання:
 *
 *     $lock = new Lock($dataDir . '/sync.lock');
 *
 *     if (!$lock->acquire()) {
 *         echo 'Синхронізація вже виконується';
 *         exit(0);
 *     }
 *
 *     // ... робота ...
 *
 *     $lock->release();
 */
class Lock
{
    /**
     * Шлях до файлу блокування.
     *
     * @var string
     */
    private $file;

    /**
     * Відкритий дескриптор файлу, поки блокування утримується.
     *
     * @var resource|null
     */
    private $handle;

    /**
     * @param string $file Шлях до lock-файлу
     */
    public function __construct($file)
    {
        $this->file = $file;
    }

    /**
     * Намагається взяти блокування.
     *
     * Не чекає: якщо блокування вже комусь належить, одразу повертає false.
     * Для cron це правильна поведінка — черга з прогонів нікому не потрібна.
     *
     * @return bool true, якщо блокування отримано
     */
    public function acquire()
    {
        Fs::ensureDir(dirname($this->file));

        $handle = @fopen($this->file, 'c');

        if ($handle === false) {
            // Файл не відкривається — найімовірніше немає прав на каталог.
            // Не блокуємо роботу через це: краще виконати синхронізацію без
            // захисту, ніж не виконати зовсім.
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Записуємо, хто саме тримає блокування — допомагає розібратись,
        // якщо процес завис і його треба знайти в списку процесів.
        ftruncate($handle, 0);
        fwrite($handle, 'pid=' . getmypid() . ' started=' . date('Y-m-d H:i:s') . "\n");
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    /**
     * Знімає блокування.
     *
     * Викликати вручну не обовʼязково: операційна система знімає flock при
     * завершенні процесу. Але явний виклик робить намір видимим у коді.
     *
     * @return void
     */
    public function release()
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }

    /**
     * Хто тримає блокування зараз.
     *
     * @return string|null Рядок із pid і часом запуску, або null
     */
    public function owner()
    {
        if (!is_file($this->file)) {
            return null;
        }

        // На Windows файл під блокуванням прочитати не вийде — це нормально,
        // просто повертаємо null замість тексту.
        $contents = @file_get_contents($this->file);

        return $contents === false || trim($contents) === '' ? null : trim($contents);
    }

    /**
     * Знімає блокування при знищенні обʼєкта — на випадок раннього return
     * десь у коді синхронізації.
     */
    public function __destruct()
    {
        $this->release();
    }
}
