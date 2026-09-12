<?php

namespace Plusest\Site\Exception;

/**
 * Помилка роботи з базою даних: не вдалося підключитись, помилковий SQL,
 * не виконалась міграція схеми.
 *
 * Оригінальний PDOException завжди зберігається у $e->getPrevious() —
 * там буде технічний текст помилки від MySQL.
 */
class DbException extends SiteException
{
}
