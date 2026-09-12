<?php

namespace Plusest\Site;

use Plusest\Site\Exception\DbException;
use Plusest\Site\Exception\SiteException;
use Plusest\Site\Support\Fs;

/**
 * Застосовує схему бази даних із SQL-файлу.
 *
 * Це не повноцінна система міграцій з версіями — заготовці вона не потрібна.
 * Клас робить одну просту річ: читає schema.sql, розбиває його на окремі
 * інструкції та виконує їх по черзі. Усі CREATE TABLE у файлі написані
 * з IF NOT EXISTS, тому команду можна запускати повторно без наслідків.
 *
 * Якщо потрібно додати власний стовпець — допишіть у schema.sql інструкцію
 * ALTER TABLE, обгорнувши її в перевірку через columnExists() у своєму коді,
 * або просто виконайте ALTER вручну через phpMyAdmin.
 */
class Migrator
{
    /**
     * @var Db
     */
    private $db;

    /**
     * @param Db $db Підключення до бази
     */
    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Виконує всі інструкції зі SQL-файлу.
     *
     * @param string $schemaFile Шлях до schema.sql
     *
     * @return array{statements: int, tables: string[]} Скільки інструкцій виконано
     *                                                  та які таблиці існують після цього
     *
     * @throws SiteException Якщо файл не знайдено
     * @throws DbException   Якщо якась інструкція завершилась помилкою
     */
    public function apply($schemaFile)
    {
        if (!is_file($schemaFile)) {
            throw new SiteException('Файл схеми не знайдено: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);

        if ($sql === false) {
            throw new SiteException('Не вдалося прочитати файл схеми: ' . $schemaFile);
        }

        $statements = $this->splitStatements($sql);
        $executed = 0;

        foreach ($statements as $statement) {
            $this->db->execute($statement);
            $executed++;
        }

        return [
            'statements' => $executed,
            'tables'     => $this->existingTables(),
        ];
    }

    /**
     * Перелік таблиць заготовки, які вже є в базі.
     *
     * @return string[] Логічні назви без префікса
     */
    public function existingTables()
    {
        $found = [];

        foreach (['objects', 'objectMetro', 'staff', 'syncState'] as $table) {
            if ($this->db->tableExists($table)) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /**
     * Перелік таблиць заготовки, яких у базі ще немає.
     *
     * @return string[] Логічні назви без префікса
     */
    public function missingTables()
    {
        return array_values(array_diff(['objects', 'objectMetro', 'staff', 'syncState'], $this->existingTables()));
    }

    /**
     * Чи існує стовпець у таблиці.
     *
     * Знадобиться, якщо ви дописуєте власні стовпці і хочете перевірити,
     * чи вже застосовано ваш ALTER TABLE.
     *
     * @param string $table  Логічна назва таблиці без префікса
     * @param string $column Назва стовпця
     *
     * @return bool
     */
    public function columnExists($table, $column)
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';

        return (int) $this->db->fetchValue($sql, [$this->db->table($table), $column]) > 0;
    }

    /**
     * Шлях до schema.sql, який слід використати.
     *
     * Спочатку шукаємо файл у проєкті користувача (його можна правити),
     * і лише якщо там немає — беремо копію з самого пакета.
     *
     * @param Config $config Налаштування (щоб знати каталог проєкту)
     *
     * @return string
     */
    public static function resolveSchemaFile(Config $config)
    {
        $userFile = $config->get('paths.schema');

        if ($userFile !== null && $userFile !== '') {
            $userFile = $config->path('paths.schema');

            if (is_file($userFile)) {
                return $userFile;
            }
        }

        // Резервна копія, що постачається разом із пакетом.
        return Fs::join(dirname(__DIR__), 'stubs', 'install', 'schema.sql');
    }

    /**
     * Розбиває вміст SQL-файлу на окремі інструкції.
     *
     * Розбір навмисно простий: коментарі "--" вирізаються, а інструкції
     * розділяються символом ";" у кінці рядка. Цього достатньо для нашої
     * схеми, у якій немає ні тригерів, ні збережених процедур, ні крапки
     * з комою всередині рядкових літералів.
     *
     * @param string $sql Вміст файлу
     *
     * @return string[] Готові до виконання інструкції
     */
    private function splitStatements($sql)
    {
        // Прибираємо BOM, якщо файл зберегли з ним.
        $sql = preg_replace('~^\xEF\xBB\xBF~', '', $sql);

        // Нормалізуємо переноси рядків, щоб файл з Windows теж розбирався.
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);

        $lines = [];

        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);

            // Рядки-коментарі не потрібні MySQL — вони лише для читача файлу.
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $lines[] = $line;
        }

        $statements = [];

        foreach (explode(";\n", implode("\n", $lines) . "\n") as $statement) {
            $statement = trim($statement, " \t\n;");

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
