<?php

namespace Plusest\Site;

/**
 * Збірка сводки про те, з чого склався час відповіді сторінки.
 *
 * Навіщо це потрібно
 * ------------------
 * Сторінка бази обʼєктів робить близько двох десятків запитів до MySQL:
 * саму вибірку, підрахунок кількості, а потім по запиту на кожен список
 * фільтра. Коли сторінка починає відповідати секундами, з боку неможливо
 * сказати, де саме час: у базі, у рендерингу шаблонів чи в читанні моделей
 * даних з диска. Сводка відповідає на це питання цифрами.
 *
 * Як увімкнути
 * ------------
 * У config.php:
 *
 *     'debug' => [
 *         'summary' => true,
 *     ],
 *
 * Після цього в кінці кожної сторінки, після </html>, зʼявляється блок
 * HTML-комментаря зі сводкою. Відвідувач її не бачить — вона видна лише в
 * початковому коді сторінки (Ctrl+U) і в curl.
 *
 * Чому в комментарі, а не окремою панеллю
 * ---------------------------------------
 * Заготовка вставляється у чужий сайт із чужою верствою. Панель, прибита до
 * низу екрана, з великою ймовірністю зʼїхала б або перекрила чиєсь меню, а
 * комментар не може зламати нічого. До того ж він потрапляє у відповідь
 * навіть тоді, коли сторінка впала з помилкою 500.
 *
 * На робочому сайті вимикайте
 * ---------------------------
 * Сводка містить текст SQL-запитів і структуру сторінки. Показувати це
 * стороннім не потрібно, тому 'summary' за замовчуванням false.
 *
 * Як користуватись у коді
 * -----------------------
 *     $debug = Debug::fromConfig($config);
 *     $debug->mark('завантаження');      // етап завершено
 *     ...
 *     echo $html . $debug->summary();
 *
 * Кожен mark() закриває етап: у сводку йде час від попереднього mark() (або
 * від початку запиту) до цього. Запити до бази записує сам клас Db — йому
 * достатньо передати цей обʼєкт через Db::watch().
 */
class Debug
{
    /**
     * Скільки запитів записувати. Далі рахуємо лише час і кількість.
     *
     * Обмеження на випадок циклу, який робить запит на кожен обʼєкт: сводка
     * з десяти тисяч рядків не допоможе, а памʼять зʼїсть.
     */
    const MAX_QUERIES = 200;

    /**
     * До якої довжини вкорочувати текст запиту у сводці.
     */
    const MAX_SQL = 400;

    /**
     * Чи ввімкнений збір сводки.
     *
     * @var bool
     */
    private $enabled;

    /**
     * Початок відліку — час, коли PHP почав обробляти запит.
     *
     * @var float
     */
    private $startedAt;

    /**
     * Час завершення попереднього етапу.
     *
     * @var float
     */
    private $lastAt;

    /**
     * Етапи: ['label' => назва, 'own' => свій час, 'total' => від початку].
     *
     * @var array[]
     */
    private $stages = [];

    /**
     * Запити до бази: ['sql', 'bindings', 'ms', 'rows'].
     *
     * @var array[]
     */
    private $queries = [];

    /**
     * Скільки запитів виконано всього — разом із тими, що не записані.
     *
     * @var int
     */
    private $queryCount = 0;

    /**
     * Скільки часу пішло на всі запити разом, мілісекунди.
     *
     * @var float
     */
    private $queryMs = 0.0;

    /**
     * Скільки часу пішло на підключення до MySQL, мілісекунди.
     *
     * @var float|null
     */
    private $connectMs;

    /**
     * Довільні позначки для шапки сводки: адреса, мова, кількість обʼєктів.
     *
     * @var array
     */
    private $notes = [];

    /**
     * @param bool $enabled Чи збирати сводку
     */
    public function __construct($enabled = false)
    {
        $this->enabled = (bool) $enabled;

        // REQUEST_TIME_FLOAT враховує ще й час автозавантаження Composer та
        // читання конфігу — а це теж частина відповіді сторінки.
        $this->startedAt = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $this->lastAt = $this->startedAt;
    }

    /**
     * Створює збирач із налаштувань.
     *
     * @param Config $config Налаштування
     *
     * @return self
     */
    public static function fromConfig(Config $config)
    {
        return new self($config->get('debug.summary', false));
    }

    /**
     * Чи збирається сводка.
     *
     * Db звертається до цього методу, щоб не викликати microtime() на
     * кожному запиті, коли налагодження вимкнене.
     *
     * @return bool
     */
    public function enabled()
    {
        return $this->enabled;
    }

    /**
     * Закриває черговий етап формування сторінки.
     *
     * @param string $label Назва етапу
     *
     * @return void
     */
    public function mark($label)
    {
        if (!$this->enabled) {
            return;
        }

        $now = microtime(true);

        $this->stages[] = [
            'label' => (string) $label,
            'own'   => ($now - $this->lastAt) * 1000,
            'total' => ($now - $this->startedAt) * 1000,
        ];

        $this->lastAt = $now;
    }

    /**
     * Записує виконаний запит до бази.
     *
     * @param string $sql      Текст запиту
     * @param array  $bindings Значення заповнювачів
     * @param float  $seconds  Скільки він тривав
     * @param int    $rows     Скільки рядків зачепив
     *
     * @return void
     */
    public function query($sql, array $bindings, $seconds, $rows = 0)
    {
        if (!$this->enabled) {
            return;
        }

        $this->queryCount++;
        $this->queryMs += $seconds * 1000;

        if (count($this->queries) >= self::MAX_QUERIES) {
            return;
        }

        $this->queries[] = [
            'sql'      => $sql,
            'bindings' => $bindings,
            'ms'       => $seconds * 1000,
            'rows'     => (int) $rows,
        ];
    }

    /**
     * Записує час підключення до MySQL.
     *
     * Окремо від запитів: підключення відбувається один раз, і коли база
     * стоїть на іншому сервері, саме воно буває найдорожчою частиною.
     *
     * @param float $seconds Скільки тривало підключення
     *
     * @return void
     */
    public function connected($seconds)
    {
        if (!$this->enabled) {
            return;
        }

        $this->connectMs = $seconds * 1000;
    }

    /**
     * Додає рядок у шапку сводки.
     *
     * @param string $label Назва
     * @param mixed  $value Значення
     *
     * @return void
     */
    public function note($label, $value)
    {
        if (!$this->enabled) {
            return;
        }

        $this->notes[(string) $label] = $value;
    }

    /**
     * Готова сводка у вигляді HTML-комментаря.
     *
     * @return string Порожній рядок, якщо збір вимкнений
     */
    public function summary()
    {
        if (!$this->enabled) {
            return '';
        }

        $totalMs = (microtime(true) - $this->startedAt) * 1000;

        $lines = ['plusest debug'];
        $lines[] = '';

        foreach ($this->notes as $label => $value) {
            $lines[] = '  ' . $label . ': ' . $this->scalar($value);
        }

        $lines[] = '  усього: ' . $this->ms($totalMs);

        $sql = '  база: ' . $this->ms($this->queryMs) . ' у ' . $this->queryCount . ' запитах';

        if ($this->connectMs !== null) {
            $sql .= ', підключення ' . $this->ms($this->connectMs);
        }

        $lines[] = $sql;
        $lines[] = '  php: ' . $this->ms($totalMs - $this->queryMs);
        $lines[] = '  памʼять: ' . $this->bytes(memory_get_usage(true))
            . ', пік ' . $this->bytes(memory_get_peak_usage(true));

        // --- Етапи ----------------------------------------------------------
        if ($this->stages !== []) {
            $lines[] = '';
            $lines[] = '  етапи (свій час | від початку)';

            foreach ($this->stages as $stage) {
                $lines[] = '    ' . $this->column($this->ms($stage['own']), 12)
                    . ' | ' . $this->column($this->ms($stage['total']), 12)
                    . '  ' . $stage['label'];
            }
        }

        // --- Запити ---------------------------------------------------------
        if ($this->queries !== []) {
            $lines[] = '';

            // Кількість рядків беремо з PDOStatement::rowCount(). Для
            // SELECT це значення обіцяне не всіма драйверами, і в MySQL
            // воно правдиве лише для буферизованих запитів — тобто для
            // наших. Якщо колись побачите тут 0 навпроти SELECT, вірте
            // часу, а не кількості.
            $lines[] = '  запити (час | рядків за rowCount)';

            foreach ($this->queries as $number => $query) {
                $lines[] = '    ' . $this->column(($number + 1) . '.', 5)
                    . $this->column($this->ms($query['ms']), 12)
                    . ' | ' . $this->column($query['rows'], 7)
                    . '  ' . $this->sql($query['sql'], $query['bindings']);
            }

            if ($this->queryCount > count($this->queries)) {
                $lines[] = '    ... і ще ' . ($this->queryCount - count($this->queries))
                    . ' запитів — у сводку не пішли';
            }
        }

        return "\n<!--\n" . $this->comment(implode("\n", $lines)) . "\n-->\n";
    }

    /**
     * Текст запиту разом зі значеннями заповнювачів, в один рядок.
     *
     * @param string $sql      Запит
     * @param array  $bindings Значення заповнювачів
     *
     * @return string
     */
    private function sql($sql, array $bindings)
    {
        // Запити в коді розбиті на кілька рядків для читабельності, а тут
        // потрібен один рядок на запит — інакше сводку неможливо читати.
        $sql = trim(preg_replace('~\s+~', ' ', (string) $sql));

        if (strlen($sql) > self::MAX_SQL) {
            $sql = substr($sql, 0, self::MAX_SQL) . '…';
        }

        if ($bindings === []) {
            return $sql;
        }

        $values = [];

        foreach ($bindings as $value) {
            $values[] = $this->scalar($value);
        }

        return $sql . '   [' . implode(', ', $values) . ']';
    }

    /**
     * Значення заповнювача в короткому вигляді.
     *
     * @param mixed $value Значення
     *
     * @return string
     */
    private function scalar($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return '[' . count($value) . ']';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        $value = (string) $value;

        return strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
    }

    /**
     * Час у мілісекундах з однаковою кількістю знаків.
     *
     * @param float $ms Мілісекунди
     *
     * @return string
     */
    private function ms($ms)
    {
        return number_format((float) $ms, 1, '.', '') . ' ms';
    }

    /**
     * Обʼєм памʼяті у мегабайтах.
     *
     * @param int $bytes Байти
     *
     * @return string
     */
    private function bytes($bytes)
    {
        return number_format($bytes / 1048576, 1, '.', '') . ' MB';
    }

    /**
     * Вирівнює значення по правому краю стовпця заданої ширини.
     *
     * Стовпці зі значеннями — завжди ASCII (числа й 'ms'), тому str_pad
     * рахує символи правильно. Назви етапів і текст запитів стоять останніми
     * в рядку саме тому, що вирівнювати їх довелось би з урахуванням
     * багатобайтових символів.
     *
     * @param string|int $value Значення
     * @param int        $width Ширина стовпця
     *
     * @return string
     */
    private function column($value, $width)
    {
        return str_pad((string) $value, $width, ' ', STR_PAD_LEFT);
    }

    /**
     * Готує текст до вставки всередину HTML-комментаря.
     *
     * Послідовність «--» закриває комментар у деяких старих парсерах, а
     * «-->» — у всіх. Тексту запитів і значенням із адреси довіряти не
     * можемо, тому розриваємо будь-яку пару дефісів. Символи керування
     * прибираємо: у сводці їм нема місця, а вивід вони псують.
     *
     * @param string $text Текст сводки
     *
     * @return string
     */
    private function comment($text)
    {
        // Без модифікатора /u: діапазон нижче не перетинається з байтами
        // багатобайтових символів UTF-8, зате регулярний вираз не впаде на
        // зіпсованому рядку.
        $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', (string) $text);

        return str_replace(['--', '<!'], ['- -', '< !'], $text);
    }
}
