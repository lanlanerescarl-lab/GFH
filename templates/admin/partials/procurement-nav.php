<?php
/**
 * Procurement sub-navigation across Purchase Orders and Suppliers.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $ordersUrl
 * @var string $suppliersUrl
 * @var string $activeTab 'orders' | 'suppliers'
 */
$tabs = [
    'orders' => ['label' => 'Purchase orders', 'url' => $ordersUrl],
    'suppliers' => ['label' => 'Suppliers', 'url' => $suppliersUrl],
];
?>
<nav class="subnav kit-stack" aria-label="Procurement sections">
<?php foreach ($tabs as $key => $tab): ?>
    <a
        class="subnav__link"
        href="<?= $this->e($tab['url']) ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>
    ><?= $this->e($tab['label']) ?></a>
<?php endforeach; ?>
</nav>
