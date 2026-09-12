<?php
/**
 * ============================================================================
 *  Спільний каркас сторінок розділу нерухомості
 * ============================================================================
 *
 *  Це найголовніший файл для правки під ваш сайт. Зазвичай роблять так:
 *
 *    * у header.php вставляють шапку свого сайту (меню, логотип);
 *    * у footer.php — підвал;
 *    * тут прибирають те, що на сайті вже підключене, — щоб не тягнути
 *      бібліотеку двічі.
 *
 *  Що підключається і навіщо
 *  -------------------------
 *  | Бібліотека      | Для чого                                            |
 *  |-----------------|-----------------------------------------------------|
 *  | Exo 2           | основний шрифт розділу                              |
 *  | Electrolize     | шрифт цін: рівні цифри, ціни в стовпчику не стрибають |
 *  | Flaticon UIcons | іконки параметрів, адреси, навігації                |
 *  | jQuery          | потрібен Select2 та Owl Carousel — самі вони плагіни |
 *  | Select2         | випадні списки з пошуком і множинним вибором        |
 *  | Owl Carousel    | карусель фотографій у картці обʼєкта                |
 *  | Fancybox        | галерея фотографій на весь екран                    |
 *  | Masonry         | розкладка характеристик у стовпці різної висоти      |
 *
 *  Прибрати можна будь-яку: site.js перед кожним викликом перевіряє, чи
 *  бібліотека є на сторінці. Без Owl Carousel картка покаже одну фотографію,
 *  без Fancybox клік по фото відкриє файл звичайним посиланням, без Select2
 *  фільтр залишиться зі звичайними select. Розділ працює в усіх випадках.
 *
 *  Якщо на вашому сайті jQuery вже підключений — приберіть рядок із ним
 *  нижче, але залиште Select2 та Owl Carousel ПІСЛЯ свого jQuery.
 *
 *  Доступні змінні:
 *
 *    $content   — готовий HTML сторінки (список обʼєктів або публікація)
 *    $title     — заголовок сторінки для <title>
 *    $siteTitle — назва розділу з config.php (site.titleUk / site.titleRu)
 *
 *  Плюс те, що доступно в кожному шаблоні: $view, $t, $url, $present,
 *  $config, $lang, $currency — див. опис класу Plusest\Site\Web\View.
 * ============================================================================
 */

// Тип сторінки: за ним site.js розуміє, чи треба запамʼятати адресу пошуку
// для кнопки «До списку обʼєктів». Значення ставлять самі шаблони сторінок.
$pageKind = isset($page) ? $page : 'other';
?>
<!doctype html>
<html lang="<?= $view->esc($lang === 'ru' ? 'ru' : 'uk') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->esc($title) ?><?= $siteTitle !== '' && $title !== $siteTitle ? ' — ' . $view->esc($siteTitle) : '' ?></title>

    <?php /* Шрифти. preconnect зменшує затримку першого запиту до Google Fonts. */ ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Electrolize&family=Exo+2:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&display=swap">

    <link rel="stylesheet" href="https://an.plusest.app/assets/stylesheets/main.css">

    <?php /* Іконки: тонкий прямий набір Flaticon UIcons. */ ?>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/@flaticon/flaticon-uicons@3.3.1/css/thin/straight.min.css">

    <?php /* Випадні списки, карусель, галерея. */ ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/owl.carousel@2.3.4/dist/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@6.1/dist/fancybox/fancybox.css">

    <?php /* Мапа. */ ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/maplibre-gl@5.3.0/dist/maplibre-gl.min.css">

    <?php /* Власні стилі розділу — завжди останні, щоб перекривати бібліотеки. */ ?>
    <link rel="stylesheet" href="<?= $view->esc($url->asset('site.css')) ?>">
</head>
<body data-pl-page="<?= $view->esc($pageKind) ?>">

<?= $view->optional('header') ?>

<main class="plusest plusest-main">
    <?= $content ?>
</main>

<?= $view->optional('footer') ?>

<?php
/*
 * Тексти для тих частин інтерфейсу, які малює JavaScript: підписи сердечка,
 * повідомлення в обраному, тексти всередині випадних списків.
 *
 * Передаємо їх звідси, а не пишемо в site.js, щоб вони перекладались так
 * само, як і решта розділу, — через templates/lang.php.
 */
$jsConfig = [
    'base'         => $url->base(),
    'favoritesUrl' => $url->favorites(),
    'lang'         => $lang,
    'text'         => [
        'favoriteAdd'      => $view->t('favorites.add'),
        'favoriteRemove'   => $view->t('favorites.remove'),
        'favoritesFailed'  => $view->t('favorites.failed'),
        'prevPhoto'        => $view->t('card.prev'),
        'nextPhoto'        => $view->t('card.next'),
        'noResults'        => $view->t('select.noResults'),
        'searching'        => $view->t('select.searching'),
    ],
];
?>
<script>
    window.plusestSite = <?= json_encode(
        $jsConfig,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/owl.carousel@2.3.4/dist/owl.carousel.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@6.1/dist/fancybox/fancybox.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/masonry-layout@4.2.2/dist/masonry.pkgd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/maplibre-gl@5.3.0/dist/maplibre-gl.min.js"></script>
<script src="<?= $view->esc($url->asset('site.js')) ?>"></script>
</body>
</html>
