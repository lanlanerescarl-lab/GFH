<?php
/**
 * Admin Order detail screen (GET /{org}/admin/orders/{id}).
 * Shows progress steps and payment status separately, plus payment recording, step advancement,
 * cancellation and extension order.detail.panels cards.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $order
 * @var array<string, mixed> $customer
 * @var list<array{id: string, title: string, subtitle: string, bodyHtml: string}> $panels
 * @var array<string, string> $paymentMethods
 * @var array<string, string> $branches
 * @var array<string, string> $advanceErrors
 * @var array<string, string> $paymentInput
 * @var array<string, string> $paymentErrors
 * @var array<string, string> $cancelInput
 * @var array<string, string> $cancelErrors
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
echo $this->e((string) $order['order_number']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$id = (string) $order['id'];
$detailUrl = $ordersUrl . '/' . $id;
$isCancelled = $order['cancelled_at'] !== null;
$path = is_array($order['path'] ?? null) ? $order['path'] : [];
$paymentSummary = is_array($order['payment_summary'] ?? null) ? $order['payment_summary'] : [];
$lines = is_array($order['lines'] ?? null) ? $order['lines'] : [];
$payments = is_array($order['payments'] ?? null) ? $order['payments'] : [];
$history = is_array($order['history'] ?? null) ? $order['history'] : [];

$currentStepLabel = (string) $order['progress_key'];
$isTerminal = false;
$stepOptions = ['' => 'Choose next progress step…'];
$pipelineSteps = [];
$seenCurrent = false;

foreach ($path as $step) {
    $isCurrent = !empty($step['current']);
    if ($isCurrent) {
        $currentStepLabel = (string) $step['label'];
        $isTerminal = !empty($step['is_terminal']);
        $state = $isCancelled ? 'cancelled' : ($isTerminal ? 'done' : 'current');
        $seenCurrent = true;
    } else {
        $state = $seenCurrent ? 'upcoming' : 'done';
        $stepOptions[(string) $step['key']] = (string) $step['label'];
    }
    $pipelineSteps[] = [
        'label' => (string) $step['label'],
        'meta' => $isCurrent ? 'Current' : '',
        'state' => $state,
    ];
}

$paymentLabels = [
    'unpaid' => 'Unpaid',
    'deposit_paid' => 'Deposit paid',
    'paid' => 'Paid',
    'refunded' => 'Refunded',
];
$paymentTones = [
    'unpaid' => 'warn',
    'deposit_paid' => 'info',
    'paid' => 'ok',
    'refunded' => 'muted',
];
$paymentStatus = (string) ($paymentSummary['status'] ?? $order['payment_status']);

$methodOptions = ['' => 'Choose payment method…'] + $paymentMethods;
$fulfillmentBranchId = (string) ($order['pickup_branch_id'] ?? $order['ship_from_branch_id'] ?? '');
$fulfillmentBranch = $branches[$fulfillmentBranchId] ?? $fulfillmentBranchId;
$shipTo = is_array($order['ship_to'] ?? null) ? $order['ship_to'] : null;
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Order detail',
    'title' => (string) $order['order_number'],
    'subtitle' => sprintf('Placed %s · %s', Format::dateTime((string) $order['placed_at']), (string) $customer['full_name']),
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($ordersUrl) . '">Back to orders</a>',
]) ?>

<?= $this->partial('partials/sales-nav', [
    'ordersUrl' => $ordersUrl,
    'posSalesUrl' => $posSalesUrl,
    'customersUrl' => $customersUrl,
    'posReviewCount' => $posReviewCount,
    'activeTab' => $activeTab,
]) ?>

<?php if ($isCancelled): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', [
        'tone' => 'danger',
        'title' => 'Order cancelled on ' . Format::dateTime((string) $order['cancelled_at']),
        'message' => (string) ($order['cancellation_reason'] ?? ''),
    ]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="order-progress-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="order-progress-heading">Fulfilment progress</h2>
                <p class="card__subtitle">Order progress is tracked independently of payments.</p>
            </div>
            <div>
                <?= $this->partial('partials/badge', [
                    'label' => $isCancelled ? 'Cancelled' : $currentStepLabel,
                    'tone' => $isCancelled ? 'danger' : ($isTerminal ? 'ok' : 'info'),
                ]) ?>
            </div>
        </div>

        <?= $this->partial('partials/status-pipeline', [
            'label' => 'Order progress steps',
            'steps' => $pipelineSteps,
        ]) ?>

<?php if (!empty($advanceErrors)): ?>
        <p class="field-error" role="alert"><?= $this->e((string) ($advanceErrors['step'] ?? reset($advanceErrors))) ?></p>
<?php endif; ?>

<?php if ($canEdit && !$isCancelled && !$isTerminal && count($stepOptions) > 1): ?>
        <form class="inline-form kit-stack" method="post" action="<?= $this->e($detailUrl . '/progress') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <?= $this->partial('partials/field', [
                'id' => 'order-next-step',
                'name' => 'progress_key',
                'type' => 'select',
                'label' => 'Move to step',
                'options' => $stepOptions,
                'required' => true,
            ]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Update progress', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
<?php endif; ?>
    </section>
</section>

<section class="kit-section" aria-labelledby="order-payment-heading">
    <div class="detail-grid">
        <section class="card">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="order-payment-heading">Payment status</h2>
                    <p class="card__subtitle">Separate from fulfilment progress.</p>
                </div>
                <div>
                    <?= $this->partial('partials/badge', [
                        'label' => $paymentLabels[$paymentStatus] ?? ucfirst($paymentStatus),
                        'tone' => $paymentTones[$paymentStatus] ?? 'muted',
                    ]) ?>
                    <?php if (!empty($order['refund_review'])): ?>
                        <?= $this->partial('partials/badge', ['label' => 'Refund review required', 'tone' => 'warn']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <dl class="detail-meta">
                <div>
                    <dt>Order total</dt>
                    <dd><?= $this->e(Format::peso((string) $order['total'])) ?></dd>
                </div>
                <div>
                    <dt>Paid</dt>
                    <dd><?= $this->e(Format::peso((string) ($paymentSummary['paid'] ?? '0.00'))) ?></dd>
                </div>
                <div>
                    <dt>Balance remaining</dt>
                    <dd><?= $this->e(Format::peso((string) ($paymentSummary['balance'] ?? $order['total']))) ?></dd>
                </div>
                <div>
                    <dt>Deposit due</dt>
                    <dd><?= $this->e(Format::peso((string) ($paymentSummary['deposit_due'] ?? $order['deposit_due']))) ?></dd>
                </div>
            </dl>

<?php if ($payments !== []): ?>
            <div class="kit-stack table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Method</th>
                            <th scope="col">Reference</th>
                            <th scope="col" class="num">Amount</th>
                            <th scope="col">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= $this->e($paymentMethods[(string) $payment['method']] ?? ucfirst((string) $payment['method'])) ?></td>
                            <td><?= $this->e((string) ($payment['reference'] ?? '—')) ?></td>
                            <td class="num"><?= $this->e(Format::peso((string) $payment['amount'])) ?></td>
                            <td><?= $this->e(Format::dateTime((string) $payment['paid_at'])) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php endif; ?>

<?php if ($canEdit && !$isCancelled): ?>
            <form class="kit-stack" method="post" action="<?= $this->e($detailUrl . '/payments') ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <h3 class="detail-subheading">Record payment</h3>
<?php if (!empty($paymentErrors['order'])): ?>
                <p class="field-error" role="alert"><?= $this->e($paymentErrors['order']) ?></p>
<?php endif; ?>
                <div class="kit-grid">
                    <?= $this->partial('partials/field', [
                        'id' => 'payment-method',
                        'name' => 'method',
                        'type' => 'select',
                        'label' => 'Payment method',
                        'value' => $paymentInput['method'] ?? '',
                        'options' => $methodOptions,
                        'required' => true,
                        'error' => $paymentErrors['method'] ?? null,
                    ]) ?>
                    <?= $this->partial('partials/field', [
                        'id' => 'payment-amount',
                        'name' => 'amount',
                        'label' => 'Amount (PHP)',
                        'value' => $paymentInput['amount'] ?? '',
                        'placeholder' => '500.00',
                        'required' => true,
                        'error' => $paymentErrors['amount'] ?? null,
                    ]) ?>
                    <?= $this->partial('partials/field', [
                        'id' => 'payment-reference',
                        'name' => 'reference',
                        'label' => 'Reference number',
                        'value' => $paymentInput['reference'] ?? '',
                        'placeholder' => 'Required for GCash, Maya, card or bank transfer',
                        'error' => $paymentErrors['reference'] ?? null,
                    ]) ?>
                </div>
                <div class="form-actions">
                    <?= $this->partial('partials/button', ['label' => 'Record payment', 'variant' => 'primary', 'type' => 'submit']) ?>
                </div>
            </form>
<?php endif; ?>
        </section>

        <section class="card">
            <div class="card__header">
                <div>
                    <h2 class="card__title">Customer &amp; fulfilment</h2>
                    <p class="card__subtitle"><?= $this->e(ucfirst((string) $order['channel'])) ?> · <?= $this->e(ucfirst((string) $order['fulfillment_method'])) ?></p>
                </div>
            </div>
            <dl class="detail-meta">
                <div>
                    <dt>Customer</dt>
                    <dd><?= $this->e((string) $customer['full_name']) ?></dd>
                </div>
                <div>
                    <dt>Email / Phone</dt>
                    <dd><?= $this->e((string) ($customer['email'] ?? '—')) ?> · <?= $this->e((string) ($customer['phone'] ?? '—')) ?></dd>
                </div>
                <div>
                    <dt><?= $order['fulfillment_method'] === 'pickup' ? 'Pickup branch' : 'Dispatch branch' ?></dt>
                    <dd><?= $this->e($fulfillmentBranch !== '' ? $fulfillmentBranch : '—') ?></dd>
                </div>
<?php if ($shipTo !== null): ?>
                <div>
                    <dt>Delivery address</dt>
                    <dd><?= $this->e(implode(', ', array_filter([
                        (string) ($shipTo['recipient'] ?? ''),
                        (string) ($shipTo['line1'] ?? ''),
                        (string) ($shipTo['barangay'] ?? ''),
                        (string) ($shipTo['city'] ?? ''),
                        (string) ($shipTo['province'] ?? ''),
                    ]))) ?></dd>
                </div>
<?php endif; ?>
                <div>
                    <dt>Promised date</dt>
                    <dd><?= $this->e(!empty($order['promised_date']) ? Format::date((string) $order['promised_date']) : '—') ?></dd>
                </div>
            </dl>
        </section>
    </div>
</section>

<section class="kit-section" aria-labelledby="order-lines-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="order-lines-heading">Items &amp; totals</h2>
                <p class="card__subtitle">VAT-inclusive pricing with extracted 12% VAT.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col" class="num">Qty</th>
                        <th scope="col" class="num">Unit price</th>
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
                        <td class="num"><?= $this->e(Format::peso((string) ($line['discount'] ?? '0.00'))) ?></td>
                        <td class="num"><?= $this->e(Format::peso((string) $line['line_total'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="detail-meta kit-stack">
            <div><dt>Subtotal</dt><dd><?= $this->e(Format::peso((string) $order['subtotal'])) ?></dd></div>
            <div><dt>Discounts</dt><dd><?= $this->e(Format::peso((string) $order['discount_total'])) ?></dd></div>
            <div><dt>Delivery / Assembly / Install</dt><dd><?= $this->e(Format::peso((string) $order['delivery_fee'])) ?> / <?= $this->e(Format::peso((string) $order['assembly_fee'])) ?> / <?= $this->e(Format::peso((string) $order['installation_fee'])) ?></dd></div>
            <div><dt>VAT (12% included)</dt><dd><?= $this->e(Format::peso((string) $order['vat_amount'])) ?></dd></div>
            <div><dt>Total</dt><dd><strong><?= $this->e(Format::peso((string) $order['total'])) ?></strong></dd></div>
        </dl>
    </section>
</section>

<?php foreach ($panels as $panel): ?>
<section class="kit-section"<?= $panel['id'] !== '' ? ' id="' . $this->e($panel['id']) . '"' : '' ?>>
    <?= $this->partial('partials/card', [
        'title' => $panel['title'],
        'subtitle' => $panel['subtitle'],
        'bodyHtml' => $panel['bodyHtml'],
    ]) ?>
</section>
<?php endforeach; ?>

<?php if ($history !== []): ?>
<section class="kit-section" aria-labelledby="order-history-heading">
    <section class="card">
        <div class="card__header">
            <h2 class="card__title" id="order-history-heading">Progress history</h2>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Step</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($history as $entry): ?>
                    <tr>
                        <td><?= $this->e(Format::dateTime((string) $entry['changed_at'])) ?></td>
                        <td><?= $this->e((string) ($entry['label'] ?? $entry['progress_key'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php endif; ?>

<?php if ($canEdit && !$isCancelled && !$isTerminal): ?>
<section class="kit-section" aria-labelledby="order-cancel-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="order-cancel-heading">Cancel order</h2>
                <p class="card__subtitle">Reserved stock is released. If payments were recorded, the order is flagged for refund review.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($detailUrl . '/cancel') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php if (!empty($cancelErrors['order'])): ?>
            <p class="field-error" role="alert"><?= $this->e($cancelErrors['order']) ?></p>
<?php endif; ?>
            <?= $this->partial('partials/field', [
                'id' => 'order-cancel-reason',
                'name' => 'reason',
                'label' => 'Cancellation reason',
                'value' => $cancelInput['reason'] ?? '',
                'placeholder' => 'Customer requested cancellation…',
                'required' => true,
                'error' => $cancelErrors['reason'] ?? null,
            ]) ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Cancel order', 'variant' => 'danger', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>
