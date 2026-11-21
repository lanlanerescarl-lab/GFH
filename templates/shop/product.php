<?php
/**
 * Shop product detail (GET /{org}/shop/products/{slug}). The server renders the default variant, so the page is
 * complete without JavaScript; the variant module only updates it in place. The Add to cart control is built but
 * disabled in Batch 6.1 (the cart is Batch 6.2).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $homeUrl
 * @var string $productsUrl
 * @var string $mediaUrl
 * @var array<string, mixed> $product the public product (variants, images, attributes; no costs)
 * @var string $selected_variant_id
 * @var list<array{id: string, title: string, bodyHtml: string}> $sections extension sections (product.detail.sections)
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e($product['name']);
$this->end();

$variants = $product['variants'];
$selected = $variants[0];
foreach ($variants as $variant) {
    if ((string) $variant['id'] === $selected_variant_id) {
        $selected = $variant;
    }
}
$productUrl = $productsUrl . '/' . rawurlencode((string) $product['slug']);
$attributes = is_array($product['attributes']) ? $product['attributes'] : [];
$paragraphs = preg_split('/\R{2,}/', trim((string) ($product['description'] ?? ''))) ?: [];
$hasAnyTiers = false;
foreach ($variants as $variant) {
    $hasAnyTiers = $hasAnyTiers || $variant['tiers'] !== [];
}
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?= $this->e($homeUrl) ?>">Home</a></li>
        <li><a href="<?= $this->e($productsUrl) ?>">Shop</a></li>
<?php if (!empty($product['category_id']) && !empty($product['category_name'])): ?>
        <li><a href="<?= $this->e($productsUrl . '?category_id=' . rawurlencode((string) $product['category_id'])) ?>"><?= $this->e($product['category_name']) ?></a></li>
<?php endif; ?>
        <li><span aria-current="page"><?= $this->e($product['name']) ?></span></li>
    </ol>
</nav>

<div class="product-detail">
    <?= $this->partial('partials/gallery', ['product' => $product]) ?>

    <div class="product-info">
<?php if (!empty($product['brand_name'])): ?>
        <p class="product-info__brand"><a href="<?= $this->e($productsUrl . '?brand_id%5B%5D=' . rawurlencode((string) $product['brand_id'])) ?>"><?= $this->e($product['brand_name']) ?></a></p>
<?php endif; ?>
        <h1 class="product-info__name"><?= $this->e($product['name']) ?></h1>

        <p class="product-info__price" aria-live="polite" data-variant-price><?= $this->e(Format::peso((string) $selected['retail_price'])) ?></p>
        <p class="product-info__vat">Prices include VAT.</p>

<?php if ($hasAnyTiers): ?>
        <?= $this->partial('partials/tier-prices', ['variants' => $variants, 'selected_variant_id' => (string) $selected['id']]) ?>
<?php endif; ?>

<?php if (count($variants) > 1): ?>
        <?= $this->partial('partials/variant-selector', ['variants' => $variants, 'selected_variant_id' => (string) $selected['id'], 'productUrl' => $productUrl]) ?>
<?php endif; ?>

        <dl class="product-facts">
            <div class="product-facts__row">
                <dt>SKU</dt>
                <dd data-variant-sku><?= $this->e($selected['sku']) ?></dd>
            </div>
            <div class="product-facts__row">
                <dt>Availability</dt>
                <dd aria-live="polite" data-variant-availability><?= !empty($selected['in_stock']) ? 'In stock' : 'Out of stock' ?></dd>
            </div>
        </dl>

        <?= $this->partial('partials/add-to-cart', ['product' => $product, 'selected' => $selected]) ?>
    </div>
</div>

<?php if ($paragraphs !== [] && $paragraphs[0] !== ''): ?>
<section class="product-section" aria-labelledby="product-description-title">
    <h2 class="product-section__title" id="product-description-title">Description</h2>
    <div class="product-section__body product-description">
<?php foreach ($paragraphs as $paragraph): ?>
        <p><?= nl2br($this->e(trim($paragraph)), false) ?></p>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($attributes !== []): ?>
<section class="product-section" aria-labelledby="product-details-title">
    <h2 class="product-section__title" id="product-details-title">Details</h2>
    <div class="table-scroll">
        <table class="table attribute-table">
            <caption class="visually-hidden">Product details</caption>
            <tbody>
<?php foreach ($attributes as $key => $value): ?>
                <tr>
                    <th scope="row"><?= $this->e(Format::attributeLabel((string) $key)) ?></th>
                    <td><?= $this->e(Format::attributeValue($value)) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php foreach ($sections as $section): ?>
<section class="product-section product-section--extension" id="<?= $this->e($section['id']) ?>" aria-labelledby="<?= $this->e($section['id']) ?>-title">
    <h2 class="product-section__title" id="<?= $this->e($section['id']) ?>-title"><?= $this->e($section['title']) ?></h2>
    <?php // The extension's own HTML (see docs/extensions.md): it must escape what it prints. ?>
    <div class="product-section__body"><?= $section['bodyHtml'] ?></div>
</section>
<?php endforeach; ?>
