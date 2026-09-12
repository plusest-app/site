<?php
/**
 * ============================================================================
 *  Сторінка 404
 * ============================================================================
 *
 *  Показується, коли обʼєкта немає в базі або в адресі фільтра щось не так.
 *  Разом із цією сторінкою віддається код HTTP 404 — так пошукові системи
 *  прибирають зі свого індексу адреси знятих із продажу обʼєктів.
 * ============================================================================
 */
?>
<div class="pl-container">
    <div class="pl-empty" style="margin:3rem 0">
        <div class="pl-empty-icon">
            <i class="fi fi-ts-search-alt" aria-hidden="true"></i>
        </div>

        <h1><?= $view->esc($view->t('error.notFound')) ?></h1>
        <p class="pl-muted"><?= $view->esc($view->t('error.notFoundText')) ?></p>

        <a class="pl-btn pl-btn-primary" href="<?= $view->esc($url->base()) ?>">
            <?= $view->esc($view->t('object.back')) ?>
        </a>
    </div>
</div>
