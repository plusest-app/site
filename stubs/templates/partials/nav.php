<?php
/**
 * ============================================================================
 *  Навігація розділу
 * ============================================================================
 *
 *  Один рядок над вмістом сторінки: ліворуч — посилання «назад», праворуч —
 *  обране й перемикачі валюти та мови.
 *
 *  Навіщо це окремо від header.php
 *  -------------------------------
 *  header.php ви замінюєте шапкою свого сайту, і перемикачі там загубились
 *  би. А ця смужка належить саме розділу нерухомості й переживе будь-яку
 *  вашу шапку.
 *
 *  Параметри (усі необовʼязкові):
 *
 *    $back      — куди веде посилання «назад»; null — не показувати його
 *    $backLabel — підпис цього посилання
 *    $favorites — показувати посилання на обране (за замовчуванням так)
 * ============================================================================
 */

$back = isset($back) ? $back : null;
$backLabel = isset($backLabel) ? $backLabel : $view->t('object.back');
$showFavorites = isset($favorites) ? $favorites : true;

// На сторінці публікації посилання «назад» уточнює site.js: якщо відвідувач
// прийшов із налаштованого фільтра — поверне до нього, якщо з обраного — до
// обраного. Тут же стоїть варіант «без JavaScript»: загальний список.
$isObjectPage = isset($backSmart) ? (bool) $backSmart : false;
?>
<nav class="pl-nav">
    <div class="pl-nav-group">
        <?php if ($back !== null): ?>
            <a class="pl-back" href="<?= $view->esc($back) ?>"
                <?php if ($isObjectPage): ?>
                    data-pl-back
                    data-pl-back-favorites="<?= $view->esc($url->favorites()) ?>"
                    data-pl-back-favorites-label="<?= $view->esc($view->t('favorites.back')) ?>"
                <?php endif ?>
            >
                <i class="fi fi-ts-angle-small-left" aria-hidden="true"></i>
                <span data-pl-back-label><?= $view->esc($backLabel) ?></span>
            </a>
        <?php endif ?>
    </div>

    <div class="pl-nav-group">
        <?php if ($showFavorites): ?>
            <a class="pl-fav-link" href="<?= $view->esc($url->favorites()) ?>" rel="nofollow">
                <?= $view->partial('partials/heart') ?>
                <span><?= $view->esc($view->t('favorites.title')) ?></span>

                <?php /* Лічильник заповнює site.js із localStorage: сервер не
                         знає, що відклав саме цей відвідувач. Поки список
                         порожній, скрипт ховає цей елемент. */ ?>
                <span class="pl-fav-count" data-pl-fav-count hidden>0</span>
            </a>
        <?php endif ?>

        <?= $view->partial('partials/switchers') ?>
    </div>
</nav>
