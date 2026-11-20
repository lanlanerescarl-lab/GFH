<?php

declare(strict_types=1);

/**
 * Admin Add order step screen (GET /{org}/admin/settings/order-steps/new, posts to /{org}/admin/settings/order-steps).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var int $openOrders
 * @var array<string, string> $mapsToLabels
 * @var array<string, string> $appliesWhenLabels
 * @var string $stepsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Add order step');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Order steps',
    'title' => 'Add order step',
    'subtitle' => 'The key is chosen now and cannot be changed later. A step can only be added while no order is open.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($stepsUrl) . '">Back to order steps</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Step not added', 'message' => $errors['action']]) ?>
</div>
<?php elseif ($openOrders > 0): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'info', 'title' => 'Not possible right now', 'message' => $openOrders . ' order(s) are open, so a step cannot be added until they are finished or cancelled.']) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="step-new-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="step-new-heading">Step details</h2>
            </div>
        </div>
        <form method="post" action="<?= $this->e($stepsUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/order-step-fields', [
                'input' => $input, 'errors' => $errors, 'keyEditable' => true,
                'mapsToLabels' => $mapsToLabels, 'appliesWhenLabels' => $appliesWhenLabels,
            ]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Add step', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
