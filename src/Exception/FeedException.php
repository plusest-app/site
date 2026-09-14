<?php

namespace Plusest\Site\Exception;

/**
 * Помилка отримання або розбору фіда з CRM.
 *
 * Причини бувають різні: мережа недоступна, посилання на JSON-feed невірне,
 * CRM відповіла помилкою, замість JSON прийшла HTML-сторінка. У всіх випадках
 * текст виключення пояснює, що саме сталося і що з цим робити.
 */
class FeedException extends SiteException
{
}
