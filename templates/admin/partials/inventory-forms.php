<?php
/**
 * Stock adjustment and write-off forms (rendered only when the staff member has inventory edit permission).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $csrf
 * @var string $adjustAction
 * @var string $writeOffAction
 * @var string $returnTo
 * @var list<array<string, mixed>> $activeBranches
 * @var list<array<string, mixed>> $variants
 * @var array<string, string> $adjustInput
 * @var array<string, string> $adjustErrors
 * @var array<string, string> $writeOffInput
 * @var array<string, string> $writeOffErrors
 */

$branchOptions = [];
foreach ($activeBranches as $b) {
    $branchOptions[(string) $b['id']] = (string) $b['name'] . ' (' . (string) $b['code'] . ')';
}
$variantOptions = [];
foreach ($variants as $v) {
    $variantOptions[(string) $v['id']] = (string) $v['sku'] . ' · ' . (string) $v['product_name'] . ' — ' . (string) $v['variant_name'];
}
?>
<section class="kit-section" aria-labelledby="inventory-actions-heading">
    <h2 class="kit-section__title" id="inventory-actions-heading">Stock adjustments &amp; write-offs</h2>
    <div class="kit-grid">
        <section class="card" id="adjust-stock" aria-labelledby="adjust-stock-title">
            <div class="card__header">
                <div>
                    <h3 class="card__title" id="adjust-stock-title">Adjust stock</h3>
                    <p class="card__subtitle">Cycle count or manual correction. Positive adds stock; negative removes stock.</p>
                </div>
            </div>
            <form method="post" action="<?= $this->e($adjustAction) ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <input type="hidden" name="return_to" value="<?= $this->e($returnTo) ?>">
                <?= $this->partial('partials/field', [
                    'id' => 'adjust-branch-id',
                    'name' => 'branch_id',
                    'label' => 'Branch',
                    'type' => 'select',
                    'value' => $adjustInput['branch_id'] ?? '',
                    'options' => $branchOptions,
                    'error' => $adjustErrors['branch_id'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'adjust-variant-id',
                    'name' => 'variant_id',
                    'label' => 'Product variant',
                    'type' => 'select',
                    'value' => $adjustInput['variant_id'] ?? '',
                    'options' => $variantOptions,
                    'error' => $adjustErrors['variant_id'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'adjust-delta',
                    'name' => 'delta',
                    'label' => 'Quantity delta (+ or -)',
                    'type' => 'number',
                    'value' => $adjustInput['delta'] ?? '',
                    'step' => '0.001',
                    'placeholder' => 'e.g. 5 or -2',
                    'help' => 'Cannot take stock at this branch below zero.',
                    'error' => $adjustErrors['delta'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'adjust-reason',
                    'name' => 'reason',
                    'label' => 'Reason (required)',
                    'type' => 'text',
                    'value' => $adjustInput['reason'] ?? '',
                    'placeholder' => 'Cycle count reconciliation',
                    'help' => '3 to 255 characters; recorded permanently in the ledger and audit log.',
                    'error' => $adjustErrors['reason'] ?? null,
                    'required' => true,
                ]) ?>
                <div class="card__footer">
                    <?= $this->partial('partials/button', ['label' => 'Record adjustment', 'type' => 'submit', 'variant' => 'primary']) ?>
                </div>
            </form>
        </section>

        <section class="card" id="write-off-stock" aria-labelledby="write-off-stock-title">
            <div class="card__header">
                <div>
                    <h3 class="card__title" id="write-off-stock-title">Write off stock</h3>
                    <p class="card__subtitle">Remove damaged, spoiled, expired or lost stock with a required reason.</p>
                </div>
            </div>
            <form method="post" action="<?= $this->e($writeOffAction) ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <input type="hidden" name="return_to" value="<?= $this->e($returnTo) ?>">
                <?= $this->partial('partials/field', [
                    'id' => 'writeoff-branch-id',
                    'name' => 'branch_id',
                    'label' => 'Branch',
                    'type' => 'select',
                    'value' => $writeOffInput['branch_id'] ?? '',
                    'options' => $branchOptions,
                    'error' => $writeOffErrors['branch_id'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'writeoff-variant-id',
                    'name' => 'variant_id',
                    'label' => 'Product variant',
                    'type' => 'select',
                    'value' => $writeOffInput['variant_id'] ?? '',
                    'options' => $variantOptions,
                    'error' => $writeOffErrors['variant_id'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'writeoff-quantity',
                    'name' => 'quantity',
                    'label' => 'Quantity to write off',
                    'type' => 'number',
                    'value' => $writeOffInput['quantity'] ?? '',
                    'min' => '0.001',
                    'step' => '0.001',
                    'placeholder' => 'e.g. 2',
                    'help' => 'Positive quantity to remove from stock on hand.',
                    'error' => $writeOffErrors['quantity'] ?? null,
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'writeoff-reason',
                    'name' => 'reason',
                    'label' => 'Reason (required)',
                    'type' => 'text',
                    'value' => $writeOffInput['reason'] ?? '',
                    'placeholder' => 'Damaged in storage',
                    'help' => '3 to 255 characters; recorded permanently in the ledger and audit log.',
                    'error' => $writeOffErrors['reason'] ?? null,
                    'required' => true,
                ]) ?>
                <div class="card__footer">
                    <?= $this->partial('partials/button', ['label' => 'Record write-off', 'type' => 'submit', 'variant' => 'danger']) ?>
                </div>
            </form>
        </section>
    </div>
</section>
