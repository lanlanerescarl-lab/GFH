<?php
/**
 * Empty state partial.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $title
 * @var string $body
 * @var string $actionsHtml optional pre-escaped actions HTML
 */
$actionsHtml ??= '';
?>
<section class="empty-state">
    <span class="empty-state__icon" aria-hidden="true">∅</span>
    <h3 class="empty-state__title"><?= $this->e($title) ?></h3>
    <p class="empty-state__body"><?= $this->e($body) ?></p>
<?php if ($actionsHtml !== ''): ?>
    <div class="empty-state__actions"><?= $actionsHtml ?></div>
<?php endif; ?>
</section>
