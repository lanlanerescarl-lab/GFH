<?php
/**
 * Product details & attributes form partial (shared between New Product and Edit Product Details tab).
 * Displays attributes.{key} validation.attributes errors inline beside each attribute input.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var bool $isNew
 * @var string $formAction
 * @var array<string, mixed> $productInput
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $suppliers
 * @var list<array{key: string, value: string, error: ?string}> $attributeRows
 * @var array<string, mixed>|null $variantInput only on $isNew
 * @var bool $canEdit
 * @var string $csrf
 */
$brandOptions = ['' => '— None —'];
foreach ($brands as $brand) {
    $brandOptions[(string) $brand['id']] = (string) $brand['name'];
}

$categoryOptions = ['' => '— Uncategorised —'];
foreach ($categories as $top) {
    $categoryOptions[(string) $top['id']] = (string) $top['name'];
    foreach ($top['children'] ?? [] as $child) {
        $categoryOptions[(string) $child['id']] = '— ' . (string) $child['name'];
    }
}

$supplierOptions = ['' => '— None —'];
foreach ($suppliers as $supplier) {
    $supplierOptions[(string) $supplier['id']] = (string) $supplier['name'];
}

$variantInput ??= [];
?>
<section class="card">
    <div class="card__header">
        <div>
            <h2 class="card__title"><?= $isNew ? 'Product details' : 'Details & attributes' ?></h2>
            <p class="card__subtitle">Core catalog fields and organization-validated product attributes.</p>
        </div>
    </div>
    <form method="post" action="<?= $this->e($formAction) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
        <div class="kit-grid">
            <?= $this->partial('partials/field', [
                'id' => 'product-name',
                'name' => 'name',
                'label' => 'Product name',
                'value' => (string) ($productInput['name'] ?? ''),
                'placeholder' => 'e.g. Mt. Kanlaon Espresso Beans',
                'required' => true,
                'disabled' => !$canEdit,
                'error' => $errors['name'] ?? null,
            ]) ?>
<?php if ($isNew): ?>
            <?= $this->partial('partials/field', [
                'id' => 'product-status',
                'name' => 'status',
                'label' => 'Initial status',
                'type' => 'select',
                'value' => (string) ($productInput['status'] ?? 'draft'),
                'options' => ['draft' => 'Draft', 'active' => 'Active (requires variant)', 'discontinued' => 'Discontinued'],
                'disabled' => !$canEdit,
                'error' => $errors['status'] ?? null,
            ]) ?>
<?php endif; ?>
            <?= $this->partial('partials/field', [
                'id' => 'product-brand',
                'name' => 'brand_id',
                'label' => 'Brand',
                'type' => 'select',
                'value' => (string) ($productInput['brand_id'] ?? ''),
                'options' => $brandOptions,
                'disabled' => !$canEdit,
                'error' => $errors['brand_id'] ?? null,
            ]) ?>
            <?= $this->partial('partials/field', [
                'id' => 'product-category',
                'name' => 'category_id',
                'label' => 'Category',
                'type' => 'select',
                'value' => (string) ($productInput['category_id'] ?? ''),
                'options' => $categoryOptions,
                'disabled' => !$canEdit,
                'error' => $errors['category_id'] ?? null,
            ]) ?>
            <?= $this->partial('partials/field', [
                'id' => 'product-supplier',
                'name' => 'supplier_id',
                'label' => 'Default supplier',
                'type' => 'select',
                'value' => (string) ($productInput['supplier_id'] ?? ''),
                'options' => $supplierOptions,
                'disabled' => !$canEdit,
                'error' => $errors['supplier_id'] ?? null,
            ]) ?>
        </div>

        <div class="field kit-stack">
            <label class="field__label" for="product-description">Description</label>
            <textarea
                id="product-description"
                name="description"
                rows="3"
                placeholder="Customer-facing product notes…"<?= !$canEdit ? ' disabled' : '' ?><?= isset($errors['description']) ? ' aria-invalid="true" aria-describedby="product-description-error"' : '' ?>
            ><?= $this->e((string) ($productInput['description'] ?? '')) ?></textarea>
<?php if (isset($errors['description'])): ?>
            <p class="field-error" id="product-description-error" role="alert"><?= $this->e($errors['description']) ?></p>
<?php endif; ?>
        </div>

        <fieldset class="attr-editor kit-stack">
            <legend class="field__label">Attributes (specs &amp; organization fields)</legend>
            <p class="help">Leave an existing attribute value blank to remove it. Extensions validate attribute keys via <code>validation.attributes</code>.</p>
<?php if (isset($errors['attributes'])): ?>
            <p class="field-error" role="alert"><?= $this->e($errors['attributes']) ?></p>
<?php endif; ?>
<?php foreach ($attributeRows as $index => $attr): ?>
<?php $attrId = 'attr-val-' . $index; $attrErr = $attr['error']; ?>
            <div class="attr-row">
                <div class="field">
                    <label class="field__label" for="<?= $this->e($attrId) ?>-key">Attribute key</label>
                    <input type="text" id="<?= $this->e($attrId) ?>-key" value="<?= $this->e($attr['key']) ?>" readonly disabled>
                </div>
                <div class="field">
                    <label class="field__label" for="<?= $this->e($attrId) ?>"><?= $this->e($attr['key']) ?> value</label>
                    <input
                        type="text"
                        id="<?= $this->e($attrId) ?>"
                        name="attributes[<?= $this->e($attr['key']) ?>]"
                        value="<?= $this->e($attr['value']) ?>"<?= !$canEdit ? ' disabled' : '' ?><?= $attrErr !== null ? ' aria-invalid="true" aria-describedby="' . $this->e($attrId) . '-error"' : '' ?>
                    >
<?php if ($attrErr !== null): ?>
                    <p class="field-error" id="<?= $this->e($attrId) ?>-error" role="alert"><?= $this->e($attrErr) ?></p>
<?php endif; ?>
                </div>
            </div>
<?php endforeach; ?>
<?php if ($canEdit): ?>
<?php for ($slot = 0; $slot < 2; $slot++): ?>
            <div class="attr-row">
                <div class="field">
                    <label class="field__label" for="attr-new-key-<?= $slot ?>">New attribute key</label>
                    <input type="text" id="attr-new-key-<?= $slot ?>" name="attr_new_key[]" placeholder="e.g. wattage">
                </div>
                <div class="field">
                    <label class="field__label" for="attr-new-val-<?= $slot ?>">New attribute value</label>
                    <input type="text" id="attr-new-val-<?= $slot ?>" name="attr_new_val[]" placeholder="e.g. 1200">
                </div>
            </div>
<?php endfor; ?>
<?php endif; ?>
        </fieldset>

<?php if ($isNew): ?>
        <fieldset class="attr-editor kit-stack">
            <legend class="field__label">Initial variant (optional for draft, required for active)</legend>
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'init-sku',
                    'name' => 'sku',
                    'label' => 'SKU',
                    'value' => (string) ($variantInput['sku'] ?? ''),
                    'placeholder' => 'e.g. KN-ESP-250',
                    'error' => $errors['variants.0.sku'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'init-vname',
                    'name' => 'variant_name',
                    'label' => 'Variant label',
                    'value' => (string) ($variantInput['name'] ?? 'Default'),
                    'placeholder' => 'e.g. 250g Bag',
                    'error' => $errors['variants.0.name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'init-retail',
                    'name' => 'retail_price',
                    'label' => 'Retail price (₱ VAT-incl.)',
                    'value' => (string) ($variantInput['retail_price'] ?? ''),
                    'placeholder' => '340.00',
                    'error' => $errors['variants.0.retail_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'init-trade',
                    'name' => 'trade_price',
                    'label' => 'Trade price (₱ optional)',
                    'value' => (string) ($variantInput['trade_price'] ?? ''),
                    'placeholder' => '285.00',
                    'error' => $errors['variants.0.trade_price'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'init-unit',
                    'name' => 'unit',
                    'label' => 'Unit',
                    'value' => (string) ($variantInput['unit'] ?? 'pc'),
                    'error' => $errors['variants.0.unit'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'init-reorder',
                    'name' => 'reorder_level',
                    'label' => 'Reorder level',
                    'value' => (string) ($variantInput['reorder_level'] ?? '0'),
                    'error' => $errors['variants.0.reorder_level'] ?? null,
                ]) ?>
            </div>
        </fieldset>
<?php endif; ?>

<?php if ($canEdit): ?>
        <div class="card__footer">
            <?= $this->partial('partials/button', ['label' => $isNew ? 'Create product' : 'Save details', 'type' => 'submit']) ?>
        </div>
<?php endif; ?>
    </form>
</section>
