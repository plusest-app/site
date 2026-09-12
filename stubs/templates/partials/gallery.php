<?php
/**
 * ============================================================================
 *  Галерея фотографій на сторінці публікації
 * ============================================================================
 *
 *  Головна фотографія розміру medium, під нею — решта дрібними мініатюрами
 *  (розмір preview, 45×35). Мініатюри тут не для розгляду, а щоб було видно,
 *  скільки фотографій має обʼєкт, і щоб можна було почати перегляд із
 *  потрібної.
 *
 *  Клік по будь-якій фотографії відкриває галерею Fancybox на весь екран у
 *  розмірі large. Галерея одна на всю публікацію — атрибут data-fancybox
 *  містить ідентифікатор публікації, тому листати можна лише фотографії
 *  цього обʼєкта.
 *
 *  Без JavaScript теж працює: кожна фотографія загорнута у звичайне
 *  посилання на файл розміру large.
 *
 *  Доступна змінна: $object
 * ============================================================================
 */

$medium = $present->photos($object, 'medium');
$previews = $present->photos($object, 'preview');
$large = $present->photos($object, 'large');

if ($large === []) {
    echo '<div class="pl-card-empty pl-note">'
        . '<span>' . $view->esc($view->t('card.noPhoto')) . '</span>'
        . '</div>';

    return;
}

$title = $present->fullTitle($object);
$gallery = 'plusest-' . $object['objectPublicationId'];
$count = count($large);
?>
<a class="pl-gallery-main"
   href="<?= $view->esc($large[0]['url']) ?>"
   data-fancybox="<?= $view->esc($gallery) ?>">

    <img src="<?= $view->esc($medium[0]['url']) ?>" alt="<?= $view->esc($title) ?>">

    <?php if ($count > 1): ?>
        <span class="pl-gallery-badge">
            <i class="fi fi-ts-images" aria-hidden="true"></i>
            <?= (int) $count ?>
        </span>
    <?php endif ?>
</a>

<?php if ($count > 1): ?>
    <div class="pl-gallery-strip">
        <?php /* Перша фотографія вже стоїть великою вище, тому в стрічці
                 починаємо з другої. */ ?>
        <?php for ($index = 1; $index < $count; $index++): ?>
            <a href="<?= $view->esc($large[$index]['url']) ?>"
               data-fancybox="<?= $view->esc($gallery) ?>"
               data-thumb="<?= $view->esc($medium[$index]['url']) ?>">
                <img src="<?= $view->esc($previews[$index]['url']) ?>" alt="" loading="lazy">
            </a>
        <?php endfor ?>
    </div>
<?php endif ?>
