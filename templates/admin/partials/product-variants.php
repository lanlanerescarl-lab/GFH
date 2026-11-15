<?php

declare(strict_types=1);

/**
 * Product variants tab partial: edit existing variants (with stock & cost summary) and add new variants.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product
 * @var string $adminBase
 * @var array<string, array<string, mixed>> $variantInputs
 * @var array<string, array<string, string>> $variantErrors
 * @var array<string, mixed> $newVariantInput
 * @var array<string, string> $newVariantErrors
 * @var bool $canEdit
 * @var string $csrf
 */

use Gfh\Core\Support\Format;

$productId = (string) $product['id'];
$variants = $product['variants'] ?? [];
$statusTones = ['active' => 'ok', 'inactive' => 'warn', 'discontinued' => 'neutral'];
?>
<div class="kit-stack">
<?php if ($variants === []): ?>
    <?= $this->partial('partials/empty-state', [
        'title' => 'No variants yet',
        'body' => 'Add a variant with a SKU and retail price before activating this product.',
    ]) ?>
<?php endif; ?>

<?php foreach ($variants as $variant): ?>
<?php
    $vid = (string) $variant['id'];
    $vInput = ($variantInputs[$vid] ?? []) + [
        'sku' => (string) $variant['sku'],
        'barcode' => (string) ($variant['barcode'] ?? ''),
        'name' => (string) $variant['name'],
        'unit' => (string) $variant['unit'],
        'unit_size' => (string) ($variant['unit_size'] ?? ''),
        'allow_decimal_qty' => (bool) $variant['allow_decimal_qty'],
        'qty_step' => (string) $variant['qty_step'],
        'min_qty' => (string) $variant['min_qty'],
        'track_batches' => (bool) $variant['track_batches'],
        'retail_price' => (string) $variant['retail_price'],
        'trade_price' => (string) ($variant['trade_price'] ?? ''),
        'reorder_level' => (string) $variant['reorder_level'],
        'status' => (string) $variant['status'],
    ];
    $vErr = $variantErrors[$vid] ?? [];
    $vStatus = (string) $variant['status'];
?>
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title"><code><?= $this->e((string) $variant['sku']) ?></code> · <?= $this->e((string) $variant['name']) ?></h2>
                <p class="card__subtitle">
                    On hand: <?= $this->e(Format::qty((string) ($variant['on_hand'] ?? '0.000'))) ?> <?= $this->e((string) $variant['unit']) ?>
                    · Avg cost: <?= $variant['avg_cost'] !== null ? $this->e(Format::peso((string) $variant['avg_cost'])) : 'Not costed yet' ?>
                </p>
            </div>
            <?= $this->partial('partials/badge', ['label' => ucfirst($vStatus), 'tone' => $statusTones[$vStatus] ?? 'neutral']) ?>
        </div>
        <form method="post" action="<?= $this->e($adminBase . '/variants/' . $vid) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-sku', 'name' => 'sku', 'label' => 'SKU',
                    'value' => (string) $vInput['sku'], 'required' => true, 'disabled' => !$canEdit,
                    'error' => $vErr['sku'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-barcode', 'name' => 'barcode', 'label' => 'Barcode',
                    'value' => (string) $vInput['barcode'], 'disabled' => !$canEdit,
                    'error' => $vErr['barcode'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-name', 'name' => 'name', 'label' => 'Variant label',
                    'value' => (string) $vInput['name'], 'required' => true, 'disabled' => !$canEdit,
                    'error' => $vErr['name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-status', 'name' => 'status', 'label' => 'Status', 'type' => 'select',
                    'value' => (string) $vInput['status'],
                    'options' => ['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'],
                    'disabled' => !$canEdit,
                    'error' => $vErr['status'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-retail', 'name' => 'retail_price', 'label' => 'Retail price (₱ VAT-incl.)',
                    'value' => (string) $vInput['retail_price'], 'required' => true, 'disabled' => !$canEdit,
                    'error' => $vErr['retail_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-trade', 'name' => 'trade_price', 'label' => 'Trade price (₱ optional)',
                    'value' => (string) $vInput['trade_price'], 'disabled' => !$canEdit,
                    'error' => $vErr['trade_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-unit', 'name' => 'unit', 'label' => 'Unit',
                    'value' => (string) $vInput['unit'], 'required' => true, 'disabled' => !$canEdit,
                    'error' => $vErr['unit'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-usize', 'name' => 'unit_size', 'label' => 'Unit size (optional)',
                    'value' => (string) $vInput['unit_size'], 'disabled' => !$canEdit,
                    'error' => $vErr['unit_size'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-step', 'name' => 'qty_step', 'label' => 'Quantity step',
                    'value' => (string) $vInput['qty_step'], 'disabled' => !$canEdit,
                    'error' => $vErr['qty_step'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-minqty', 'name' => 'min_qty', 'label' => 'Minimum order qty',
                    'value' => (string) $vInput['min_qty'], 'disabled' => !$canEdit,
                    'error' => $vErr['min_qty'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'v-' . $vid . '-reorder', 'name' => 'reorder_level', 'label' => 'Reorder level',
                    'value' => (string) $vInput['reorder_level'], 'disabled' => !$canEdit,
                    'error' => $vErr['reorder_level'] ?? null,
                ]) ?>
            </div>
            <div class="inline-actions" style="margin-top: var(--space-3);">
<?php if ($canEdit): ?>
                <input type="hidden" name="allow_decimal_qty" value="0">
<?php endif; ?>
                <label class="field__checkbox">
                    <input type="checkbox" name="allow_decimal_qty" value="1"<?= !empty($vInput['allow_decimal_qty']) ? ' checked' : '' ?><?= !$canEdit ? ' disabled' : '' ?>>
                    <span>Allow decimal quantities</span>
                </label>
<?php if ($canEdit): ?>
                <input type="hidden" name="track_batches" value="0">
<?php endif; ?>
                <label class="field__checkbox">
                    <input type="checkbox" name="track_batches" value="1"<?= !empty($vInput['track_batches']) ? ' checked' : '' ?><?= !$canEdit ? ' disabled' : '' ?>>
                    <span>Track batches / expiry</span>
                </label>
            </div>
<?php if ($canEdit): ?>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Save variant', 'type' => 'submit']) ?>
            </div>
<?php endif; ?>
        </form>
    </section>
<?php endforeach; ?>

<?php if ($canEdit): ?>
<?php
    $nv = $newVariantInput + [
        'sku' => '',
        'barcode' => '',
        'name' => '',
        'unit' => 'pc',
        'unit_size' => '',
        'allow_decimal_qty' => false,
        'qty_step' => '1',
        'min_qty' => '1',
        'track_batches' => false,
        'retail_price' => '',
        'trade_price' => '',
        'reorder_level' => '0',
        'status' => 'active',
    ];
?>
    <section class="card" aria-labelledby="new-variant-heading">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="new-variant-heading">Add variant</h2>
                <p class="card__subtitle">Every new variant records its opening price in Price History.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($adminBase . '/products/' . $productId . '/variants') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'nv-sku', 'name' => 'sku', 'label' => 'SKU',
                    'value' => (string) $nv['sku'], 'placeholder' => 'e.g. KN-ESP-500', 'required' => true,
                    'error' => $newVariantErrors['sku'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-barcode', 'name' => 'barcode', 'label' => 'Barcode',
                    'value' => (string) $nv['barcode'],
                    'error' => $newVariantErrors['barcode'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-name', 'name' => 'name', 'label' => 'Variant label',
                    'value' => (string) $nv['name'], 'placeholder' => 'e.g. 500g Bag', 'required' => true,
                    'error' => $newVariantErrors['name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-status', 'name' => 'status', 'label' => 'Status', 'type' => 'select',
                    'value' => (string) $nv['status'],
                    'options' => ['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'],
                    'error' => $newVariantErrors['status'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-retail', 'name' => 'retail_price', 'label' => 'Retail price (₱ VAT-incl.)',
                    'value' => (string) $nv['retail_price'], 'placeholder' => '640.00', 'required' => true,
                    'error' => $newVariantErrors['retail_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-trade', 'name' => 'trade_price', 'label' => 'Trade price (₱ optional)',
                    'value' => (string) $nv['trade_price'], 'placeholder' => '520.00',
                    'error' => $newVariantErrors['trade_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-unit', 'name' => 'unit', 'label' => 'Unit',
                    'value' => (string) $nv['unit'], 'required' => true,
                    'error' => $newVariantErrors['unit'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-usize', 'name' => 'unit_size', 'label' => 'Unit size (optional)',
                    'value' => (string) $nv['unit_size'],
                    'error' => $newVariantErrors['unit_size'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-step', 'name' => 'qty_step', 'label' => 'Quantity step',
                    'value' => (string) $nv['qty_step'],
                    'error' => $newVariantErrors['qty_step'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-minqty', 'name' => 'min_qty', 'label' => 'Minimum order qty',
                    'value' => (string) $nv['min_qty'],
                    'error' => $newVariantErrors['min_qty'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'nv-reorder', 'name' => 'reorder_level', 'label' => 'Reorder level',
                    'value' => (string) $nv['reorder_level'],
                    'error' => $newVariantErrors['reorder_level'] ?? null,
                ]) ?>
            </div>
            <div class="inline-actions" style="margin-top: var(--space-3);">
                <input type="hidden" name="allow_decimal_qty" value="0">
                <label class="field__checkbox">
                    <input type="checkbox" name="allow_decimal_qty" value="1"<?= !empty($nv['allow_decimal_qty']) ? ' checked' : '' ?>>
                    <span>Allow decimal quantities</span>
                </label>
                <input type="hidden" name="track_batches" value="0">
                <label class="field__checkbox">
                    <input type="checkbox" name="track_batches" value="1"<?= !empty($nv['track_batches']) ? ' checked' : '' ?>>
                    <span>Track batches / expiry</span>
                </label>
            </div>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Add variant', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
<?php endif; ?>
</div>
