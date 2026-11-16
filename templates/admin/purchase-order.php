<?php
/**
 * Admin Purchase Order detail & goods-receiving screen (GET /{org}/admin/procurement/{id}).
 * Shows status, supplier & branch details, Send/Cancel transitions, and Receive form with rejected qty & batch capture.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $po
 * @var array<string, array<string, string>> $receiveInput
 * @var array<string, array<string, string>> $lineErrors
 * @var array<string, string> $formErrors
 * @var bool $canEdit
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $suppliersUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e((string) $po['po_number']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$id = (string) $po['id'];
$status = (string) $po['status'];
$detailUrl = $ordersUrl . '/' . $id;
$lines = is_array($po['lines'] ?? null) ? $po['lines'] : [];

$statusLabels = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'partially_received' => 'Partially received',
    'received' => 'Received',
    'cancelled' => 'Cancelled',
];
$statusTones = [
    'draft' => 'muted',
    'sent' => 'info',
    'partially_received' => 'warn',
    'received' => 'ok',
    'cancelled' => 'danger',
];

$canReceive = $canEdit && in_array($status, ['sent', 'partially_received'], true);
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Purchase order',
    'title' => (string) $po['po_number'],
    'subtitle' => sprintf('%s · %s', (string) $po['supplier_name'], (string) $po['branch_name']),
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($ordersUrl) . '">Back to purchase orders</a>',
]) ?>

<?= $this->partial('partials/procurement-nav', [
    'ordersUrl' => $ordersUrl,
    'suppliersUrl' => $suppliersUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if (!empty($formErrors)): ?>
<div class="kit-stack">
<?php foreach ($formErrors as $msg): ?>
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not complete action', 'message' => $msg]) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>

<section class="kit-section">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Order summary</h2>
                <p class="card__subtitle">Created <?= $this->e(Format::dateTime((string) $po['created_at'])) ?></p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => $statusLabels[$status] ?? ucfirst($status), 'tone' => $statusTones[$status] ?? 'muted']) ?>
            </div>
        </div>
        <dl class="detail-meta">
            <div>
                <dt>Supplier</dt>
                <dd><a class="table-link" href="<?= $this->e($suppliersUrl . '/' . $po['supplier_id']) ?>"><?= $this->e((string) $po['supplier_name']) ?></a></dd>
            </div>
            <div>
                <dt>Receiving branch</dt>
                <dd><?= $this->e((string) $po['branch_name']) ?></dd>
            </div>
            <div>
                <dt>Expected delivery</dt>
                <dd><?= $this->e(!empty($po['expected_on']) ? Format::date((string) $po['expected_on']) : '—') ?></dd>
            </div>
            <div>
                <dt>Sent</dt>
                <dd><?= $this->e(!empty($po['sent_at']) ? Format::dateTime((string) $po['sent_at']) : 'Not sent yet') ?></dd>
            </div>
            <div>
                <dt>Notes</dt>
                <dd><?= $this->e(!empty($po['notes']) ? (string) $po['notes'] : '—') ?></dd>
            </div>
        </dl>

<?php if ($canEdit && in_array($status, ['draft', 'sent'], true)): ?>
        <div class="form-actions">
<?php if ($status === 'draft'): ?>
            <form method="post" action="<?= $this->e($detailUrl . '/send') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Send to supplier', 'variant' => 'primary', 'type' => 'submit']) ?>
            </form>
<?php endif; ?>
            <form method="post" action="<?= $this->e($detailUrl . '/cancel') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Cancel order', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
        </div>
<?php endif; ?>
    </section>
</section>

<section class="kit-section" aria-labelledby="po-lines-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="po-lines-heading"><?= $canReceive ? 'Receive goods' : 'Ordered items' ?></h2>
                <p class="card__subtitle">
                    <?= $canReceive
                        ? 'Enter accepted and rejected quantities. Accepted quantities update branch stock and weighted-average cost.'
                        : 'Ordered, accepted and rejected quantities with line cost.' ?>
                </p>
            </div>
        </div>

<?php if ($canReceive): ?>
        <form method="post" action="<?= $this->e($detailUrl . '/receive') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php endif; ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="num">Unit cost</th>
                            <th scope="col" class="num">Ordered</th>
                            <th scope="col" class="num">Received</th>
                            <th scope="col" class="num">Rejected</th>
                            <th scope="col" class="num">Remaining</th>
<?php if ($canReceive): ?>
                            <th scope="col">Accept qty</th>
                            <th scope="col">Reject qty</th>
                            <th scope="col">Batch &amp; location</th>
<?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach (array_values($lines) as $i => $line): ?>
<?php
    $lineId = (string) $line['id'];
    $ordered = Decimal::parse((string) $line['qty_ordered'], 3) ?? 0;
    $received = Decimal::parse((string) $line['qty_received'], 3) ?? 0;
    $rejected = Decimal::parse((string) $line['qty_rejected'], 3) ?? 0;
    $remaining = max(0, $ordered - $received - $rejected);
    $trackBatches = (bool) ($line['track_batches'] ?? false);
    $in = $receiveInput[$lineId] ?? [];
    $err = $lineErrors[$lineId] ?? [];
?>
                        <tr>
                            <td>
                                <strong><?= $this->e((string) $line['product_name']) ?></strong>
                                <div class="cell-meta">
                                    <?= $this->e((string) $line['sku']) ?> · <?= $this->e((string) $line['variant_name']) ?> (<?= $this->e((string) $line['unit']) ?>)
                                    <?php if ($trackBatches): ?> · <?= $this->partial('partials/badge', ['label' => 'Batch required', 'tone' => 'info']) ?><?php endif; ?>
                                </div>
<?php if (!empty($err['line_id'])): ?>
                                <p class="field-error" role="alert"><?= $this->e($err['line_id']) ?></p>
<?php endif; ?>
                            </td>
                            <td class="num"><?= $this->e(Format::peso((string) $line['unit_cost'])) ?></td>
                            <td class="num"><?= $this->e(Format::qty((string) $line['qty_ordered'])) ?></td>
                            <td class="num"><?= $this->e(Format::qty((string) $line['qty_received'])) ?></td>
                            <td class="num"><?= $this->e(Format::qty((string) $line['qty_rejected'])) ?></td>
                            <td class="num"><?= $this->e(Format::qty(Decimal::format($remaining, 3))) ?></td>
<?php if ($canReceive): ?>
                            <td>
<?php if ($remaining > 0): ?>
                                <input type="hidden" name="lines[<?= $i ?>][line_id]" value="<?= $this->e($lineId) ?>">
                                <?= $this->partial('partials/field', [
                                    'id' => "receive-$i-accept",
                                    'name' => "lines[$i][qty_received]",
                                    'label' => 'Accepted quantity',
                                    'value' => $in['qty_received'] ?? '',
                                    'placeholder' => '0',
                                    'error' => $err['qty_received'] ?? null,
                                ]) ?>
<?php else: ?>
                                <span class="cell-meta">Complete</span>
<?php if (!empty($err['qty_received'])): ?>
                                <p class="field-error" role="alert"><?= $this->e($err['qty_received']) ?></p>
<?php endif; ?>
<?php endif; ?>
                            </td>
                            <td>
<?php if ($remaining > 0): ?>
                                <?= $this->partial('partials/field', [
                                    'id' => "receive-$i-reject",
                                    'name' => "lines[$i][qty_rejected]",
                                    'label' => 'Rejected quantity',
                                    'value' => $in['qty_rejected'] ?? '',
                                    'placeholder' => '0',
                                    'error' => $err['qty_rejected'] ?? null,
                                ]) ?>
<?php else: ?>
                                —
<?php if (!empty($err['qty_rejected'])): ?>
                                <p class="field-error" role="alert"><?= $this->e($err['qty_rejected']) ?></p>
<?php endif; ?>
<?php endif; ?>
                            </td>
                            <td>
<?php if ($remaining > 0): ?>
                                <div class="po-batch-fields">
                                    <?= $this->partial('partials/field', [
                                        'id' => "receive-$i-batch",
                                        'name' => "lines[$i][batch_code]",
                                        'label' => 'Batch code' . ($trackBatches ? ' *' : ''),
                                        'value' => $in['batch_code'] ?? '',
                                        'placeholder' => $trackBatches ? 'LOT-001' : 'Optional',
                                        'error' => $err['batch_code'] ?? null,
                                    ]) ?>
                                    <?= $this->partial('partials/field', [
                                        'id' => "receive-$i-expires",
                                        'name' => "lines[$i][expires_on]",
                                        'type' => 'date',
                                        'label' => 'Expiry date',
                                        'value' => $in['expires_on'] ?? '',
                                        'error' => $err['expires_on'] ?? null,
                                    ]) ?>
                                    <?= $this->partial('partials/field', [
                                        'id' => "receive-$i-bin",
                                        'name' => "lines[$i][bin]",
                                        'label' => 'Bin / shelf',
                                        'value' => $in['bin'] ?? '',
                                        'placeholder' => 'e.g. A-01',
                                        'error' => $err['bin'] ?? null,
                                    ]) ?>
                                </div>
<?php else: ?>
                                —
<?php endif; ?>
                            </td>
<?php endif; ?>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php if ($canReceive): ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Record receipt', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
<?php endif; ?>
    </section>
</section>
