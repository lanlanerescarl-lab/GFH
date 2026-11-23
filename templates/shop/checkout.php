<?php
/**
 * The checkout page shell. A page script builds the form from the server's checkout options, re-prices when the
 * choice changes and places the order. No payment is taken in this shop; the page says so in plain words.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $cartUrl
 * @var string $ordersBase
 * @var string $productsUrl
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Checkout');
$this->end();
?>
<section class="checkout" aria-labelledby="checkout-title" data-checkout-page data-cart-url="<?= $this->e($cartUrl) ?>" data-orders-base="<?= $this->e($ordersBase) ?>" data-sign-in-url="<?= $this->e($shopAccount['signInUrl']) ?>">
    <h1 id="checkout-title" tabindex="-1">Checkout</h1>
    <div class="visually-hidden" role="status" aria-live="polite" data-checkout-live></div>
    <noscript><p class="alert alert--error">Checkout needs JavaScript. Please turn it on for this site to place an order.</p></noscript>
    <p data-checkout-loading>Loading checkout…</p>

    <div class="alert alert--error form-errors" tabindex="-1" role="group" aria-labelledby="checkout-errors-title" data-checkout-errors hidden></div>

    <div class="checkout__layout" data-checkout-content hidden>
        <form class="checkout__form" method="post" action="#" novalidate data-checkout-form></form>
        <aside class="checkout__aside" aria-label="Order summary">
            <h2 class="checkout__aside-title">Your order</h2>
            <div data-checkout-lines></div>
            <div data-checkout-summary aria-live="polite"></div>
            <div class="checkout-payment" data-checkout-payment></div>
            <p><a href="<?= $this->e($cartUrl) ?>">Change your cart</a></p>
        </aside>
    </div>
</section>
