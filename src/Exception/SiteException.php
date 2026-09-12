<?php

namespace Plusest\Site\Exception;

use RuntimeException;

/**
 * Базове виключення заготовки.
 *
 * Усі власні виключення пакета успадковуються від цього класу, тому в скриптах
 * користувача достатньо одного catch, щоб перехопити будь-яку нашу помилку:
 *
 *     try {
 *         // ... робота із заготовкою ...
 *     } catch (\Plusest\Site\Exception\SiteException $e) {
 *         echo $e->getMessage();
 *     }
 */
class SiteException extends RuntimeException
{
}
