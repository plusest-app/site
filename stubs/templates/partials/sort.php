<?php
/**
 * ============================================================================
 *  Перемикач сортування
 * ============================================================================
 *
 *  Це не частина форми фільтра, а окремі посилання: сортування — така сама
 *  частина адреси, як і фільтри, тому кожен варіант має власне ЧПУ і його
 *  можна надіслати комусь у листі.
 *
 *  Меню відкриває site.js. Без JavaScript воно залишиться закритим, тому
 *  сортування у такому разі змінюється лише прямою адресою — це свідомий
 *  компроміс: показувати сім посилань підряд у рядку над списком гірше.
 *
 *  Доступна змінна: $query — поточний запит.
 * ============================================================================
 */

$sorts = Plusest\Site\Search\Query::sorts();
?>
<div class="pl-sort">
    <button class="pl-btn pl-btn-outline pl-btn-sm" type="button"
            data-pl-sort aria-expanded="false">
        <span class="pl-muted"><?= $view->esc($view->t('sort.title')) ?>:</span>
        <span><?= $view->esc($view->t('sort.' . $query->sort())) ?></span>
        <i class="fi fi-ts-angle-small-down" aria-hidden="true"></i>
    </button>

    <div class="pl-sort-menu" data-pl-sort-menu>
        <?php foreach ($sorts as $sort): ?>
            <a class="<?= $sort === $query->sort() ? 'is-active' : '' ?>"
               href="<?= $view->esc($url->search($query->withSort($sort))) ?>">
                <?= $view->esc($view->t('sort.' . $sort)) ?>
            </a>
        <?php endforeach ?>
    </div>
</div>
