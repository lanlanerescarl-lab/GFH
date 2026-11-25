<?php
/**
 * Shop home (GET /{org}/shop): hero, top-level category tiles, newest products.
 * An organization changes one part of it by overriding a partial (partials/home-intro is empty by default) or, as a
 * last resort, replaces this whole page by adding orgs/{slug}/shop/home.php.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var string $productsUrl
 * @var string $mediaUrl
 * @var string $tagline the organization's own short line, or "" when none is set
 * @var list<array<string, mixed>> $categories top-level categories that have products (id, name, product_count, children)
 * @var list<array<string, mixed>> $newest newest products (rows of the public listing)
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Home');
$this->end();
?>
<section class="shop-hero" aria-labelledby="shop-hero-title">
    <p class="shop-hero__eyebrow">Online shop</p>
    <h1 class="shop-hero__title" id="shop-hero-title"><?= $this->e($organizationName) ?></h1>
    <p class="shop-hero__line"><?= $this->e($tagline !== '' ? $tagline : 'Browse the full range and find what you need.') ?></p>
    <a class="button shop-hero__cta" href="<?= $this->e($productsUrl) ?>">Shop all products</a>
</section>

<?= $this->partial('partials/home-intro', ['organizationName' => $organizationName, 'productsUrl' => $productsUrl]) ?>

<?php if ($categories !== []): ?>
<section class="shop-section" aria-labelledby="shop-categories-title">
    <h2 class="shop-section__title" id="shop-categories-title">Shop by category</h2>
    <ul class="category-tiles">
<?php foreach ($categories as $category): ?>
        <li>
            <a class="category-tile" href="<?= $this->e($productsUrl . '?category_id=' . rawurlencode((string) $category['id'])) ?>">
                <span class="category-tile__name"><?= $this->e($category['name']) ?></span>
                <span class="category-tile__count"><?= (int) $category['product_count'] ?> <?= (int) $category['product_count'] === 1 ? 'product' : 'products' ?></span>
            </a>
        </li>
<?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="shop-section" aria-labelledby="shop-newest-title">
    <h2 class="shop-section__title" id="shop-newest-title">New arrivals</h2>
<?php if ($newest === []): ?>
    <div class="empty-state">
        <p class="empty-state__title">Products will appear here soon.</p>
    </div>
<?php else: ?>
    <ul class="product-grid">
<?php foreach ($newest as $product): ?>
        <li><?= $this->partial('partials/product-card', ['product' => $product]) ?></li>
<?php endforeach; ?>
    </ul>
    <p class="shop-section__more"><a class="button button--secondary" href="<?= $this->e($productsUrl . '?sort=-newest') ?>">View all products</a></p>
<?php endif; ?>
</section>
