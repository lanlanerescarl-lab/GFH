<?php
/**
 * Add to cart control. The quantity field follows the variant's step and minimum (a decimal-quantity variant takes steps
 * like 0.5; others take whole numbers), and the variant module keeps it, and the form's variant id and decimal flag, in
 * step with the chosen variant. The cart script (cart-badge, add-to-cart) adds to the browser's cart; nothing is sent
 * to the server until the cart page prices it. Without JavaScript the form does nothing, which the note says.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product
 * @var array<string, mixed> $selected the variant shown first
 * @var array{cartUrl?: string} $shopAccount
 */
use Gfh\Core\Support\Format;

$decimal = !empty($selected['allow_decimal_qty']);
$step = Format::qty((string) $selected['qty_step']);
$min = Format::qty((string) $selected['min_qty']);
$cartUrl = (string) (($shopAccount ?? [])['cartUrl'] ?? '');
?>
<form class="add-to-cart" method="get" action="<?= $this->e($cartUrl) ?>" data-add-to-cart data-product-id="<?= $this->e($product['id']) ?>" data-variant-id="<?= $this->e($selected['id']) ?>" data-decimal="<?= $decimal ? '1' : '0' ?>" data-cart-url="<?= $this->e($cartUrl) ?>">
    <fieldset class="add-to-cart__fields">
        <legend class="visually-hidden">Add to cart</legend>
        <div class="field add-to-cart__quantity">
            <label for="product-quantity">Quantity</label>
            <input id="product-quantity" type="number" name="quantity" inputmode="<?= $decimal ? 'decimal' : 'numeric' ?>" step="<?= $this->e($step) ?>" min="<?= $this->e($min) ?>" value="<?= $this->e($min) ?>" aria-describedby="product-quantity-help product-cart-status" data-quantity>
            <p class="help" id="product-quantity-help" data-quantity-help><?= $decimal ? 'Steps of ' . $this->e($step) . '. ' : '' ?>Minimum <?= $this->e($min) ?>.</p>
        </div>
        <button class="button add-to-cart__button" type="submit">Add to cart</button>
    </fieldset>
    <?php // A polite live region: it says "Added to cart" (with a View cart link) or why the quantity was refused. ?>
    <div class="add-to-cart__status" id="product-cart-status" role="status" aria-live="polite" data-cart-status></div>
</form>
