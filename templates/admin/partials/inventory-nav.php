<?php
/**
 * Inventory sub-navigation across Stock levels, Movement ledger and Reorder suggestions.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $baseUrl
 * @var string $ledgerUrl
 * @var string $reorderUrl
 * @var string $activeTab 'levels' | 'ledger' | 'reorder'
 */
$tabs = [
    'levels' => ['label' => 'Stock levels', 'url' => $baseUrl],
    'ledger' => ['label' => 'Movement ledger', 'url' => $ledgerUrl],
    'reorder' => ['label' => 'Reorder suggestions', 'url' => $reorderUrl],
];
?>
<nav class="subnav kit-stack" aria-label="Inventory sections">
<?php foreach ($tabs as $key => $tab): ?>
    <a
        class="subnav__link"
        href="<?= $this->e($tab['url']) ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>
    ><?= $this->e($tab['label']) ?></a>
<?php endforeach; ?>
</nav>
