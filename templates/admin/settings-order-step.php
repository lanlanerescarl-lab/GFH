<?php

declare(strict_types=1);

/**
 * Admin Edit order step screen (GET /{org}/admin/settings/order-steps/{key}).
 * The name can always be changed. Every other field is a structural change and is refused while an order is open.
 * The key is shown read-only. Delete is refused while any order or history entry refers to the step.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $step
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var int $openOrders
 * @var array<string, string> $mapsToLabels
 * @var array<string, string> $appliesWhenLabels
 * @var string $stepUrl
 * @var string $stepsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e((string) $step['label']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings · Order steps',
    'title' => (string) $step['label'],
    'subtitle' => (string) $step['key'],
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($stepsUrl) . '">Back to order steps</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Nothing was changed', 'message' => $errors['action']]) ?>
</div>
<?php elseif ($openOrders > 0): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'info', 'title' => 'Only the name can change right now', 'message' => $openOrders . ' order(s) are open, so structural changes are refused until they are finished or cancelled.']) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="step-details-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="step-details-heading">Step details</h2>
                <p class="card__subtitle">
                    <?= $this->e((string) $step['order_count']) ?> order(s) on this step now.
                </p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($stepUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/order-step-fields', [
                'input' => $input, 'errors' => $errors, 'keyEditable' => false, 'keyValue' => (string) $step['key'],
                'mapsToLabels' => $mapsToLabels, 'appliesWhenLabels' => $appliesWhenLabels,
            ]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>

<section class="kit-section" aria-labelledby="step-delete-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="step-delete-heading">Delete step</h2>
                <p class="card__subtitle">
                    Possible only while no order is open and no order or history entry refers to the step
                    (<?= $this->e((string) $step['orders_total']) ?> order(s), <?= $this->e((string) $step['history_total']) ?> history entr<?= (int) $step['history_total'] === 1 ? 'y' : 'ies' ?> here now).
                    Otherwise rename it.
                </p>
            </div>
        </div>
        <div class="form-actions">
            <form method="post" action="<?= $this->e($stepUrl . '/delete') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Delete step', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
        </div>
    </section>
</section>
