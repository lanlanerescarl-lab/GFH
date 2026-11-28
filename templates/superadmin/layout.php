<?php
/**
 * Super Admin page shell (SA.1a). Reuses the Admin design tokens and component styles,
 * with a group-level sidebar, top bar (name, role badge, CSRF-protected sign-out),
 * a "View only" banner for Group Viewers, and a bare mode for /superadmin/sign-in.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var \Gfh\Core\View\SuperAdminAssetUrls $assets
 * @var string $homeUrl
 * @var bool $bare optional; true on sign-in
 * @var list<array{label: string, url: string, current?: bool}> $navigation optional
 * @var string|null $staffName optional
 * @var string|null $staffRole optional
 * @var bool $isGroupViewer optional
 * @var string|null $logoutUrl optional
 * @var string|null $csrf optional
 */
$bare ??= false;
$navigation ??= [];
$staffName ??= null;
$staffRole ??= null;
$isGroupViewer ??= false;
$logoutUrl ??= null;
$csrf ??= null;
$navigationHtml = $bare ? '' : trim($this->partial('partials/nav', ['navigation' => $navigation]));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $this->section('title', 'Super Admin') ?> · GFH Super Admin</title>
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->admin('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->shared('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->admin('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= $this->e($assets->url('css/app.css')) ?>">
    <?= $this->section('head') ?>
<?php if (!$bare): ?>
    <script type="module" src="<?= $this->e($assets->url('js/shell.js')) ?>"></script>
<?php endif; ?>
</head>
<body class="app-admin app-superadmin<?= $bare ? ' app-superadmin--bare' : '' ?>">
    <a class="skip-link" href="#main">Skip to the main content</a>
<?php if ($bare): ?>
    <main class="sa-auth-main" id="main" tabindex="-1">
        <?= $this->section('content') ?>
    </main>
<?php else: ?>
    <div class="admin-shell sa-shell">
        <aside class="admin-sidebar sa-sidebar" id="sa-sidebar" aria-label="Sidebar">
            <div class="sa-sidebar__head">
                <a class="shell-brand admin-brand sa-brand" href="<?= $this->e($homeUrl) ?>">GFH Super Admin</a>
                <button class="button button--ghost button--sm sa-sidebar__close" type="button" data-sa-nav-close aria-label="Close navigation">Close</button>
            </div>
            <?= $navigationHtml ?>
        </aside>
        <div class="sa-backdrop" data-sa-backdrop hidden></div>
        <div class="admin-body sa-body">
            <header class="admin-topbar sa-topbar">
<?php if ($navigationHtml !== ''): ?>
                <button class="shell-menu-button sa-menu-button" type="button" aria-expanded="false" aria-controls="sa-sidebar" data-sa-nav-toggle>Menu</button>
<?php endif; ?>
                <div class="admin-topbar__slot sa-topbar__context">
                    <span class="sa-topbar__label">Group oversight</span>
                    <?= $this->section('topbar') ?>
                </div>
<?php if ($staffName !== null): ?>
                <div class="admin-user sa-user">
                    <span class="admin-user__name sa-user__name"><?= $this->e($staffName) ?></span>
<?php if ($staffRole !== null && $staffRole !== ''): ?>
                    <span class="sa-role-badge<?= $isGroupViewer ? ' sa-role-badge--viewer' : '' ?>"><?= $this->e($staffRole) ?></span>
<?php endif; ?>
<?php if ($logoutUrl !== null && $csrf !== null): ?>
                    <form class="admin-user__logout" method="post" action="<?= $this->e($logoutUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                        <button class="button button--ghost button--sm sa-signout-btn" type="submit">Sign out</button>
                    </form>
<?php endif; ?>
                </div>
<?php endif; ?>
            </header>
            <main class="shell-main admin-main sa-main" id="main" tabindex="-1">
<?php if ($isGroupViewer): ?>
                <div class="sa-readonly-banner" role="status">
                    <span class="badge badge--info"><span class="badge__dot" aria-hidden="true"></span>View only</span>
                    <p class="sa-readonly-banner__text">Group Viewer access. You can browse all group pages, and write actions are not available.</p>
                </div>
<?php endif; ?>
                <?= $this->section('content') ?>
            </main>
            <footer class="shell-footer sa-footer">
                <?= $this->section('footer') ?>
            </footer>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
