<?php
/**
 * The POS app. One page: the screens (terminal, shift, sell, receipts, sync, close) are drawn by assets/js/app.js and
 * switched by the URL hash. This file only supplies the frame, one JSON config block and the receipt template.
 * An organization can replace this file, or only partials/receipt.php, from orgs/{slug}/pos/.
 *
 * The config block holds no secret: the organization name, the signed-in person's name and id, the session's CSRF
 * token (the same one every form carries) and a few paths. Nothing about authentication is kept in browser storage.
 * The service worker keeps a copy of this page for offline starts, with the token and the person's name taken out.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var string $configJson JSON, already escaped for use inside a script element
 * @var string $logoutUrl
 * @var string $csrf
 */
$this->layout('layout', ['bare' => true]);
$this->start('title');
?>Point of sale<?php
$this->end();
?>
<div class="pos-app" id="pos-app">
    <header class="pos-status" id="pos-status" aria-label="Status">
        <span class="pos-status__org" data-status="organization"></span>
        <span class="pos-status__item"><span class="pos-status__label">Terminal</span> <strong data-status="terminal">Not chosen</strong></span>
        <span class="pos-status__item"><span class="pos-status__label">Cashier</span> <strong data-status="cashier"></strong></span>
        <span class="pos-status__item"><span class="pos-status__label">Shift</span> <strong data-status="shift">No shift</strong></span>
        <span class="pos-status__spacer"></span>
        <span class="pos-status__item pos-status__clock" data-status="clock"></span>
        <span class="pos-status__item pos-pill" data-status="online" role="status">Online</span>
        <a class="pos-status__item pos-pill pos-pill--quiet" data-status="waiting" href="#sync" hidden></a>
        <form class="pos-status__signout" method="post" action="<?= $this->e($logoutUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>" data-csrf-field>
            <button class="pos-button pos-button--quiet" type="submit">Sign out</button>
        </form>
    </header>

    <div class="pos-banner" id="pos-banner" role="status" hidden></div>

    <nav class="pos-tabs" id="pos-tabs" aria-label="POS screens" hidden></nav>

    <section class="pos-locked" id="pos-locked" aria-labelledby="title-locked" hidden>
        <h1 id="title-locked" tabindex="-1">The POS is open in another tab</h1>
        <p class="pos-notice pos-notice--warn" id="pos-locked-text">The POS is already open in another tab or window on this device. Use that one, or close it and reload this page.</p>
        <p><button class="pos-button pos-button--primary" type="button" id="pos-locked-reload">Reload this page</button></p>
    </section>

    <div class="pos-screens" id="pos-screens">
        <noscript><p class="pos-notice pos-notice--error">The point of sale needs JavaScript. Turn it on in the browser and reload.</p></noscript>
        <section class="pos-screen" id="screen-terminal" data-screen="terminal" aria-labelledby="title-terminal" hidden></section>
        <section class="pos-screen" id="screen-shift" data-screen="shift" aria-labelledby="title-shift" hidden></section>
        <section class="pos-screen pos-screen--sell" id="screen-sell" data-screen="sell" aria-label="Sell" hidden></section>
        <section class="pos-screen" id="screen-receipts" data-screen="receipts" aria-labelledby="title-receipts" hidden></section>
        <section class="pos-screen" id="screen-sync" data-screen="sync" aria-labelledby="title-sync" hidden></section>
        <section class="pos-screen" id="screen-close" data-screen="close" aria-labelledby="title-close" hidden></section>
    </div>

    <div class="visually-hidden" id="pos-live" role="status" aria-live="polite" aria-atomic="true"></div>
</div>

<div id="pos-dialogs"></div>
<div class="print-sheet" id="print-sheet" aria-hidden="true"></div>

<template id="receipt-template">
<?= $this->partial('partials/receipt') ?>
</template>

<script type="application/json" id="pos-config"><?= $configJson ?></script>
<script type="module" src="<?= $this->e($assets->url('js/app.js')) ?>"></script>
