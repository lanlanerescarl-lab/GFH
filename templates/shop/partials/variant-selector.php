<?php
/**
 * Variant choice. A GET form: without JavaScript the shopper picks an option and presses the button, and the server
 * renders that variant (?variant=ID). With JavaScript the variant module updates the price, SKU, availability and
 * quantity rules in place. The data attributes below are everything the module needs.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array<string, mixed>> $variants
 * @var string $selected_variant_id
 * @var string $productUrl
 */
use Gfh\Core\Support\Format;
?>
<form class="variant-form" method="get" action="<?= $this->e($productUrl) ?>" data-variant-form>
    <fieldset class="variant-fieldset">
        <legend>Choose an option</legend>
        <ul class="variant-options">
<?php foreach ($variants as $variant): ?>
<?php $id = (string) $variant['id']; ?>
            <li>
                <label class="variant-option">
                    <input type="radio" name="variant" value="<?= $this->e($id) ?>"<?= $id === $selected_variant_id ? ' checked' : '' ?>
                        data-price="<?= $this->e(Format::peso((string) $variant['retail_price'])) ?>"
                        data-sku="<?= $this->e($variant['sku']) ?>"
                        data-in-stock="<?= !empty($variant['in_stock']) ? '1' : '0' ?>"
                        data-unit="<?= $this->e($variant['unit']) ?>"
                        data-decimal="<?= !empty($variant['allow_decimal_qty']) ? '1' : '0' ?>"
                        data-step="<?= $this->e(Format::qty((string) $variant['qty_step'])) ?>"
                        data-min="<?= $this->e(Format::qty((string) $variant['min_qty'])) ?>">
                    <span class="variant-option__name"><?= $this->e($variant['name']) ?></span>
                    <span class="variant-option__price"><?= $this->e(Format::peso((string) $variant['retail_price'])) ?></span>
                </label>
            </li>
<?php endforeach; ?>
        </ul>
    </fieldset>
    <button class="button button--secondary no-js-only" type="submit">Show this option</button>
</form>
