<?php
/**
 * Super Admin page header partial.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $title
 * @var string|null $subtitle
 * @var string $actionsHtml optional pre-escaped actions HTML
 */
$subtitle ??= null;
$actionsHtml ??= '';
?>
<header class="page-header sa-page-header">
    <div class="sa-page-header__copy">
        <h1 class="page-header__title"><?= $this->e($title) ?></h1>
<?php if ($subtitle !== null && $subtitle !== ''): ?>
        <p class="page-header__subtitle sa-page-header__subtitle"><?= $this->e($subtitle) ?></p>
<?php endif; ?>
    </div>
<?php if ($actionsHtml !== ''): ?>
    <div class="page-header__actions"><?= $actionsHtml ?></div>
<?php endif; ?>
</header>
