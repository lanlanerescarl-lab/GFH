<?php
/**
 * Catalog sub-navigation across Products, Brands and Categories.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $productsUrl
 * @var string $brandsUrl
 * @var string $categoriesUrl
 * @var string $activeTab 'products' | 'brands' | 'categories'
 */
$tabs = [
    'products' => ['label' => 'Products', 'url' => $productsUrl],
    'brands' => ['label' => 'Brands', 'url' => $brandsUrl],
    'categories' => ['label' => 'Categories', 'url' => $categoriesUrl],
];
?>
<nav class="subnav kit-stack" aria-label="Catalog sections">
<?php foreach ($tabs as $key => $tab): ?>
    <a
        class="subnav__link"
        href="<?= $this->e($tab['url']) ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>
    ><?= $this->e($tab['label']) ?></a>
<?php endforeach; ?>
</nav>
