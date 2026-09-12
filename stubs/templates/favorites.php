<?php
/**
 * ============================================================================
 *  Розділ «Обране»
 * ============================================================================
 *
 *  Сторінка приходить порожньою — і це не помилка. Список обраного лежить у
 *  браузері відвідувача (localStorage), а не на сервері: реєстрації в
 *  розділі немає, а зберігати вибір анонімного відвідувача на сервері
 *  означало б ставити йому ще одну куку й відповідати за ці дані.
 *
 *  Тому сторінка працює у два кроки:
 *
 *    1. сервер віддає цей каркас;
 *    2. site.js читає список із localStorage і запитує
 *       '/base/favorites/?ids=...', а сервер віддає вже готову верстку
 *       карточок — той самий partial, що й у списку обʼєктів.
 *
 *  Значить, картка, яку ви переробили під свій дизайн, виглядає тут так
 *  само, як і в пошуку.
 *
 *  Доступна змінна: $title
 * ============================================================================
 */
?>
<div class="pl-container">

    <?= $view->partial('partials/nav', [
        'back'      => $url->base(),
        'backLabel' => $view->t('object.back'),
        'favorites' => false,
    ]) ?>

    <div class="pl-fav-head">
        <h1><?= $view->esc($title) ?></h1>

        <button class="pl-btn pl-btn-outline pl-btn-sm" type="button" data-pl-fav-clear>
            <i class="fi fi-ts-x" aria-hidden="true"></i>
            <?= $view->esc($view->t('favorites.clear')) ?>
        </button>
    </div>

    <?php /* Поки скрипт не отримав відповідь сервера. */ ?>
    <div class="pl-fav-loading" data-pl-fav-loading>
        <?= $view->esc($view->t('favorites.loading')) ?>
    </div>

    <?php /* Частину обʼєктів могли зняти з продажу — тоді сервер їх не
             знайде, а скрипт покаже це повідомлення й прибере їх зі
             свого списку. */ ?>
    <div class="pl-note pl-small pl-muted" data-pl-fav-gone hidden
         style="margin-bottom:1.25rem">
        <?= $view->esc($view->t('favorites.gone')) ?>
    </div>

    <?php /* Сюди site.js вставляє верстку карточок, яку віддав сервер.
             Адреса запиту лежить у самому атрибуті — щоб скрипт не збирав
             її з базового шляху руками. */ ?>
    <div data-pl-fav-list="<?= $view->esc($url->favorites()) ?>" hidden></div>

    <div class="pl-empty" data-pl-fav-empty hidden>
        <div class="pl-empty-icon">
            <i class="fi fi-ts-hand-holding-heart" aria-hidden="true"></i>
        </div>

        <p class="pl-note-title"><?= $view->esc($view->t('favorites.empty')) ?></p>
        <p class="pl-muted pl-small"><?= $view->esc($view->t('favorites.hint')) ?></p>

        <a class="pl-btn pl-btn-primary" href="<?= $view->esc($url->base()) ?>">
            <?= $view->esc($view->t('object.back')) ?>
        </a>
    </div>

</div>
