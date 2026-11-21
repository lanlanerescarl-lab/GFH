<?php
/**
 * One product in a grid: 4:5 photo area, brand, name and price. The whole card is one link.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $product a row of the public listing
 * @var string $productsUrl
 * @var string $mediaUrl
 */
use Gfh\Core\Support\Format;

$url = $productsUrl . '/' . rawurlencode((string) $product['slug']);
$hasPrice = $product['price_from'] !== null;
?>
<article class="product-card">
    <a class="product-card__link" href="<?= $this->e($url) ?>">
        <span class="media-frame product-card__media">
<?php if (!empty($product['primary_media_id'])): ?>
            <img src="<?= $this->e($mediaUrl . '/' . $product['primary_media_id'] . '?thumb=1') ?>" width="400" height="500" alt="<?= $this->e($product['name']) ?>" loading="lazy" decoding="async">
<?php else: ?>
            <?= $this->partial('partials/placeholder') ?>
<?php endif; ?>
        </span>
        <span class="product-card__body">
<?php if (!empty($product['brand_name'])): ?>
            <span class="product-card__brand"><?= $this->e($product['brand_name']) ?></span>
<?php endif; ?>
            <h3 class="product-card__name"><?= $this->e($product['name']) ?></h3>
<?php if ($hasPrice): ?>
            <span class="product-card__price"><?= (int) $product['variant_count'] > 1 ? 'From ' : '' ?><?= $this->e(Format::peso((string) $product['price_from'])) ?></span>
<?php endif; ?>
<?php if (empty($product['in_stock'])): ?>
            <span class="product-card__flag">Out of stock</span>
<?php endif; ?>
        </span>
    </a>
</article>
