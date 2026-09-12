<?php
/**
 * ============================================================================
 *  Сторінка помилки (код HTTP 500)
 * ============================================================================
 *
 *  Текст помилки й стек викликів передаються сюди лише коли в config.php
 *  увімкнено debug.enabled. На робочому сайті відвідувач бачить загальну
 *  фразу — і це правильно: у тексті помилки бувають і шляхи на сервері, і
 *  фрагменти SQL.
 *
 *  Доступні змінні: $message, $trace (обидві можуть бути null).
 * ============================================================================
 */
?>
<div class="pl-container">
    <div style="margin:3rem 0">
        <h1><?= $view->esc($view->t('error.general')) ?></h1>
        <p class="pl-muted"><?= $view->esc($view->t('error.generalText')) ?></p>

        <?php if ($message !== null): ?>
            <div class="pl-note pl-error" style="margin:1.5rem 0">
                <div class="pl-note-title"><?= $view->esc($message) ?></div>

                <?php if ($trace !== null): ?>
                    <pre class="pl-small" style="margin:0;white-space:pre-wrap"><?= $view->esc($trace) ?></pre>
                <?php endif ?>
            </div>
        <?php endif ?>

        <a class="pl-btn pl-btn-outline" href="<?= $view->esc($url->base()) ?>">
            <?= $view->esc($view->t('object.back')) ?>
        </a>
    </div>
</div>
