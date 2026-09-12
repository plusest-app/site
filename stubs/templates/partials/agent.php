<?php
/**
 * ============================================================================
 *  Картка агента
 * ============================================================================
 *
 *  Показуємо співробітника, який створив публікацію в CRM. Якщо агента
 *  звільнили, синхронізація видаляє всі його публікації разом із ним — тому
 *  на сайті не буває обʼєкта з контактами людини, яка вже не працює.
 *
 *  Це головний блок, що перетворює відвідувача на звернення, тому:
 *
 *    * телефон оформлений найпомітнішою кнопкою — саме дзвінок і є цільова
 *      дія;
 *    * поруч стоїть текст, який пояснює, що дає цей дзвінок. Форма дієслова
 *      залежить від статі співробітника («він розповість» / «вона
 *      розповість»); якщо стать у CRM не вказана, текст іде від агентства
 *      («ми розповімо») — вгадувати її за імʼям не можна;
 *    * знизу — рядок про те, що консультація безкоштовна: він знімає
 *      найчастіше побоювання перед дзвінком.
 *
 *  Замінити будь-який із цих текстів можна у своєму templates/lang.php,
 *  ключі: object.agentLeadMan, object.agentLeadWoman, object.agentLead,
 *  object.agentNote.
 *
 *  Доступна змінна: $agent (може бути null, якщо публікація без автора).
 * ============================================================================
 */

if ($agent === null) {
    return;
}

$name = $present->agentName($agent);
$photo = $present->agentPhoto($agent);
$phones = $present->agentPhones($agent);
$links = $present->agentLinks($agent);
?>
<div class="pl-agent">

    <?php if ($photo !== null): ?>
        <img class="pl-agent-photo" src="<?= $view->esc($photo) ?>"
             alt="<?= $view->esc($name) ?>" loading="lazy">
    <?php endif ?>

    <div class="pl-agent-info">
        <div class="pl-agent-role"><?= $view->esc($view->t('object.agent')) ?></div>

        <?php if ($name !== null): ?>
            <div class="pl-agent-name"><?= $view->esc($name) ?></div>
        <?php endif ?>

        <p class="pl-agent-lead"><?= $view->esc($present->agentLead($agent)) ?></p>

        <?php if ($phones !== []): ?>
            <div class="pl-agent-note"><?= $view->esc($view->t('object.agentNote')) ?></div>
        <?php endif ?>
    </div>

    <div class="pl-agent-contacts">
        <?php foreach ($phones as $phone): ?>
            <?php if ($phone['tel'] !== null): ?>
                <a class="pl-agent-phone" href="tel:<?= $view->esc($phone['tel']) ?>">
                    <i class="fi fi-ts-phone-call" aria-hidden="true"></i>
                    <?= $view->esc($phone['text']) ?>
                </a>
            <?php else: ?>
                <span class="pl-agent-phone">
                    <i class="fi fi-ts-phone-call" aria-hidden="true"></i>
                    <?= $view->esc($phone['text']) ?>
                </span>
            <?php endif ?>

            <?php /* Месенджери — під телефоном, дрібніше: комусь простіше
                     написати, ніж подзвонити. */ ?>
            <?php if ($phone['telegram'] !== null || $phone['viber'] !== null): ?>
                <div class="pl-agent-links">
                    <?php if ($phone['telegram'] !== null): ?>
                        <a href="https://t.me/<?= $view->esc(ltrim($phone['telegram'], '@')) ?>"
                           target="_blank" rel="noopener nofollow">Telegram</a>
                    <?php endif ?>

                    <?php if ($phone['viber'] !== null): ?>
                        <a href="viber://chat?number=<?= $view->esc($phone['viber']) ?>"
                           rel="noopener nofollow">Viber</a>
                    <?php endif ?>
                </div>
            <?php endif ?>
        <?php endforeach ?>

        <?php /* Соцмережі співробітника — якщо він їх указав у CRM. */ ?>
        <?php if ($links !== []): ?>
            <div class="pl-agent-links">
                <?php foreach ($links as $link): ?>
                    <a href="<?= $view->esc($link['link']) ?>"
                       target="_blank" rel="noopener nofollow">
                        <?= $view->esc($link['platform'] === null ? 'www' : $link['platform']) ?>
                    </a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>

</div>
