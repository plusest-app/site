<?php
/**
 * ============================================================================
 *  Пагінація
 * ============================================================================
 *
 *  Номер сторінки — частина ЧПУ: /base/type-house/page-3/. Перша сторінка в
 *  адресу не пишеться, тому '/page-1/' front controller перекине на адресу
 *  без неї.
 *
 *  Доступні змінні: $query, $page (поточна сторінка), $pages (усього).
 * ============================================================================
 */

if ($pages < 2) {
    return;
}

// Показуємо не всі номери, а вікно навколо поточної сторінки: у базі на
// кілька тисяч обʼєктів сторінок буває сотні.
$window = 2;
$from = max(1, $page - $window);
$to = min($pages, $page + $window);
?>
<nav class="pl-pagination"
     aria-label="<?= $view->esc($view->t('pagination.page', ['page' => $page, 'pages' => $pages])) ?>">

    <?php if ($page > 1): ?>
        <a class="pl-page" href="<?= $view->esc($url->search($query->withPage($page - 1))) ?>" rel="prev">
            <i class="fi fi-ts-angle-small-left" aria-hidden="true"></i>
            <?= $view->esc($view->t('pagination.prev')) ?>
        </a>
    <?php else: ?>
        <span class="pl-page is-disabled">
            <i class="fi fi-ts-angle-small-left" aria-hidden="true"></i>
            <?= $view->esc($view->t('pagination.prev')) ?>
        </span>
    <?php endif ?>

    <?php if ($from > 1): ?>
        <a class="pl-page" href="<?= $view->esc($url->search($query->withPage(1))) ?>">1</a>

        <?php if ($from > 2): ?>
            <span class="pl-page-gap">…</span>
        <?php endif ?>
    <?php endif ?>

    <?php for ($number = $from; $number <= $to; $number++): ?>
        <?php if ($number === $page): ?>
            <span class="pl-page is-active" aria-current="page"><?= (int) $number ?></span>
        <?php else: ?>
            <a class="pl-page" href="<?= $view->esc($url->search($query->withPage($number))) ?>">
                <?= (int) $number ?>
            </a>
        <?php endif ?>
    <?php endfor ?>

    <?php if ($to < $pages): ?>
        <?php if ($to < $pages - 1): ?>
            <span class="pl-page-gap">…</span>
        <?php endif ?>

        <a class="pl-page" href="<?= $view->esc($url->search($query->withPage($pages))) ?>">
            <?= (int) $pages ?>
        </a>
    <?php endif ?>

    <?php if ($page < $pages): ?>
        <a class="pl-page" href="<?= $view->esc($url->search($query->withPage($page + 1))) ?>" rel="next">
            <?= $view->esc($view->t('pagination.next')) ?>
            <i class="fi fi-ts-angle-small-right" aria-hidden="true"></i>
        </a>
    <?php else: ?>
        <span class="pl-page is-disabled">
            <?= $view->esc($view->t('pagination.next')) ?>
            <i class="fi fi-ts-angle-small-right" aria-hidden="true"></i>
        </span>
    <?php endif ?>

</nav>
