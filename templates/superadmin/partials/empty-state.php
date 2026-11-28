<?php
/**
 * Empty state partial (short title, one sentence, optional next-step action).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $title
 * @var string $body
 * @var string $actionsHtml optional pre-escaped actions HTML
 */
$actionsHtml ??= '';
?>
<section class="empty-state sa-empty-state">
    <h3 class="empty-state__title"><?= $this->e($title) ?></h3>
    <p class="empty-state__body"><?= $this->e($body) ?></p>
<?php if ($actionsHtml !== ''): ?>
    <div class="empty-state__actions"><?= $actionsHtml ?></div>
<?php endif; ?>
</section>
