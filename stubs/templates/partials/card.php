<?php
/**
 * ============================================================================
 *  Картка обʼєкта у списку
 * ============================================================================
 *
 *  Будова картки, згори донизу:
 *
 *    1. Фотографії (розмір medium) у каруселі, пропорція 4:3. Висота блоку
 *       при прокручуванні не змінюється: пропорцію задає CSS кожному слайду
 *       окремо, а фотографія обрізається по центру. Клік відкриває галерею
 *       на весь екран — окрему для кожної публікації.
 *    2. Позначки поверх фотографії: NEW (обʼєкт додано менш ніж тиждень
 *       тому) і «Продано» / «Здано» на реалізованих.
 *    3. Ціна на градієнті в нижній частині фотографії. Для продажу під
 *       загальною ціною стоїть ціна за м² (за соту для ділянки), для
 *       оренди — «на місяць».
 *    4. Назва обʼєкта, короткa адреса, житловий комплекс.
 *    5. Три параметри на світло-сірому тлі: кімнати, поверх, площа.
 *    6. Кнопки, притиснуті до низу картки: «Детальніше» і сердечко
 *       «в обране».
 *
 *  Однакова висота карточок у рядку тримається на CSS: сітка вирівнює рядок
 *  за найвищою карткою, а сама картка — flex-колонка, у якій розтягується
 *  блок із назвою. Тому кнопки завжди на одній лінії, скільки б рядків не
 *  зайняла назва.
 *
 *  Доступна змінна: $object — рядок публікації з бази.
 * ============================================================================
 */

$id = $object['objectPublicationId'];
$link = $url->object($id);

// Для картки — коротка назва, без кількості кімнат: вона й так стоїть
// окремим параметром нижче, а назва в три рядки ламає сітку.
$title = $present->title($object);

$photos = $present->photos($object, 'medium');
$large = $present->photos($object, 'large');

$price = $present->price($object);
$priceNote = $present->priceNote($object);

$address = $present->address($object);
$complex = $present->complex($object);

$rooms = $present->roomsText($object);
$floor = $present->floorShort($object);
$area = $present->areaText($object);

// Галерея групується за ідентифікатором публікації: відвідувач листає
// фотографії ЦЬОГО обʼєкта і не потрапляє до сусіднього.
$gallery = 'plusest-' . $id;
?>
<article class="pl-card" data-pl-card>

    <div class="pl-card-media<?php if ($present->isSold($object)): ?> pl-sold<?php endif ?>">

        <?php if ($photos === []): ?>

            <?php /* Фотографій ще немає: вони завантажуються окремим кроком
                     синхронізації, і на великій базі це триває довго. */ ?>
            <div class="pl-card-empty">
                <span><?= $view->esc($view->t('card.noPhoto')) ?></span>
            </div>

        <?php else: ?>

            <?php /* Клас owl-carousel обовʼязковий: усе оформлення каруселі в
                     її власному CSS привʼязане саме до нього. */ ?>
            <div class="pl-card-carousel owl-carousel" data-pl-carousel>
                <?php foreach ($photos as $index => $photo): ?>
                    <div class="pl-card-slide">
                        <a class="pl-card-photo"
                           href="<?= $view->esc($large[$index]['url']) ?>"
                           data-fancybox="<?= $view->esc($gallery) ?>">
                            <?php /* Перше фото вантажимо звичайно, решту —
                                     ліниво: у списку з тридцяти обʼєктів
                                     фотографій буває кілька сотень. */ ?>
                            <img src="<?= $view->esc($photo['url']) ?>"
                                 alt="<?= $view->esc($title) ?>"
                                 loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                        </a>
                    </div>
                <?php endforeach ?>
            </div>

        <?php endif ?>

        <?php if ($present->isNew($object)): ?>
            <div class="pl-card-new"><?= $view->esc($view->t('card.new')) ?></div>
        <?php endif ?>

        <?php if ($present->isSold($object)): ?>
            <div class="pl-card-sold"><?= $view->esc($present->soldLabel($object)) ?></div>
        <?php endif ?>

        <?php if ($price !== null): ?>
            <div class="pl-card-price">
                <span class="pl-price"><?= $view->esc($price) ?></span>

                <?php if ($priceNote !== null): ?>
                    <span class="pl-card-price-note"><?= $view->esc($priceNote) ?></span>
                <?php endif ?>
            </div>
        <?php endif ?>

    </div>

    <div class="pl-card-body">
        <h2 class="pl-card-title">
            <a href="<?= $view->esc($link) ?>"><?= $view->esc($title) ?></a>
        </h2>

        <?php if ($address !== null): ?>
            <div class="pl-card-line">
                <i class="fi fi-ts-location-alt" aria-hidden="true"></i>
                <span><?= $view->esc($address) ?></span>
            </div>
        <?php endif ?>

        <?php if ($complex !== null): ?>
            <div class="pl-card-line">
                <i class="fi fi-ts-house-building" aria-hidden="true"></i>
                <span><?= $view->esc($complex) ?></span>
            </div>
        <?php endif ?>
    </div>

    <?php /* Рядок параметрів. Показуємо лише заповнені: у ділянки немає ні
             кімнат, ні поверхів, і прочерки замість них виглядали б так,
             ніби дані загубились. */ ?>
    <?php if ($rooms !== null || $floor !== null || $area !== null): ?>
        <div class="pl-card-facts">
            <?php if ($rooms !== null): ?>
                <span class="pl-card-fact">
                    <i class="fi fi-ts-blueprint" aria-hidden="true"></i>
                    <?= $view->esc($rooms) ?>
                </span>
            <?php endif ?>

            <?php if ($floor !== null): ?>
                <span class="pl-card-fact" title="<?= $view->esc($present->floorText($object)) ?>">
                    <i class="fi fi-ts-floor-layer" aria-hidden="true"></i>
                    <?= $view->esc($floor) ?>
                </span>
            <?php endif ?>

            <?php if ($area !== null): ?>
                <span class="pl-card-fact">
                    <i class="fi fi-ts-ruler-triangle" aria-hidden="true"></i>
                    <?= $view->esc($area) ?>
                </span>
            <?php endif ?>
        </div>
    <?php endif ?>

    <div class="pl-card-actions">
        <a class="pl-card-details" href="<?= $view->esc($link) ?>">
            <?= $view->esc($view->t('card.details')) ?>
        </a>

        <?php /* Обране зберігається в браузері відвідувача, тому це кнопка,
                 а не посилання: сервер про цей клік нічого не знає. Стан
                 сердечка ставить site.js із localStorage. */ ?>
        <button class="pl-fav-btn" type="button"
                data-pl-favorite="<?= $view->esc($id) ?>"
                aria-pressed="false"
                aria-label="<?= $view->esc($view->t('favorites.add')) ?>">
            <?= $view->partial('partials/heart') ?>
        </button>
    </div>

</article>
