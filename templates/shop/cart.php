<?php
/**
 * The cart page. The cart itself is kept in the browser; the page script asks the server to price it and draws the
 * server's answer. Nothing here knows a product, a price or a stock level.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var bool $signedIn
 * @var string $checkoutUrl
 * @var string $productsUrl
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Your cart');
$this->end();
?>
<section class="cart-page" aria-labelledby="cart-title" data-cart-page data-checkout-url="<?= $this->e($checkoutUrl) ?>">
    <h1 id="cart-title" tabindex="-1">Your cart</h1>
    <div class="visually-hidden" role="status" aria-live="polite" data-cart-live></div>
    <noscript><p class="alert alert--error">The cart needs JavaScript. Please turn it on for this site to shop.</p></noscript>

    <div class="empty-state" data-cart-empty hidden>
        <h2 class="empty-state__title">Your cart is empty</h2>
        <p>Add something from the shop and it will show up here.</p>
        <a class="button" href="<?= $this->e($productsUrl) ?>">Browse all products</a>
    </div>

    <div class="cart-page__content" data-cart-content hidden>
        <div class="alert alert--error" data-cart-message hidden></div>
        <div class="cart-page__lines" data-cart-lines></div>
        <aside class="cart-page__summary" aria-label="Order total">
            <div data-cart-summary aria-live="polite"></div>
            <button class="button cart-page__checkout" type="button" data-checkout-button disabled>Continue to checkout</button>
<?php if (!$signedIn): ?>
            <p class="help">You will be asked to sign in or create an account first. Your cart is kept.</p>
<?php endif; ?>
        </aside>
    </div>
</section>
