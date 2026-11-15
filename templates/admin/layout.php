<?php
/**
 * Page shell of the Admin app: skip link, a sidebar with the brand and navigation, a top bar with the menu button
 * and the signed-in person, the main area and a footer.
 * An organization can replace this whole file from orgs/{slug}/admin/layout.php, or just partials/nav.php.
 * A view fills it with $this->layout('layout'), a "title" section (escaped text only) and its own output, and may
 * add a "topbar" section (for example a search box) and a "head" section (page styles or module scripts).
 *
 * The navigation items come from AdminNavigationService, which keeps only what PermissionService::can() allows and
 * lets extensions add items through the navigation.admin hook. This file does not decide who sees what.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var string $organizationName
 * @var string $homeUrl
 * @var list<array{label: string, url: string, current?: bool, group?: string}> $navigation optional
 * @var string|null $staffName optional; shown in the top bar
 * @var string|null $staffRole optional
 * @var string|null $logoutUrl optional; with $csrf, shows a sign-out button
 * @var string|null $csrf optional
 */
$navigation ??= [];
$staffName ??= null;
$staffRole ??= null;
$logoutUrl ??= null;
$csrf ??= null;
// The navigation partial decides which items may be shown; the menu button only makes sense if it printed something.
$navigationHtml = trim($this->partial('partials/nav', ['navigation' => $navigation]));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $this->section('title', 'Staff') ?> · <?= $this->e($organizationName) ?></title>
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->branding()) ?>">
    <?= $this->section('head') ?>
    <script type="module" src="<?= $this->e($assets->shared('js/shell.js')) ?>"></script>
</head>
<body class="app-admin">
    <a class="skip-link" href="#main">Skip to the main content</a>
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Sidebar">
            <a class="shell-brand admin-brand" href="<?= $this->e($homeUrl) ?>"><?= $this->e($organizationName) ?></a>
            <?= $navigationHtml ?>
        </aside>
        <div class="admin-body">
            <header class="admin-topbar">
<?php if ($navigationHtml !== ''): ?>
                <button class="shell-menu-button" type="button" aria-expanded="false" aria-controls="shell-nav" data-nav-toggle>Menu</button>
<?php endif; ?>
                <div class="admin-topbar__slot"><?= $this->section('topbar') ?></div>
<?php if ($staffName !== null): ?>
                <div class="admin-user">
                    <span class="admin-user__name"><?= $this->e($staffName) ?></span>
<?php if ($staffRole !== null && $staffRole !== ''): ?>
                    <span class="admin-user__role"><?= $this->e($staffRole) ?></span>
<?php endif; ?>
<?php if ($logoutUrl !== null && $csrf !== null): ?>
                    <form class="admin-user__logout" method="post" action="<?= $this->e($logoutUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <button class="button button--ghost button--sm" type="submit">Sign out</button>
                    </form>
<?php endif; ?>
                </div>
<?php endif; ?>
            </header>
            <main class="shell-main admin-main" id="main" tabindex="-1">
<?php if (is_array($flash ?? null) && $flash !== []): ?>
                <?= $this->partial('partials/flash', ['flash' => $flash]) ?>
<?php endif; ?>
                <?= $this->section('content') ?>
            </main>
            <footer class="shell-footer">
                <?= $this->section('footer') ?>
            </footer>
        </div>
    </div>
</body>
</html>
