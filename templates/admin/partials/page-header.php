<?php
/**
 * Admin page header partial.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $title
 * @var string|null $eyebrow
 * @var string|null $subtitle
 * @var string $actionsHtml optional pre-rendered actions HTML
 */
$eyebrow ??= null;
$subtitle ??= null;
$actionsHtml ??= '';
?>
<header class="page-header">
    <div>
<?php if ($eyebrow !== null && $eyebrow !== ''): ?>
        <p class="page-header__eyebrow"><?= $this->e($eyebrow) ?></p>
<?php endif; ?>
        <h1 class="page-header__title"><?= $this->e($title) ?></h1>
<?php if ($subtitle !== null && $subtitle !== ''): ?>
        <p class="page-header__subtitle"><?= $this->e($subtitle) ?></p>
<?php endif; ?>
    </div>
<?php if ($actionsHtml !== ''): ?>
    <div class="page-header__actions"><?= $actionsHtml ?></div>
<?php endif; ?>
</header>
