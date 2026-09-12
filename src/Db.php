<?php

namespace Plusest\Site;

use PDO;
use PDOException;
use PDOStatement;
use Plusest\Site\Exception\DbException;

/**
 * Тонка обгортка над PDO для MySQL.
 *
 * Навмисно не ORM: усі запити пишуться звичайним SQL, щоб код залишався
 * прозорим і його легко було правити під власний сайт. Клас додає лише те,
 * без чого незручно:
 *
 *   * відкладене підключення (з'єднання створюється при першому запиті);
 *   * підстановку префікса таблиць — {objects} перетворюється у `prefix_objects`;
 *   * готові хелпери fetchAll/fetchRow/fetchValue/insert/upsert;
 *   * зрозумілі виключення замість «сирих» PDOException;
 *   * хронометраж запитів для сводки налагодження — див. watch() і Debug.
 *
 * Приклад:
 *
 *     $db = new Db($config->get('db'));
 *     $rows = $db->fetchAll('SELECT * FROM {objects} WHERE status = ?', ['active']);
 */
class Db
{
    /**
     * Налаштування підключення (секція db з config.php).
     *
     * @var array
     */
    private $settings;

    /**
     * Активне підключення або null, якщо ще не підключались.
     *
     * @var PDO|null
     */
    private $pdo;

    /**
     * Префікс назв таблиць.
     *
     * @var string
     */
    private $prefix;

    /**
     * Кількість виконаних запитів — корисно для налагодження на сторінці.
     *
     * @var int
     */
    private $queryCount = 0;

    /**
     * Збирач сводки налагодження або null, якщо вона не потрібна.
     *
     * @var Debug|null
     */
    private $debug;

    /**
     * @param array $settings Масив із ключами host, port, name, user, password,
     *                        charset, prefix, options
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->prefix = isset($settings['prefix']) ? (string) $settings['prefix'] : '';
    }

    /**
     * Просить записувати запити у сводку налагодження.
     *
     * Поки збирач не передали (а у скрипті синхронізації його немає взагалі),
     * запити не хронометруються: жодного microtime() на запит.
     *
     * @param Debug $debug Збирач сводки
     *
     * @return $this
     */
    public function watch(Debug $debug)
    {
        $this->debug = $debug->enabled() ? $debug : null;

        return $this;
    }

    /**
     * Повертає активне підключення PDO, створюючи його за потреби.
     *
     * @return PDO
     *
     * @throws DbException Якщо підключитись не вдалося
     */
    public function pdo()
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = isset($this->settings['host']) ? (string) $this->settings['host'] : '127.0.0.1';
        $port = isset($this->settings['port']) ? (int) $this->settings['port'] : 3306;
        $name = isset($this->settings['name']) ? (string) $this->settings['name'] : '';
        $user = isset($this->settings['user']) ? (string) $this->settings['user'] : '';
        $password = isset($this->settings['password']) ? (string) $this->settings['password'] : '';
        $charset = isset($this->settings['charset']) ? (string) $this->settings['charset'] : 'utf8mb4';
        $socket = isset($this->settings['socket']) ? (string) $this->settings['socket'] : '';

        // Якщо вказано unix-сокет — підключаємось через нього, інакше через host:port.
        if ($socket !== '') {
            $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=' . $charset;
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;
        }

        $options = [
            // Помилки — виключеннями: тихі false в результатах запитів нам не потрібні.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // За замовчуванням віддаємо асоціативні масиви.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Справжні підготовлені запити на боці MySQL, а не емуляція в PDO.
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Довгі рядки (колонка data з повним JSON об'єкта) не буферизуємо окремо.
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        // Користувач може додати або перекрити будь-яку опцію через db.options.
        if (isset($this->settings['options']) && is_array($this->settings['options'])) {
            $options = $this->settings['options'] + $options;
        }

        $startedAt = $this->debug === null ? 0.0 : microtime(true);

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new DbException(
                'Не вдалося підключитися до MySQL (' . $host . ':' . $port . ', база "' . $name . '"): '
                . $e->getMessage(),
                0,
                $e
            );
        }

        if ($this->debug !== null) {
            $this->debug->connected(microtime(true) - $startedAt);
        }

        return $this->pdo;
    }

    /**
     * Виконує запит і повертає підготовлений PDOStatement.
     *
     * У тексті запиту назви таблиць пишемо у фігурних дужках — {objects},
     * {staff}, {syncState}. Вони автоматично замінюються на назви з префіксом
     * і беруться в обернені лапки.
     *
     * @param string $sql      SQL із заповнювачами ? або :name
     * @param array  $bindings Значення для заповнювачів
     *
     * @return PDOStatement
     *
     * @throws DbException Якщо запит завершився помилкою
     */
    public function query($sql, array $bindings = [])
    {
        $sql = $this->expandTableNames($sql);

        try {
            // Підключення створюється до відліку часу: воно рахується окремо,
            // інакше весь його час дістався б першому ж запиту.
            $pdo = $this->pdo();

            $startedAt = $this->debug === null ? 0.0 : microtime(true);

            $statement = $pdo->prepare($sql);

            // Логічні значення PDO передає як 1/0 лише для цілих типів,
            // тому TINYINT(1) зручніше нормалізувати самим.
            foreach ($bindings as $key => $value) {
                if (is_bool($value)) {
                    $bindings[$key] = $value ? 1 : 0;
                }
            }

            $statement->execute($bindings);
            $this->queryCount++;

            if ($this->debug !== null) {
                $this->debug->query(
                    $sql,
                    $bindings,
                    microtime(true) - $startedAt,
                    $statement->rowCount()
                );
            }

            return $statement;
        } catch (PDOException $e) {
            throw new DbException(
                'Помилка SQL-запиту: ' . $e->getMessage() . "\nЗапит: " . $sql,
                0,
                $e
            );
        }
    }

    /**
     * Усі рядки результату.
     *
     * @param string $sql      SQL-запит
     * @param array  $bindings Значення для заповнювачів
     *
     * @return array[] Масив асоціативних масивів
     */
    public function fetchAll($sql, array $bindings = [])
    {
        return $this->query($sql, $bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Перший рядок результату або null.
     *
     * @param string $sql      SQL-запит
     * @param array  $bindings Значення для заповнювачів
     *
     * @return array|null
     */
    public function fetchRow($sql, array $bindings = [])
    {
        $row = $this->query($sql, $bindings)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Значення першого стовпця першого рядка або null.
     *
     * @param string $sql      SQL-запит
     * @param array  $bindings Значення для заповнювачів
     *
     * @return mixed|null
     */
    public function fetchValue($sql, array $bindings = [])
    {
        $value = $this->query($sql, $bindings)->fetchColumn(0);

        return $value === false ? null : $value;
    }

    /**
     * Одновимірний масив значень першого стовпця.
     *
     * Зручно, коли треба, наприклад, отримати список усіх objectPublicationId,
     * що вже є в базі.
     *
     * @param string $sql      SQL-запит
     * @param array  $bindings Значення для заповнювачів
     *
     * @return array
     */
    public function fetchColumn($sql, array $bindings = [])
    {
        return $this->query($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Виконує запит без вибірки і повертає кількість зачеплених рядків.
     *
     * @param string $sql      SQL-запит
     * @param array  $bindings Значення для заповнювачів
     *
     * @return int
     */
    public function execute($sql, array $bindings = [])
    {
        return $this->query($sql, $bindings)->rowCount();
    }

    /**
     * Вставляє рядок у таблицю.
     *
     * @param string $table Логічна назва таблиці без префікса, напр. 'objects'
     * @param array  $data  Масив «стовпець => значення»
     *
     * @return int Кількість вставлених рядків
     */
    public function insert($table, array $data)
    {
        if ($data === []) {
            throw new DbException('Спроба вставити порожній рядок у таблицю ' . $table);
        }

        $columns = array_keys($data);

        $sql = 'INSERT INTO {' . $table . '} ('
            . implode(', ', array_map([$this, 'quoteIdentifier'], $columns))
            . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?'))
            . ')';

        return $this->execute($sql, array_values($data));
    }

    /**
     * Вставляє рядок або оновлює наявний (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * Саме цей метод використовує синхронізація: вона не перевіряє наперед,
     * чи є публікація в базі, — просто пише її, а MySQL сам вирішує, це
     * вставка чи оновлення за первинним ключем.
     *
     * @param string   $table   Логічна назва таблиці без префікса
     * @param array    $data    Масив «стовпець => значення»
     * @param string[] $exclude Стовпці, які НЕ треба перезаписувати при оновленні
     *                          (типово createdAt — дата першої появи об'єкта)
     *
     * @return int Кількість зачеплених рядків: 1 — вставка, 2 — оновлення, 0 — без змін
     */
    public function upsert($table, array $data, array $exclude = [])
    {
        if ($data === []) {
            throw new DbException('Спроба записати порожній рядок у таблицю ' . $table);
        }

        $columns = array_keys($data);

        $updates = [];

        foreach ($columns as $column) {
            if (in_array($column, $exclude, true)) {
                continue;
            }

            $quoted = $this->quoteIdentifier($column);
            $updates[] = $quoted . ' = VALUES(' . $quoted . ')';
        }

        $sql = 'INSERT INTO {' . $table . '} ('
            . implode(', ', array_map([$this, 'quoteIdentifier'], $columns))
            . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?'))
            . ')';

        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        return $this->execute($sql, array_values($data));
    }

    /**
     * Чи існує таблиця в базі.
     *
     * @param string $table Логічна назва таблиці без префікса
     *
     * @return bool
     */
    public function tableExists($table)
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';

        return (int) $this->fetchValue($sql, [$this->table($table)]) > 0;
    }

    /**
     * Повна назва таблиці з префіксом (без обернених лапок).
     *
     * @param string $table Логічна назва, напр. 'objects'
     *
     * @return string Напр. 'plusest_objects'
     */
    public function table($table)
    {
        return $this->prefix . $table;
    }

    /**
     * Префікс таблиць із налаштувань.
     *
     * @return string
     */
    public function prefix()
    {
        return $this->prefix;
    }

    /**
     * Кількість виконаних запитів за час життя об'єкта.
     *
     * @return int
     */
    public function queryCount()
    {
        return $this->queryCount;
    }

    /**
     * Починає транзакцію.
     *
     * @return void
     */
    public function begin()
    {
        $this->pdo()->beginTransaction();
    }

    /**
     * Фіксує транзакцію.
     *
     * @return void
     */
    public function commit()
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /**
     * Відкочує транзакцію (безпечно викликати навіть якщо її не було).
     *
     * @return void
     */
    public function rollBack()
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Замінює {назваТаблиці} на `префікс_назваТаблиці`.
     *
     * @param string $sql Запит із логічними назвами таблиць у фігурних дужках
     *
     * @return string
     */
    public function expandTableNames($sql)
    {
        $prefix = $this->prefix;

        return preg_replace_callback(
            '~\{([A-Za-z][A-Za-z0-9_]*)\}~',
            function (array $matches) use ($prefix) {
                return '`' . $prefix . $matches[1] . '`';
            },
            $sql
        );
    }

    /**
     * Бере назву стовпця в обернені лапки, прибираючи все небезпечне.
     *
     * @param string $identifier Назва стовпця
     *
     * @return string
     */
    public function quoteIdentifier($identifier)
    {
        return '`' . str_replace('`', '', (string) $identifier) . '`';
    }
}
