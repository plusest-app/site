<?php
/**
 * ============================================================================
 *  Сторінка списку обʼєктів
 * ============================================================================
 *
 *  Порядок блоків: навігація, фільтр на градієнті, рядок «знайдено /
 *  сортування», сітка карточок, пагінація.
 *
 *  Фільтр стоїть ПЕРЕД контейнером навмисно: його обгортка з рухомим
 *  градієнтом займає всю ширину екрана, а біле поле з полями фільтра
 *  всередині вже обмежене контейнером.
 *
 *  Доступні змінні:
 *
 *    $objects — обʼєкти цієї сторінки (масив рядків із бази)
 *    $total   — скільки обʼєктів усього знайдено
 *    $pages   — скільки сторінок
 *    $page    — номер поточної сторінки
 *    $query   — поточний запит (обʼєкт Search\Query): фільтри, сортування
 *    $facets  — значення для списків фільтра, узяті з бази
 *    $title   — назва розділу з config.php
 * ============================================================================
 */
?>
<div class="pl-container">
    <?= $view->partial('partials/nav') ?>

    <h1><?= $view->esc($title) ?></h1>
</div>

<?= $view->partial('partials/filter', ['query' => $query, 'facets' => $facets]) ?>

<div class="pl-container">

    <?php if ($total === 0): ?>

        <div class="pl-empty">
            <div class="pl-empty-icon">
                <i class="fi fi-ts-file-circle-info" aria-hidden="true"></i>
            </div>

            <?php /* Порожня база і порожній результат фільтра — різні речі, і
                     підказка відвідувачу теж потрібна різна. */ ?>
            <?php if ($query->count() === 0): ?>

                <p class="pl-muted"><?= $view->esc($view->t('list.nothingYet')) ?></p>

            <?php else: ?>

                <p class="pl-note-title"><?= $view->esc($view->t('list.empty')) ?></p>
                <p class="pl-muted pl-small"><?= $view->esc($view->t('list.emptyHint')) ?></p>

                <a class="pl-btn pl-btn-outline" href="<?= $view->esc($url->search($query->reset())) ?>">
                    <?= $view->esc($view->t('filter.reset')) ?>
                </a>

            <?php endif ?>
        </div>

    <?php else: ?>

        <div class="pl-list-head">
            <div class="pl-list-total">
                <?= $view->esc($view->t('list.found', ['count' => $total])) ?>
            </div>

            <?= $view->partial('partials/sort', ['query' => $query]) ?>
        </div>

        <?= $view->partial('partials/cards', ['objects' => $objects]) ?>

        <?= $view->partial('partials/pagination', [
            'query' => $query,
            'page'  => $page,
            'pages' => $pages,
        ]) ?>

    <?php endif ?>

</div>
