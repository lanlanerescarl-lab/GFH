<?php
/**
 * Admin POS Sale detail and needs-review resolution screen (GET /{org}/admin/pos-sales/{id}).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $sale
 * @var array<string, mixed>|null $terminal
 * @var string $branchName
 * @var array<string, mixed>|null $customer
 * @var array<string, string> $resolveInput
 * @var array<string, string> $resolveErrors
 * @var bool $canEdit
 * @var int $posReviewCount
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $posSalesUrl
 * @var string $customersUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e((string) $sale['receipt_number']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$id = (string) $sale['id'];
$detailUrl = $posSalesUrl . '/' . $id;
$status = (string) $sale['status'];
$lines = is_array($sale['lines'] ?? null) ? $sale['lines'] : [];
$payments = is_array($sale['payments'] ?? null) ? $sale['payments'] : [];
$discounts = is_array($sale['discounts'] ?? null) ? $sale['discounts'] : [];

$statusLabels = [
    'completed' => 'Completed',
    'needs_review' => 'Needs review',
    'voided' => 'Voided',
];
$statusTones = [
    'completed' => 'ok',
    'needs_review' => 'warn',
    'voided' => 'danger',
];
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'POS receipt',
    'title' => (string) $sale['receipt_number'],
    'subtitle' => sprintf('Sold %s · %s', Format::dateTime((string) $sale['sold_at']), $branchName),
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($posSalesUrl) . '">Back to POS sales</a>',
]) ?>

<?= $this->partial('partials/sales-nav', [
    'ordersUrl' => $ordersUrl,
    'posSalesUrl' => $posSalesUrl,
    'customersUrl' => $customersUrl,
    'posReviewCount' => $posReviewCount,
    'activeTab' => $activeTab,
]) ?>

<?php if ($status === 'needs_review'): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', [
        'tone' => 'warn',
        'title' => 'Needs review',
        'message' => (string) ($sale['review_reason'] ?? 'Flagged during offline sync.'),
    ]) ?>
</div>
<?php endif; ?>

<section class="kit-section">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Receipt summary</h2>
                <p class="card__subtitle">Synced <?= $this->e(Format::dateTime((string) $sale['synced_at'])) ?></p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => $statusLabels[$status] ?? ucfirst($status), 'tone' => $statusTones[$status] ?? 'muted']) ?>
            </div>
        </div>
        <dl class="detail-meta">
            <div><dt>Branch</dt><dd><?= $this->e($branchName) ?></dd></div>
            <div><dt>Terminal</dt><dd><?= $this->e($terminal !== null ? ($terminal['name'] . ' (' . $terminal['code'] . ')') : (string) $sale['terminal_id']) ?></dd></div>
            <div><dt>Customer</dt><dd><?= $this->e($customer !== null ? (string) $customer['full_name'] : 'Walk-in customer') ?></dd></div>
            <div><dt>Subtotal</dt><dd><?= $this->e(Format::peso((string) $sale['subtotal'])) ?></dd></div>
            <div><dt>Discounts</dt><dd><?= $this->e(Format::peso((string) $sale['discount_total'])) ?></dd></div>
            <div><dt>VAT (12% included)</dt><dd><?= $this->e(Format::peso((string) $sale['vat_amount'])) ?></dd></div>
            <div><dt>Total</dt><dd><strong><?= $this->e(Format::peso((string) $sale['total'])) ?></strong></dd></div>
        </dl>
    </section>
</section>

<section class="kit-section" aria-labelledby="pos-lines-heading">
    <section class="card">
        <div class="card__header">
            <h2 class="card__title" id="pos-lines-heading">Line items</h2>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col" class="num">Qty</th>
                        <th scope="col" class="num">Unit price</th>
                        <th scope="col" class="num">Unit cost</th>
                        <th scope="col" class="num">Discount</th>
                        <th scope="col" class="num">Line total</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($lines as $line): ?>
                    <tr>
                        <td>
                            <strong><?= $this->e((string) $line['name']) ?></strong>
                            <div class="cell-meta"><?= $this->e((string) $line['sku']) ?></div>
                        </td>
                        <td class="num"><?= $this->e(Format::qty((string) $line['qty'])) ?></td>
                        <td class="num"><?= $this->e(Format::peso((string) $line['unit_price'])) ?></td>
                        <td class="num"><?= $this->e($line['unit_cost'] !== null ? Format::peso((string) $line['unit_cost']) : '—') ?></td>
                        <td class="num"><?= $this->e(Format::peso((string) ($line['discount'] ?? '0.00'))) ?></td>
                        <td class="num"><?= $this->e(Format::peso((string) $line['line_total'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>

<section class="kit-section">
    <div class="detail-grid">
        <section class="card">
            <div class="card__header">
                <h2 class="card__title">Payments</h2>
            </div>
<?php if ($payments === []): ?>
            <p class="cell-meta">No payments recorded.</p>
<?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Method</th>
                            <th scope="col">Reference</th>
                            <th scope="col" class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= $this->e(ucfirst(str_replace('_', ' ', (string) $payment['method']))) ?></td>
                            <td><?= $this->e((string) ($payment['reference'] ?? '—')) ?></td>
                            <td class="num"><?= $this->e(Format::peso((string) $payment['amount'])) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php endif; ?>
        </section>

        <section class="card">
            <div class="card__header">
                <h2 class="card__title">Discounts applied</h2>
            </div>
<?php if ($discounts === []): ?>
            <p class="cell-meta">No receipt discounts applied.</p>
<?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Description</th>
                            <th scope="col" class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($discounts as $discount): ?>
                        <tr>
                            <td><?= $this->e((string) $discount['label']) ?></td>
                            <td class="num"><?= $this->e(Format::peso((string) $discount['amount'])) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php endif; ?>
        </section>
    </div>
</section>

<?php if ($canEdit && $status === 'needs_review'): ?>
<section class="kit-section" aria-labelledby="pos-resolve-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="pos-resolve-heading">Resolve review</h2>
                <p class="card__subtitle">Record an audit note explaining how the sync flag was verified or adjusted.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($detailUrl . '/resolve') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php if (!empty($resolveErrors['sale'])): ?>
            <p class="field-error" role="alert"><?= $this->e($resolveErrors['sale']) ?></p>
<?php endif; ?>
            <?= $this->partial('partials/field', [
                'id' => 'pos-resolve-note',
                'name' => 'note',
                'label' => 'Resolution note',
                'value' => $resolveInput['note'] ?? '',
                'placeholder' => 'Verified physical stock count and supervisor discount approval…',
                'required' => true,
                'error' => $resolveErrors['note'] ?? null,
            ]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Mark review resolved', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>
