<?php
/**
 * Page shell of the Shop app: document head, skip link, header, main area, footer.
 * An organization can replace this whole file from orgs/{slug}/shop/layout.php, or any partial it uses
 * (partials/header.php, partials/nav.php, partials/search-form.php, partials/footer.php, partials/placeholder.php).
 * A view fills it with $this->layout('layout'), a "title" section (escaped text only) and its own output.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var string $organizationName
 * @var string $homeUrl
 * @var list<array{label: string, url: string, current?: bool}> $navigation optional; items for the header navigation
 * @var string $productsUrl optional; the product listing address, which the header search box submits to
 * @var string $searchText optional; the current search text, shown again in the header box
 * @var array{signedIn: bool, csrf: string, cartUrl: string, signInUrl: string, registerUrl: string, ordersUrl: string, signOutUrl: string, apiBase: string, slug: string} $shopAccount
 *      optional; the header links and the settings the page scripts read from <body> (organization slug, API root, CSRF token)
 */
$navigation ??= [];
$productsUrl ??= '';
$searchText ??= '';
$shopAccount ??= ['signedIn' => false, 'csrf' => '', 'cartUrl' => '', 'signInUrl' => '', 'registerUrl' => '', 'ordersUrl' => '', 'signOutUrl' => '', 'apiBase' => '', 'slug' => ''];
// The navigation partial decides which items may be shown; the menu button only makes sense if it printed something.
$navigationHtml = trim($this->partial('partials/nav', ['navigation' => $navigation]));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->section('title', 'Shop') ?> · <?= $this->e($organizationName) ?></title>
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->branding()) ?>">
    <?= $this->section('head') ?>
    <script type="module" src="<?= $this->e($assets->shared('js/shell.js')) ?>"></script>
    <script type="module" src="<?= $this->e($assets->url('js/shop.js')) ?>"></script>
</head>
<body class="app-shop" data-shop-slug="<?= $this->e($shopAccount['slug']) ?>" data-shop-api="<?= $this->e($shopAccount['apiBase']) ?>" data-shop-csrf="<?= $this->e($shopAccount['csrf']) ?>">
    <a class="skip-link" href="#main">Skip to the main content</a>
<?= $this->partial('partials/header', [
    'navigationHtml' => $navigationHtml,
    'productsUrl' => $productsUrl,
    'searchText' => $searchText,
]) ?>
    <main class="shell-main" id="main" tabindex="-1">
        <?= $this->section('content') ?>
    </main>
    <footer class="shell-footer shop-footer">
        <?= $this->section('footer', $this->partial('partials/footer', ['productsUrl' => $productsUrl])) ?>
    </footer>
    <?php // Copied by the script when a photo fails to load, so a broken image falls back to the same placeholder. ?>
    <template id="shop-placeholder-template"><?= $this->partial('partials/placeholder') ?></template>
</body>
</html>
