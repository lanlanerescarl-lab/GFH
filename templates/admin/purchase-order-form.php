<?php
/**
 * Admin Purchase Order creation screen (GET /{org}/admin/procurement/new).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var list<array<string, string>> $lines
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $suppliers
 * @var list<array<string, mixed>> $branches
 * @var list<array<string, mixed>> $variants
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $suppliersUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('New purchase order');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$supplierOptions = ['' => 'Choose a supplier…'];
foreach ($suppliers as $supplier) {
    $supplierOptions[(string) $supplier['id']] = (string) $supplier['name'];
}
$branchOptions = ['' => 'Choose a receiving branch…'];
foreach ($branches as $branch) {
    $branchOptions[(string) $branch['id']] = (string) $branch['name'];
}
$variantOptions = ['' => 'Choose a product variant…'];
foreach ($variants as $variant) {
    $variantOptions[(string) $variant['id']] = sprintf('%s — %s (%s, %s)', $variant['sku'], $variant['product_name'], $variant['variant_name'], $variant['unit']);
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Procurement',
    'title' => 'New purchase order',
    'subtitle' => 'Draft a purchase order for an active supplier and branch.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($ordersUrl) . '">Back to purchase orders</a>',
]) ?>

<?= $this->partial('partials/procurement-nav', [
    'ordersUrl' => $ordersUrl,
    'suppliersUrl' => $suppliersUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if (!empty($errors['lines'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Add order lines', 'message' => $errors['lines']]) ?>
</div>
<?php endif; ?>

<section class="kit-section">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Order header &amp; line items</h2>
                <p class="card__subtitle">Empty rows are ignored when saving. Unit cost should be your VAT-exclusive purchase cost in PHP.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($ordersUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'po-supplier',
                    'name' => 'supplier_id',
                    'type' => 'select',
                    'label' => 'Supplier',
                    'value' => $input['supplier_id'] ?? '',
                    'options' => $supplierOptions,
                    'required' => true,
                    'error' => $errors['supplier_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'po-branch',
                    'name' => 'branch_id',
                    'type' => 'select',
                    'label' => 'Receiving branch',
                    'value' => $input['branch_id'] ?? '',
                    'options' => $branchOptions,
                    'required' => true,
                    'error' => $errors['branch_id'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'po-expected-on',
                    'name' => 'expected_on',
                    'type' => 'date',
                    'label' => 'Expected delivery date',
                    'value' => $input['expected_on'] ?? '',
                    'error' => $errors['expected_on'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'po-notes',
                    'name' => 'notes',
                    'label' => 'Notes',
                    'value' => $input['notes'] ?? '',
                    'placeholder' => 'Delivery instructions or reference…',
                    'error' => $errors['notes'] ?? null,
                ]) ?>
            </div>

            <div class="kit-stack">
                <h3 class="detail-subheading">Order lines</h3>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Variant</th>
                                <th scope="col">Quantity</th>
                                <th scope="col">Unit cost (PHP)</th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach (array_values($lines) as $i => $line): ?>
<?php
    $varErr = $errors["lines.$i.variant_id"] ?? null;
    $qtyErr = $errors["lines.$i.quantity"] ?? null;
    $costErr = $errors["lines.$i.unit_cost"] ?? null;
?>
                            <tr>
                                <td>
                                    <?= $this->partial('partials/field', [
                                        'id' => "po-line-$i-variant",
                                        'name' => "lines[$i][variant_id]",
                                        'type' => 'select',
                                        'label' => 'Line ' . ($i + 1) . ' variant',
                                        'value' => $line['variant_id'] ?? '',
                                        'options' => $variantOptions,
                                        'error' => $varErr,
                                    ]) ?>
                                </td>
                                <td>
                                    <?= $this->partial('partials/field', [
                                        'id' => "po-line-$i-qty",
                                        'name' => "lines[$i][quantity]",
                                        'label' => 'Quantity',
                                        'value' => $line['quantity'] ?? '',
                                        'placeholder' => '1',
                                        'error' => $qtyErr,
                                    ]) ?>
                                </td>
                                <td>
                                    <?= $this->partial('partials/field', [
                                        'id' => "po-line-$i-cost",
                                        'name' => "lines[$i][unit_cost]",
                                        'label' => 'Unit cost',
                                        'value' => $line['unit_cost'] ?? '',
                                        'placeholder' => '0.00',
                                        'error' => $costErr,
                                    ]) ?>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save draft PO', 'variant' => 'primary', 'type' => 'submit']) ?>
                <a class="button button--ghost" href="<?= $this->e($ordersUrl) ?>">Cancel</a>
            </div>
        </form>
    </section>
</section>
