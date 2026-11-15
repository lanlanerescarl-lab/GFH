<?php
/**
 * Admin Card partial.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string|null $title
 * @var string|null $subtitle
 * @var string $bodyHtml pre-escaped inner HTML
 * @var string $headerActionsHtml optional pre-escaped header actions HTML
 * @var string $footerHtml optional pre-escaped footer HTML
 * @var string $variant optional: 'default' | 'compact' | 'interactive'
 */
$title ??= null;
$subtitle ??= null;
$bodyHtml ??= '';
$headerActionsHtml ??= '';
$footerHtml ??= '';
$variant ??= 'default';

$classes = 'card';
if ($variant === 'compact') {
    $classes .= ' card--compact';
} elseif ($variant === 'interactive') {
    $classes .= ' card--interactive';
}
?>
<section class="<?= $this->e($classes) ?>">
<?php if (($title !== null && $title !== '') || $headerActionsHtml !== ''): ?>
    <div class="card__header">
        <div>
<?php if ($title !== null && $title !== ''): ?>
            <h2 class="card__title"><?= $this->e($title) ?></h2>
<?php endif; ?>
<?php if ($subtitle !== null && $subtitle !== ''): ?>
            <p class="card__subtitle"><?= $this->e($subtitle) ?></p>
<?php endif; ?>
        </div>
<?php if ($headerActionsHtml !== ''): ?>
        <div class="card__actions"><?= $headerActionsHtml ?></div>
<?php endif; ?>
    </div>
<?php endif; ?>
    <div class="card__body"><?= $bodyHtml ?></div>
<?php if ($footerHtml !== ''): ?>
    <div class="card__footer"><?= $footerHtml ?></div>
<?php endif; ?>
</section>
