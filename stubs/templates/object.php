<?php
/**
 * ============================================================================
 *  Сторінка публікації обʼєкта
 * ============================================================================
 *
 *  Порядок блоків:
 *
 *    1. Навігація: ліворуч повернення до пошуку, праворуч обране й
 *       перемикачі валюти та мови.
 *    2. Заголовок на всю ширину екрана. Тлом — перша фотографія обʼєкта
 *       (розмір large) із розмиттям і паралаксом при прокручуванні.
 *    3. Повідомлення, якщо обʼєкт уже реалізований.
 *    4. Дві колонки: ліворуч фотографії (~300px), праворуч усе про обʼєкт —
 *       адреса, ціна, параметри, характеристики, опис. На вузьких екранах
 *       колонки стають рядками.
 *    5. Картка агента з продаючим текстом.
 *    6. Повторне посилання «до списку обʼєктів».
 *    7. Схожі обʼєкти.
 *
 *  Доступні змінні:
 *
 *    $object  — публікація: стовпці таблиці плюс розібраний JSON у ['data']
 *    $agent   — співробітник, чиї контакти показуємо (може бути null)
 *    $similar — кілька схожих обʼєктів для блоку в кінці
 *    $title   — повна назва обʼєкта
 *    $address — коротка адреса обʼєкта
 *
 *  Параметри й характеристики вже розшифровані за моделями даних:
 *  $present->parameters($object) і $present->characteristics($object)
 *  повертають готові пари «назва — значення» потрібною мовою.
 * ============================================================================
 */

$parameters = $present->parameters($object);
$characteristics = $present->characteristics($object);
$metro = $present->metro($object);
$multimedia = $present->multimedia($object);
$cadastre = $present->cadastre($object);
$mapUrl = $present->mapUrl($object);
$mapCoordinates = $present->mapCoordinates($object);
$description = $present->descriptionHtml($object);

$price = $present->price($object);
$unitPrice = $present->unitPrice($object);
$complex = $present->complex($object);
$id = $object['objectPublicationId'];

// Фонова фотографія заголовка. Беремо найбільший розмір: вона розтягується
// на всю ширину екрана, а розмиття однаково приховає нерізкість.
$hero = $present->photo($object, 'large');
?>

<div class="pl-container">
    <?php /* backSmart каже site.js: це сторінка публікації, і посилання
             «назад» треба уточнити — повернути відвідувача до налаштованого
             фільтра або до обраного, звідки він прийшов. */ ?>
    <?= $view->partial('partials/nav', [
        'back'      => $url->base(),
        'backLabel' => $view->t('object.back'),
        'backSmart' => true,
    ]) ?>
</div>

<!-- ==================== Заголовок ==================== -->
<div class="pl-hero<?= $hero === null ? ' pl-hero-plain' : '' ?>">
    <?php if ($hero !== null): ?>
        <?php /* Фотографія — окремим шаром: filter: blur() на самій обгортці
                 розмив би й заголовок. Паралакс цьому шару додає site.js. */ ?>
        <div class="pl-hero-bg" data-pl-parallax
             style="background-image:url('<?= $view->esc($hero) ?>')"></div>
    <?php endif ?>

    <div class="pl-hero-veil"></div>

    <div class="pl-hero-inner">
        <div class="pl-container">
            <h1>
                <?= $view->esc($title) ?>
                <?php if ($address !== null): ?>
                    <span><?= $view->esc($present->address($object, true)) ?></span>
                <?php endif ?>
            </h1>
        </div>
    </div>
</div>

<div class="pl-container">

    <!-- ==================== Мапа ==================== -->
    <?php if ($mapCoordinates): ?>
        <div class="pl-map" data-coordinate='<?= json_encode($mapCoordinates) ?>'></div>
    <?php endif ?>

    <?php /* --- Реалізований обʼєкт ------------------------------------- */ ?>
    <?php if ($present->isSold($object)): ?>
        <div class="pl-sold-notice">
            <i class="fi fi-ts-triangle-warning" aria-hidden="true"></i>

            <div>
                <div class="pl-sold-notice-title">
                    <?= $view->esc($view->t(
                        $object['operation'] === 'rent'
                            ? 'object.soldNoticeRent'
                            : 'object.soldNoticeSale'
                    )) ?>
                </div>
                <div class="pl-small"><?= $view->esc($view->t('object.soldNotice')) ?></div>
            </div>
        </div>
    <?php endif ?>

    <!-- ==================== Фотографії та дані ==================== -->
    <div class="pl-object">

        <!-- --- Ліва колонка: фотографії --- -->
        <div class="pl-object-photos">
            <?= $view->partial('partials/gallery', ['object' => $object]) ?>

            <?php /* Відео та огляди — під фотографіями: це продовження тієї
                     самої теми «подивитись обʼєкт». */ ?>
            <?php if ($multimedia !== []): ?>
                <div class="pl-multimedia">
                    <?php foreach ($multimedia as $item): ?>
                        <a href="<?= $view->esc($item['url']) ?>" target="_blank" rel="noopener nofollow">
                            <i class="fi fi-ts-play-circle" aria-hidden="true"></i>
                            <span><?= $view->esc($item['type'] === null
                                    ? $view->t('object.multimedia')
                                    : $item['type']) ?></span>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>

        <!-- --- Права колонка: усе про обʼєкт --- -->
        <div class="pl-object-data">

            <?php /* Верхній блок: ліворуч місце, праворуч ціна. */ ?>
            <div class="pl-summary">

                <div class="pl-summary-place">
                    <?php if ($address !== null): ?>
                        <div class="pl-card-line">
                            <i class="fi fi-ts-location-alt" aria-hidden="true"></i>

                            <?php if ($mapUrl !== null): ?>
                                <a href="<?= $view->esc($mapUrl) ?>" target="_blank" rel="noopener nofollow"
                                   title="<?= $view->esc($view->t('object.map')) ?>">
                                    <?= $view->esc($present->address($object, false)) ?>
                                </a>
                            <?php else: ?>
                                <span><?= $view->esc($present->address($object, false)) ?></span>
                            <?php endif ?>
                        </div>
                    <?php endif ?>

                    <?php if ($complex !== null): ?>
                        <div class="pl-card-line">
                            <i class="fi fi-ts-house-building" aria-hidden="true"></i>
                            <span><?= $view->esc($complex) ?></span>
                        </div>
                    <?php endif ?>

                    <?php if ($metro !== []): ?>
                        <div class="pl-card-line">
                            <i class="fi fi-ts-subway" aria-hidden="true"></i>
                            <span><?= $view->esc(implode('; ', $metro)) ?></span>
                        </div>
                    <?php endif ?>

                    <?php if ($cadastre !== []): ?>
                        <div class="pl-card-line">
                            <i class="fi fi-ts-land-layers" aria-hidden="true"></i>
                            <span>
                                <?= $view->esc($view->t('object.cadastre')) ?>:
                                <?= $view->esc(implode(', ', $cadastre)) ?>
                            </span>
                        </div>
                    <?php endif ?>

                    <?php /* --- Основні параметри ---------------------------------- */ ?>
                    <?php if ($parameters !== []): ?>
                        <section class="pl-section">
                            <h2 class="pl-section-title"><?= $view->esc($view->t('object.parameters')) ?></h2>

                            <ul class="pl-params">
                                <?php foreach ($parameters as $row): ?>
                                    <li class="pl-param">
                                        <span class="pl-param-name"><?= $view->esc($row['name']) ?></span>
                                        <span class="pl-param-value">
                                            <?= $view->esc($row['value']) ?>
                                            <?php /* Одиниці виміру приходять із моделі з
                                                     розміткою (м<sup>2</sup>), тому
                                                     виводимо як є. */ ?>
                                            <?php if ($row['postfix'] !== null): ?><?= $row['postfix'] ?><?php endif ?>
                                        </span>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </section>
                    <?php endif ?>

                </div>

                <div class="pl-summary-price">
                    <?php if ($price !== null): ?>
                        <span class="pl-price"><?= $view->esc($price) ?></span>

                        <?php if ($object['operation'] === 'rent'): ?>
                            <span class="pl-summary-unit"><?= $view->esc($view->t('card.perMonth')) ?></span>
                        <?php elseif ($unitPrice !== null): ?>
                            <span class="pl-summary-unit">
                                <?= $view->esc($unitPrice) ?> <?= $view->esc($present->unitLabel($object)) ?>
                            </span>
                        <?php endif ?>
                    <?php endif ?>

                    <?php /* Торг і розтермінування — окремими плашками: це
                             саме те, через що відвідувач наважується
                             подзвонити навіть при завищеній ціні. */ ?>
                    <?php if ($present->hasBargain($object) || $present->hasCredit($object)): ?>
                        <div class="pl-price-tags">
                            <?php if ($present->hasBargain($object)): ?>
                                <span class="pl-tag">
                                    <i class="fi fi-ts-badge-percent" aria-hidden="true"></i>
                                    <?= $view->esc($present->paramLabel('priceBargain')) ?>
                                </span>
                            <?php endif ?>

                            <?php if ($present->hasCredit($object)): ?>
                                <span class="pl-tag">
                                    <i class="fi fi-ts-hand-holding-usd" aria-hidden="true"></i>
                                    <?= $view->esc($present->paramLabel('priceCredit')) ?>
                                </span>
                            <?php endif ?>
                        </div>
                    <?php endif ?>

                    <div class="pl-price-tags">
                        <?php /* Друге місце для кнопки «в обране» — поруч із
                                 ціною, там, де відвідувач вирішує. */ ?>
                        <button class="pl-fav-wide" type="button"
                                data-pl-favorite="<?= $view->esc($id) ?>"
                                aria-pressed="false"
                                aria-label="<?= $view->esc($view->t('favorites.add')) ?>">
                            <?= $view->partial('partials/heart') ?>
                            <span><?= $view->esc($view->t('favorites.title')) ?></span>
                        </button>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <?php /* --- Характеристики ------------------------------------- */ ?>
    <?php /* Розділів буває від двох до десяти, і висота в них дуже
             різна. Вертикальну укладку рахує Masonry, щоб під
             коротким розділом не залишалось порожнього місця. */ ?>
    <?php if ($characteristics !== []): ?>
        <section class="pl-section">
            <div class="pl-masonry" data-pl-masonry>
                <?php foreach ($characteristics as $section): ?>
                    <div class="pl-masonry-item">
                        <div class="pl-masonry-box">
                            <h3 class="pl-masonry-title"><?= $view->esc($section['name']) ?></h3>

                            <ul class="pl-chars">
                                <?php foreach ($section['items'] as $row): ?>
                                    <li>
                                        <span class="pl-chars-name"><?= $view->esc($row['name']) ?>:</span>
                                        <?= $view->esc($row['value']) ?>
                                        <?php if ($row['postfix'] !== null): ?><?= $row['postfix'] ?><?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        </section>
    <?php endif ?>

    <?php /* --- Опис ----------------------------------------------- */ ?>
    <?php if ($description !== null): ?>
        <section class="pl-section">
            <h2 class="pl-section-title"><?= $view->esc($view->t('object.description')) ?></h2>

            <?php /* Текст уже екранований у Present::descriptionHtml(). */ ?>
            <div class="pl-description"><?= $description ?></div>
        </section>
    <?php endif ?>

    <!-- ==================== Агент ==================== -->
    <section class="pl-section">
        <?= $view->partial('partials/agent', ['agent' => $agent]) ?>
    </section>

    <!-- ==================== Повернення до пошуку ==================== -->
    <div class="pl-nav">
        <a class="pl-back" href="<?= $view->esc($url->base()) ?>"
           data-pl-back
           data-pl-back-favorites="<?= $view->esc($url->favorites()) ?>"
           data-pl-back-favorites-label="<?= $view->esc($view->t('favorites.back')) ?>">
            <i class="fi fi-ts-angle-small-left" aria-hidden="true"></i>
            <span data-pl-back-label><?= $view->esc($view->t('object.backTop')) ?></span>
        </a>
    </div>

    <!-- ==================== Схожі обʼєкти ==================== -->
    <?php if ($similar !== []): ?>
        <section class="pl-section">
            <h2 class="pl-section-title"><?= $view->esc($view->t('object.similar')) ?></h2>

            <?= $view->partial('partials/cards', ['objects' => $similar]) ?>
        </section>
    <?php endif ?>

</div>
