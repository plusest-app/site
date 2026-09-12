<?php

namespace Plusest\Site\Installer;

use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Fs;

/**
 * Ключ доступу до веб-установника.
 *
 * Задача, яку вирішує цей клас
 * ---------------------------
 * Установник — найнебезпечніший файл заготовки: він записує config.php і
 * підключається до бази даних за тими параметрами, які йому передали у формі.
 * Поки він лежить на сервері й система не налаштована, будь-хто, хто вгадає
 * адресу, може встановити рух першим і вказати свою базу.
 *
 * Класична схема (як у Joomla) — просто відмовлятись працювати, якщо конфіг
 * уже заповнений. Але між моментом, коли файли розпакували на сервер, і
 * моментом, коли адміністратор відкрив установник, залишається вікно.
 *
 * Тому ми робимо надійніше: при першому відкритті установник генерує
 * випадковий ключ і записує його у файл data/installKey.txt. Цей файл не
 * доступний з вебу — прочитати його можна лише тим самим FTP чи файловим
 * менеджером, яким користувач заливав архів. Ключ треба вставити у форму.
 *
 * Так зловмисник, який має лише HTTP-доступ, не пройде перший крок.
 *
 * Після успішного встановлення ключ видаляється разом із каталогом установника.
 */
class InstallKey
{
    /**
     * Назва файлу з ключем у службовому каталозі.
     */
    const FILE_NAME = 'installKey.txt';

    /**
     * Назва файлу-позначки, що встановлення завершене.
     */
    const LOCK_NAME = 'installed.lock';

    /**
     * Скільки байтів випадкових даних беремо для ключа.
     * 12 байтів у base32-подібному вигляді дають 20 символів — достатньо
     * стійко і при цьому реально передрукувати руками.
     */
    const KEY_BYTES = 12;

    /**
     * Службовий каталог, у якому лежить файл ключа.
     *
     * @var string
     */
    private $dataDir;

    /**
     * @param string $dataDir Шлях до каталогу data
     */
    public function __construct($dataDir)
    {
        $this->dataDir = $dataDir;
    }

    /**
     * Повертає наявний ключ або створює новий.
     *
     * @return string Ключ у вигляді 20 символів, розділених дефісами
     *
     * @throws SiteException Якщо каталог data недоступний для запису
     */
    public function ensure()
    {
        $existing = $this->read();

        if ($existing !== null) {
            return $existing;
        }

        $key = $this->generate();

        Fs::ensureDir($this->dataDir);
        Fs::writeAtomic($this->file(), $this->fileContents($key));

        return $key;
    }

    /**
     * Читає ключ із файлу.
     *
     * @return string|null Ключ або null, якщо файлу немає
     */
    public function read()
    {
        $file = $this->file();

        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        // У файлі є пояснювальний текст, сам ключ — у рядку після маркера.
        if (preg_match('~^KEY:\s*(\S+)\s*$~m', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Порівнює введений користувачем ключ зі збереженим.
     *
     * @param string $input Що ввели у формі
     *
     * @return bool
     */
    public function matches($input)
    {
        $stored = $this->read();

        if ($stored === null) {
            return false;
        }

        // Нормалізуємо ввід: людина може набрати ключ малими літерами,
        // з пробілами замість дефісів або скопіювати з зайвими пробілами.
        $input = $this->normalize($input);

        // hash_equals — порівняння за постійний час, без підбору посимвольно.
        return hash_equals($this->normalize($stored), $input);
    }

    /**
     * Чи вже завершене встановлення.
     *
     * @return bool
     */
    public function isInstalled()
    {
        return is_file(Fs::join($this->dataDir, self::LOCK_NAME));
    }

    /**
     * Ставить позначку, що встановлення завершене.
     *
     * @param array $details Що записати у файл-позначку: час, версія, підсумки
     *
     * @return void
     */
    public function markInstalled(array $details = [])
    {
        $details = ['installedAt' => date('c')] + $details;

        Fs::writeAtomic(
            Fs::join($this->dataDir, self::LOCK_NAME),
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Видаляє файл із ключем — після завершення встановлення він не потрібен.
     *
     * @return bool true, якщо файлу вже немає або він успішно видалений
     */
    public function forget()
    {
        $file = $this->file();

        if (!is_file($file)) {
            return true;
        }

        return @unlink($file);
    }

    /**
     * Повний шлях до файлу з ключем — установник показує його користувачу.
     *
     * @return string
     */
    public function file()
    {
        return Fs::join($this->dataDir, self::FILE_NAME);
    }

    /**
     * Генерує новий випадковий ключ.
     *
     * @return string Наприклад '7K3M-QP9X-2VBN-4HTZ-8RWC'
     *
     * @throws SiteException Якщо в системі немає джерела надійної випадковості
     */
    private function generate()
    {
        $bytes = $this->randomBytes(self::KEY_BYTES);

        // Алфавіт без символів, які легко сплутати при передруковуванні:
        // немає 0/O, 1/I/L, U/V. Лишається 26 символів.
        $alphabet = '23456789ABCDEFGHJKMNPQRSTWXYZ';
        $length = strlen($alphabet);

        $key = '';

        for ($i = 0; $i < strlen($bytes); $i++) {
            $key .= $alphabet[ord($bytes[$i]) % $length];
        }

        // Розбиваємо на групи по 4 символи — так ключ легше прочитати з екрана.
        return implode('-', str_split($key, 4));
    }

    /**
     * Випадкові байти з урахуванням того, що PHP 7.3 має random_bytes,
     * але на екзотичних збірках він може бути недоступний.
     *
     * @param int $length Скільки байтів потрібно
     *
     * @return string
     *
     * @throws SiteException Якщо надійного джерела випадковості немає
     */
    private function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (\Exception $e) {
                // Провалюємось до наступного варіанта.
            }
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);

            if ($bytes !== false && $strong) {
                return $bytes;
            }
        }

        throw new SiteException(
            'У системі немає надійного джерела випадкових чисел '
            . '(потрібна функція random_bytes або розширення openssl).'
        );
    }

    /**
     * Прибирає з ключа все, крім літер і цифр, і приводить до верхнього регістра.
     *
     * @param string $key Ключ у будь-якому вигляді
     *
     * @return string
     */
    private function normalize($key)
    {
        return strtoupper(preg_replace('~[^A-Za-z0-9]~', '', (string) $key));
    }

    /**
     * Вміст файлу з ключем: сам ключ плюс пояснення, щоб людина, яка відкриє
     * файл через FTP, одразу зрозуміла, що це і що з ним робити.
     *
     * @param string $key Ключ
     *
     * @return string
     */
    private function fileContents($key)
    {
        return "Ключ доступу до установника Plusest Site\n"
            . "========================================\n\n"
            . "Скопіюйте рядок після \"KEY:\" і вставте його у форму установника\n"
            . "у браузері. Ключ підтверджує, що установку робить власник сайту,\n"
            . "а не випадковий відвідувач, який вгадав адресу.\n\n"
            . "KEY: " . $key . "\n\n"
            . "Створено: " . date('Y-m-d H:i:s') . "\n"
            . "Після завершення встановлення цей файл видаляється автоматично.\n";
    }
}
