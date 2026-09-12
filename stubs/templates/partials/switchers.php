<?php
/**
 * ============================================================================
 *  Перемикачі мови та валюти
 * ============================================================================
 *
 *  Вибір зберігається в куках, тому перемикач — це посилання з параметром
 *  ?lang=ru або ?currency=uah. Front controller ставить куку і повертає
 *  відвідувача на ту саму сторінку вже без параметра: так у пошуковий індекс
 *  не потрапляють адреси з чужими налаштуваннями.
 *
 *  Якщо в config.php залишена одна мова (або одна валюта) — відповідний
 *  перемикач не показується.
 * ============================================================================
 */

use Plusest\Site\Web\Translator;

$languages = $config->languages();
$currencies = $config->currencies();

// Повертаємось на поточну сторінку — з тими самими фільтрами. Змінна
// $path приходить із front controller і містить шлях без базової адреси.
$current = $url->base() . $path;
?>

<?php if (count($currencies) > 1): ?>
    <div class="pl-switch">
        <?php foreach ($currencies as $one): ?>
            <a class="<?= $one === $currency ? 'is-active' : '' ?>"
               href="<?= $view->esc($url->withParams($current, ['currency' => $one])) ?>"
               rel="nofollow"
               title="<?= $view->esc(strtoupper($one)) ?>">
                <?= $view->esc(Translator::currencySymbol($one)) ?>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>

<?php if (count($languages) > 1): ?>
    <div class="pl-switch">
        <?php foreach ($languages as $one): ?>
            <a class="<?= $one === $lang ? 'is-active' : '' ?>"
               href="<?= $view->esc($url->withParams($current, ['lang' => $one])) ?>"
               rel="nofollow">
                <?= $view->esc(Translator::langName($one)) ?>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
