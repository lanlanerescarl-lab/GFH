<?php
/**
 * Page shell of the POS app: document head, skip link, header with brand and navigation, main area, footer.
 * An organization can replace this whole file from orgs/{slug}/pos/layout.php, or just partials/nav.php.
 * A view fills it with $this->layout('layout'), a "title" section (escaped text only) and its own output.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var string $organizationName
 * @var string $homeUrl
 * @var list<array{label: string, url: string, current?: bool}> $navigation optional; items for the header navigation
 * @var bool $bare optional; true leaves out the brand header and footer (the POS screens draw their own status bar)
 */
$navigation ??= [];
$bare ??= false;
// The navigation partial decides which items may be shown; the menu button only makes sense if it printed something.
$navigationHtml = $bare ? '' : trim($this->partial('partials/nav', ['navigation' => $navigation]));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $this->section('title', 'Point of sale') ?> · <?= $this->e($organizationName) ?></title>
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->branding()) ?>">
    <?= $this->section('head') ?>
    <script type="module" src="<?= $this->e($assets->shared('js/shell.js')) ?>"></script>
</head>
<body class="app-pos<?= $bare ? ' app-pos--bare' : '' ?>">
    <a class="skip-link" href="#main">Skip to the main content</a>
<?php if (!$bare): ?>
    <header class="shell-header">
        <a class="shell-brand" href="<?= $this->e($homeUrl) ?>"><?= $this->e($organizationName) ?></a>
<?php if ($navigationHtml !== ''): ?>
        <button class="shell-menu-button" type="button" aria-expanded="false" aria-controls="shell-nav" data-nav-toggle>Menu</button>
<?php endif; ?>
        <?= $navigationHtml ?>
    </header>
<?php endif; ?>
    <main class="shell-main" id="main" tabindex="-1">
        <?= $this->section('content') ?>
    </main>
<?php if (!$bare): ?>
    <footer class="shell-footer">
        <?= $this->section('footer') ?>
    </footer>
<?php endif; ?>
</body>
</html>
