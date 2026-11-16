<?php
/**
 * Sales sub-navigation across Orders, POS Sales (with needs-review count badge) and Customers.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $ordersUrl
 * @var string $posSalesUrl
 * @var string $customersUrl
 * @var int $posReviewCount
 * @var string $activeTab 'orders' | 'pos-sales' | 'customers'
 */
$posReviewCount ??= 0;
$tabs = [
    'orders' => ['label' => 'Orders', 'url' => $ordersUrl, 'badge' => 0],
    'pos-sales' => ['label' => 'POS sales', 'url' => $posSalesUrl, 'badge' => (int) $posReviewCount],
    'customers' => ['label' => 'Customers', 'url' => $customersUrl, 'badge' => 0],
];
?>
<nav class="subnav kit-stack" aria-label="Sales sections">
<?php foreach ($tabs as $key => $tab): ?>
    <a
        class="subnav__link"
        href="<?= $this->e($tab['url']) ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>
    >
        <span><?= $this->e($tab['label']) ?></span>
<?php if ($tab['badge'] > 0): ?>
        <?= $this->partial('partials/badge', ['label' => (string) $tab['badge'], 'tone' => 'warn']) ?>
<?php endif; ?>
    </a>
<?php endforeach; ?>
</nav>
