<?php
/**
 * The printable receipt for one of the customer's own orders. An order receipt only: it carries no legal or tax-invoice
 * wording. Print styles hide the site header, footer and buttons. An organization can replace this whole file from
 * orgs/{slug}/shop/order-receipt.php.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var array<string, mixed> $order see ShopOrderService::detailFor()
 * @var string $customerName
 * @var string $orderUrl
 */
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Receipt for order ' . $order['order_number']);
$this->end();

$positive = static fn (mixed $amount): bool => (Decimal::parse((string) $amount, 2) ?? 0) > 0;
?>
<article class="receipt" aria-labelledby="receipt-title">
    <p class="receipt__actions">
        <button class="button" type="button" data-print hidden>Print</button>
        <a href="<?= $this->e($orderUrl) ?>">Back to the order</a>
    </p>
    <header class="receipt__head">
        <p class="receipt__org"><?= $this->e($organizationName) ?></p>
        <h1 id="receipt-title">Order receipt</h1>
        <dl class="receipt__meta">
            <div><dt>Order</dt><dd><?= $this->e($order['order_number']) ?></dd></div>
            <div><dt>Date</dt><dd><?= $this->e(Format::date($order['placed_at'])) ?></dd></div>
            <div><dt>Customer</dt><dd><?= $this->e($customerName) ?></dd></div>
        </dl>
    </header>

    <table class="receipt__lines">
        <caption class="visually-hidden">Items on this order</caption>
        <thead><tr><th scope="col">Item</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Price</th><th scope="col" class="num">Amount</th></tr></thead>
        <tbody>
<?php foreach ($order['lines'] as $line): ?>
            <tr>
                <td><?= $this->e($line['name']) ?></td>
                <td class="num"><?= $this->e(Format::qty($line['qty'])) ?></td>
                <td class="num"><?= $this->e(Format::peso($line['unit_price'])) ?></td>
                <td class="num"><?= $this->e(Format::peso($line['line_total'])) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>

    <dl class="summary receipt__totals">
        <div class="summary__row"><dt>Subtotal</dt><dd><?= $this->e(Format::peso($order['subtotal'])) ?></dd></div>
<?php if ($positive($order['discount_total'])): ?>
        <div class="summary__row"><dt>Discounts</dt><dd>-<?= $this->e(Format::peso($order['discount_total'])) ?></dd></div>
<?php endif; ?>
<?php foreach (['delivery_fee' => 'Delivery fee', 'assembly_fee' => 'Assembly fee', 'installation_fee' => 'Installation fee'] as $key => $label): ?>
<?php if ($positive($order[$key])): ?>
        <div class="summary__row"><dt><?= $this->e($label) ?></dt><dd><?= $this->e(Format::peso($order[$key])) ?></dd></div>
<?php endif; ?>
<?php endforeach; ?>
        <div class="summary__row summary__row--total"><dt>Total (VAT included)</dt><dd><?= $this->e(Format::peso($order['total'])) ?></dd></div>
        <div class="summary__row"><dt>Amount without VAT</dt><dd><?= $this->e(Format::peso($order['net_of_vat'])) ?></dd></div>
        <div class="summary__row"><dt>VAT included</dt><dd><?= $this->e(Format::peso($order['vat_amount'])) ?></dd></div>
    </dl>

    <section class="receipt__payments" aria-labelledby="receipt-payments-title">
        <h2 id="receipt-payments-title">Payments</h2>
<?php if ($order['payments'] === []): ?>
        <p>No payment recorded.</p>
<?php else: ?>
        <ul class="payment-list">
<?php foreach ($order['payments'] as $payment): ?>
            <li><?= $this->e(Format::date($payment['paid_at'])) ?> · <?= $this->e(Format::paymentMethod($payment['method'])) ?> · <?= $this->e(Format::peso($payment['amount'])) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <dl class="summary">
            <div class="summary__row"><dt>Paid</dt><dd><?= $this->e(Format::peso($order['paid'])) ?></dd></div>
            <div class="summary__row summary__row--total"><dt>Balance</dt><dd><?= $this->e(Format::peso($order['balance'])) ?></dd></div>
        </dl>
    </section>
</article>
