<?php
/**
 * The demonstration organization's introduction on the Shop home page. This partial replaces the empty
 * templates/shop/partials/home-intro.php for this organization only.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var string $productsUrl
 */
?>
<section class="shop-section" aria-labelledby="demo-intro-title">
    <h2 class="shop-section__title" id="demo-intro-title">Welcome to the demonstration shop</h2>
    <p>This is a sample shop with sample products and generated photos. Browse it, add things to the cart and try the checkout: nothing here is a real order, and nothing is ever shipped.</p>
    <p><a href="<?= $this->e($productsUrl) ?>">See what <?= $this->e($organizationName) ?> sells</a></p>
</section>
