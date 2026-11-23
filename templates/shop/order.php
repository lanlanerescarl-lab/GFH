<?php
/**
 * One of the customer's own orders: lines, totals with VAT, fulfilment, deposit and promised date, payment status with
 * the payments recorded, and the progress timeline. There is no cancel control here.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $order see ShopOrderService::detailFor()
 * @var bool $justPlaced
 * @var string $receiptUrl
 * @var string $ordersUrl
 */
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Order ' . $order['order_number']);
$this->end();

$positive = static fn (mixed $amount): bool => (Decimal::parse((string) $amount, 2) ?? 0) > 0;
?>
<article class="order-page" aria-labelledby="order-title">
    <h1 id="order-title">Order <?= $this->e($order['order_number']) ?></h1>
<?php if ($justPlaced): ?>
    <p class="alert alert--ok" role="status">Thank you, your order is placed. It is unpaid: the shop will confirm with you how to pay.</p>
<?php endif; ?>
<?php if ($order['cancelled_at'] !== null): ?>
    <p class="alert alert--error">This order was cancelled on <?= $this->e(Format::date($order['cancelled_at'])) ?>.</p>
<?php endif; ?>
    <p class="order-page__meta">Placed <?= $this->e(Format::date($order['placed_at'])) ?> · Payment: <span class="badge badge--muted"><?= $this->e($order['payment_label']) ?></span></p>
    <p class="order-page__actions">
        <a class="button button--secondary" href="<?= $this->e($receiptUrl) ?>">Print receipt</a>
        <a href="<?= $this->e($ordersUrl) ?>">Back to my orders</a>
    </p>

    <section class="order-section" aria-labelledby="order-progress-title">
        <h2 id="order-progress-title">Progress</h2>
        <?= $this->partial('partials/order-timeline', ['timeline' => $order['timeline']]) ?>
    </section>

    <section class="order-section" aria-labelledby="order-fulfilment-title">
        <h2 id="order-fulfilment-title"><?= $order['fulfillment_method'] === 'delivery' ? 'Delivery' : 'Pickup' ?></h2>
<?php if ($order['fulfillment_method'] === 'delivery' && is_array($order['ship_to'])): ?>
        <address class="order-address">
<?php foreach (['recipient', 'line1', 'barangay', 'city', 'province', 'postal_code', 'phone'] as $key): ?>
<?php if (($order['ship_to'][$key] ?? '') !== ''): ?>
            <?= $this->e($order['ship_to'][$key]) ?><br>
<?php endif; ?>
<?php endforeach; ?>
        </address>
<?php elseif (is_array($order['pickup_branch'])): ?>
        <p><?= $this->e($order['pickup_branch']['name']) ?><?= $order['pickup_branch']['address'] !== '' ? ', ' . $this->e($order['pickup_branch']['address']) : '' ?></p>
<?php endif; ?>
<?php if ($order['promised_date'] !== null): ?>
        <p>Promised date: <?= $this->e(Format::date($order['promised_date'])) ?></p>
<?php endif; ?>
    </section>

    <section class="order-section" aria-labelledby="order-items-title">
        <h2 id="order-items-title">Items</h2>
        <ul class="cart-lines cart-lines--compact">
<?php foreach ($order['lines'] as $line): ?>
            <li class="cart-line cart-line--compact">
                <div class="cart-line__info">
                    <p class="cart-line__name"><?= $this->e($line['name']) ?></p>
                    <p class="cart-line__price"><?= $this->e(Format::qty($line['qty'])) ?> × <?= $this->e(Format::peso($line['unit_price'])) ?></p>
<?php if ($line['is_made_to_order']): ?>
                    <p class="cart-line__flag">Made to order</p>
<?php endif; ?>
                </div>
                <p class="cart-line__total"><?= $this->e(Format::peso($line['line_total'])) ?></p>
            </li>
<?php endforeach; ?>
        </ul>
    </section>

    <section class="order-section" aria-labelledby="order-totals-title">
        <h2 id="order-totals-title">Totals</h2>
        <dl class="summary">
            <div class="summary__row"><dt>Subtotal</dt><dd><?= $this->e(Format::peso($order['subtotal'])) ?></dd></div>
<?php if ($positive($order['discount_total'])): ?>
            <div class="summary__row"><dt>Discount</dt><dd>-<?= $this->e(Format::peso($order['discount_total'])) ?></dd></div>
<?php endif; ?>
<?php foreach (['delivery_fee' => 'Delivery fee', 'assembly_fee' => 'Assembly fee', 'installation_fee' => 'Installation fee'] as $key => $label): ?>
<?php if ($positive($order[$key])): ?>
            <div class="summary__row"><dt><?= $this->e($label) ?></dt><dd><?= $this->e(Format::peso($order[$key])) ?></dd></div>
<?php endif; ?>
<?php endforeach; ?>
            <div class="summary__row summary__row--total"><dt>Total</dt><dd><?= $this->e(Format::peso($order['total'])) ?></dd></div>
        </dl>
        <p class="help">Prices include VAT. VAT included in the total: <?= $this->e(Format::peso($order['vat_amount'])) ?>.</p>
<?php if ($positive($order['deposit_due'])): ?>
        <p>Deposit due: <?= $this->e(Format::peso($order['deposit_due'])) ?></p>
<?php endif; ?>
    </section>

    <section class="order-section" aria-labelledby="order-payments-title">
        <h2 id="order-payments-title">Payment</h2>
        <p><?= $this->e($order['payment_label']) ?>. Paid so far: <?= $this->e(Format::peso($order['paid'])) ?>. Balance: <?= $this->e(Format::peso($order['balance'])) ?>.</p>
<?php if ($order['payments'] === []): ?>
        <p class="help">No payment has been recorded yet. This shop does not take payment online; it will confirm with you how to pay.</p>
<?php else: ?>
        <ul class="payment-list">
<?php foreach ($order['payments'] as $payment): ?>
            <li><?= $this->e(Format::peso($payment['amount'])) ?> by <?= $this->e(Format::paymentMethod($payment['method'])) ?> on <?= $this->e(Format::date($payment['paid_at'])) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
</article>
