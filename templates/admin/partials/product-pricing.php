<?php
/**
 * Product pricing tab partial: Price Tiers per variant and append-only Price History.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product
 * @var list<array<string, mixed>> $priceHistory
 * @var string $adminBase
 * @var array<string, array<string, mixed>> $newTierInputs
 * @var array<string, array<string, string>> $newTierErrors
 * @var array<string, array<string, mixed>> $tierInputs
 * @var array<string, array<string, string>> $tierErrors
 * @var bool $canEdit
 * @var string $csrf
 */

use Gfh\Core\Support\Format;

$productId = (string) $product['id'];
$variants = $product['variants'] ?? [];
?>
<div class="kit-stack">
<?php foreach ($variants as $variant): ?>
<?php
    $vid = (string) $variant['id'];
    $tiers = $variant['tiers'] ?? [];
    $newInput = ($newTierInputs[$vid] ?? []) + ['basis' => 'quantity', 'tier_key' => '', 'label' => '', 'min_qty' => '1', 'price' => ''];
    $newErr = $newTierErrors[$vid] ?? [];
?>
    <section class="card kit-stack">
        <div class="card__header">
            <div>
                <h2 class="card__title">Price tiers · <?= $this->e((string) $variant['sku']) ?></h2>
                <p class="card__subtitle">Retail price: <?= $this->e(Format::peso((string) $variant['retail_price'])) ?> · Lowest matching active tier applies.</p>
            </div>
        </div>
<?php if ($tiers !== []): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Basis</th>
                        <th scope="col">Tier key</th>
                        <th scope="col">Label</th>
                        <th scope="col" class="data-table__num">Min qty</th>
                        <th scope="col" class="data-table__num">Tier price (₱)</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($tiers as $tier): ?>
<?php
    $tid = (string) $tier['id'];
    $tInput = ($tierInputs[$tid] ?? []) + [
        'tier_key' => (string) ($tier['tier_key'] ?? ''),
        'label' => (string) $tier['label'],
        'min_qty' => (string) $tier['min_qty'],
        'price' => (string) $tier['price'],
    ];
    $tErr = $tierErrors[$tid] ?? [];
?>
                    <tr>
                        <td><?= $this->e((string) $tier['basis']) ?></td>
                        <td>
<?php if ($canEdit && $tier['basis'] !== 'quantity'): ?>
                            <input form="tier-form-<?= $this->e($tid) ?>" type="text" name="tier_key" value="<?= $this->e((string) $tInput['tier_key']) ?>" aria-label="Tier key"<?= isset($tErr['tier_key']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($tErr['tier_key'])): ?><p class="field-error" role="alert"><?= $this->e($tErr['tier_key']) ?></p><?php endif; ?>
<?php else: ?>
                            <?= $this->e((string) ($tier['tier_key'] ?? '—')) ?>
<?php endif; ?>
                        </td>
                        <td>
<?php if ($canEdit): ?>
                            <input form="tier-form-<?= $this->e($tid) ?>" type="text" name="label" value="<?= $this->e((string) $tInput['label']) ?>" aria-label="Tier label"<?= isset($tErr['label']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($tErr['label'])): ?><p class="field-error" role="alert"><?= $this->e($tErr['label']) ?></p><?php endif; ?>
<?php else: ?>
                            <?= $this->e((string) $tier['label']) ?>
<?php endif; ?>
                        </td>
                        <td class="data-table__num">
<?php if ($canEdit): ?>
                            <input form="tier-form-<?= $this->e($tid) ?>" type="text" name="min_qty" value="<?= $this->e((string) $tInput['min_qty']) ?>" aria-label="Minimum quantity"<?= isset($tErr['min_qty']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($tErr['min_qty'])): ?><p class="field-error" role="alert"><?= $this->e($tErr['min_qty']) ?></p><?php endif; ?>
<?php else: ?>
                            <?= $this->e(Format::qty((string) $tier['min_qty'])) ?>
<?php endif; ?>
                        </td>
                        <td class="data-table__num">
<?php if ($canEdit): ?>
                            <input form="tier-form-<?= $this->e($tid) ?>" type="text" name="price" value="<?= $this->e((string) $tInput['price']) ?>" aria-label="Tier price"<?= isset($tErr['price']) ? ' aria-invalid="true"' : '' ?>>
<?php if (isset($tErr['price'])): ?><p class="field-error" role="alert"><?= $this->e($tErr['price']) ?></p><?php endif; ?>
<?php else: ?>
                            <?= $this->e(Format::peso((string) $tier['price'])) ?>
<?php endif; ?>
                        </td>
                        <td>
<?php if ($canEdit): ?>
                            <div class="inline-actions">
                                <form id="tier-form-<?= $this->e($tid) ?>" method="post" action="<?= $this->e($adminBase . '/tiers/' . $tid) ?>">
                                    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                                    <input type="hidden" name="variant_id" value="<?= $this->e($vid) ?>">
                                    <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
                                    <button class="button button--secondary button--sm" type="submit">Save</button>
                                </form>
                                <form method="post" action="<?= $this->e($adminBase . '/tiers/' . $tid . '/delete') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                                    <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
                                    <button class="button button--danger button--sm" type="submit">Delete</button>
                                </form>
                            </div>
<?php endif; ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php else: ?>
        <p class="table-meta">No price tiers defined for this variant yet.</p>
<?php endif; ?>

<?php if ($canEdit): ?>
        <form method="post" action="<?= $this->e($adminBase . '/variants/' . $vid . '/tiers') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="product_id" value="<?= $this->e($productId) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'tier-basis-' . $vid, 'name' => 'basis', 'label' => 'Basis', 'type' => 'select',
                    'value' => (string) $newInput['basis'],
                    'options' => ['quantity' => 'Quantity break', 'customer_tier' => 'Customer tier', 'customer_type' => 'Customer type'],
                    'error' => $newErr['basis'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'tier-key-' . $vid, 'name' => 'tier_key', 'label' => 'Tier / type key (for customer basis)',
                    'value' => (string) $newInput['tier_key'], 'placeholder' => 'e.g. Gold or Dealer',
                    'error' => $newErr['tier_key'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'tier-label-' . $vid, 'name' => 'label', 'label' => 'Label',
                    'value' => (string) $newInput['label'], 'placeholder' => 'e.g. 5 or more / Gold partner', 'required' => true,
                    'error' => $newErr['label'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'tier-minqty-' . $vid, 'name' => 'min_qty', 'label' => 'Minimum quantity',
                    'value' => (string) $newInput['min_qty'], 'required' => true,
                    'error' => $newErr['min_qty'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'tier-price-' . $vid, 'name' => 'price', 'label' => 'Tier price (₱)',
                    'value' => (string) $newInput['price'], 'placeholder' => '199.00', 'required' => true,
                    'error' => $newErr['price'] ?? null,
                ]) ?>
            </div>
            <div class="card__footer">
                <?= $this->partial('partials/button', ['label' => 'Add price tier', 'type' => 'submit']) ?>
            </div>
        </form>
<?php endif; ?>
    </section>
<?php endforeach; ?>

    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Price history</h2>
                <p class="card__subtitle">Every retail or trade price recorded when variants are created or updated.</p>
            </div>
        </div>
<?php if ($priceHistory === []): ?>
        <?= $this->partial('partials/empty-state', [
            'title' => 'No price history yet',
            'body' => 'Price history entries appear as soon as variants are added or repriced.',
        ]) ?>
<?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Effective date</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Variant</th>
                        <th scope="col" class="data-table__num">Retail price</th>
                        <th scope="col" class="data-table__num">Trade price</th>
                        <th scope="col" class="data-table__num">Cost</th>
                        <th scope="col">Recorded</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($priceHistory as $entry): ?>
                    <tr>
                        <td><?= $this->e(Format::date((string) $entry['effective_on'])) ?></td>
                        <td><code><?= $this->e((string) $entry['sku']) ?></code></td>
                        <td><?= $this->e((string) $entry['variant_name']) ?></td>
                        <td class="data-table__num"><?= $this->e(Format::peso((string) $entry['retail_price'])) ?></td>
                        <td class="data-table__num"><?= $entry['trade_price'] !== null ? $this->e(Format::peso((string) $entry['trade_price'])) : '—' ?></td>
                        <td class="data-table__num"><?= $entry['cost'] !== null ? $this->e(Format::peso((string) $entry['cost'])) : '—' ?></td>
                        <td><?= $this->e(Format::dateTime((string) $entry['created_at'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </section>
</div>
