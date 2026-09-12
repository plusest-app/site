<?php

namespace Plusest\Site\Exception;

/**
 * Помилка налаштувань: файл config.php не знайдено, він повертає не масив,
 * або в ньому не заповнені обовʼязкові параметри (доступ до БД, URL фіда).
 */
class ConfigException extends SiteException
{
}
