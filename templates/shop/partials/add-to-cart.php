<?php
/**
 * Add to cart control, built as a form with data attributes for Batch 6.2 but disabled in Batch 6.1: the cart does not
 * exist yet. The quantity field already follows the variant's step and minimum (a decimal-quantity variant takes
 * steps like 0.5; others take whole numbers), and the variant module keeps it in step with the chosen variant.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product
 * @var array<string, mixed> $selected the variant shown first
 */
use Gfh\Core\Support\Format;

$decimal = !empty($selected['allow_decimal_qty']);
$step = Format::qty((string) $selected['qty_step']);
$min = Format::qty((string) $selected['min_qty']);
?>
<form class="add-to-cart" method="post" action="#" data-add-to-cart data-product-id="<?= $this->e($product['id']) ?>" data-variant-id="<?= $this->e($selected['id']) ?>">
    <fieldset class="add-to-cart__fields" disabled>
        <legend class="visually-hidden">Add to cart</legend>
        <div class="field add-to-cart__quantity">
            <label for="product-quantity">Quantity</label>
            <input id="product-quantity" type="number" name="quantity" inputmode="<?= $decimal ? 'decimal' : 'numeric' ?>" step="<?= $this->e($step) ?>" min="<?= $this->e($min) ?>" value="<?= $this->e($min) ?>" aria-describedby="product-quantity-help" data-quantity>
            <p class="help" id="product-quantity-help" data-quantity-help><?= $decimal ? 'Steps of ' . $this->e($step) . '. ' : '' ?>Minimum <?= $this->e($min) ?>.</p>
        </div>
        <button class="button add-to-cart__button" type="submit" disabled>Add to cart</button>
    </fieldset>
    <p class="add-to-cart__note" role="note">The cart opens soon. You can browse and compare products in the meantime.</p>
</form>
