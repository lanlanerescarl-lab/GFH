<?php
/**
 * Admin Products listing screen (GET /{org}/admin/products).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $categories
 * @var string $productsUrl
 * @var string $brandsUrl
 * @var string $categoriesUrl
 * @var string $newProductUrl
 * @var bool $canEdit
 * @var string $activeTab
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 */

use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Products');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$actionsHtml = $canEdit
    ? '<a class="button" href="' . $this->e($newProductUrl) . '">New product</a>'
    : '';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Products',
    'subtitle' => 'Manage products, variants, attributes, price tiers, price history and photos.',
    'actionsHtml' => $actionsHtml,
]) ?>

<?= $this->partial('partials/products-nav', [
    'productsUrl' => $productsUrl,
    'brandsUrl' => $brandsUrl,
    'categoriesUrl' => $categoriesUrl,
    'activeTab' => $activeTab,
]) ?>

<?php
$brandOptions = ['' => 'All brands'];
foreach ($brands as $brand) {
    $brandOptions[(string) $brand['id']] = (string) $brand['name'];
}

$categoryOptions = ['' => 'All categories'];
foreach ($categories as $top) {
    $categoryOptions[(string) $top['id']] = (string) $top['name'];
    foreach ($top['children'] ?? [] as $child) {
        $categoryOptions[(string) $child['id']] = '— ' . (string) $child['name'];
    }
}

$statusTones = ['active' => 'ok', 'draft' => 'warn', 'discontinued' => 'neutral'];
$rows = [];
foreach ($result['items'] as $row) {
    $id = (string) $row['id'];
    $editHref = $productsUrl . '/' . $id;
    $status = (string) ($row['status'] ?? 'draft');
    $tone = $statusTones[$status] ?? 'neutral';

    $rows[] = [
        'cells' => [
            ['html' => '<a class="table-link" href="' . $this->e($editHref) . '"><strong>' . $this->e((string) $row['name']) . '</strong></a>'
                . '<div class="table-meta">' . $this->e((string) $row['slug']) . '</div>'],
            ['text' => (string) ($row['brand_name'] ?? '—')],
            ['text' => (string) ($row['category_name'] ?? '—')],
            ['text' => (string) ((int) ($row['variant_count'] ?? 0)), 'numeric' => true],
            [
                'html' => $row['price_from'] !== null ? $this->e(Format::peso((string) $row['price_from'])) : '<span class="table-meta">—</span>',
                'numeric' => true,
            ],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $tone])],
            ['html' => '<a class="button button--secondary button--sm" href="' . $this->e($editHref) . '">'
                . $this->e($canEdit ? 'Edit' : 'View') . '</a>'],
        ],
    ];
}

ob_start();
?>
<label class="visually-hidden" for="products-filter-brand">Brand</label>
<select id="products-filter-brand" name="brand_id">
<?php foreach ($brandOptions as $optVal => $optLabel): ?>
    <option value="<?= $this->e((string) $optVal) ?>"<?= $filters['brand_id'] === (string) $optVal ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
<?php endforeach; ?>
</select>
<label class="visually-hidden" for="products-filter-cat">Category</label>
<select id="products-filter-cat" name="category_id">
<?php foreach ($categoryOptions as $optVal => $optLabel): ?>
    <option value="<?= $this->e((string) $optVal) ?>"<?= $filters['category_id'] === (string) $optVal ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
<?php endforeach; ?>
</select>
<label class="visually-hidden" for="products-filter-status">Status</label>
<select id="products-filter-status" name="status">
<?php foreach (['' => 'All statuses', 'active' => 'Active', 'draft' => 'Draft', 'discontinued' => 'Discontinued'] as $optVal => $optLabel): ?>
    <option value="<?= $this->e($optVal) ?>"<?= $filters['status'] === $optVal ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
<?php endforeach; ?>
</select>
<label class="visually-hidden" for="products-filter-min">Min price (₱)</label>
<input id="products-filter-min" type="text" name="min_price" value="<?= $this->e($filters['min_price']) ?>" placeholder="Min ₱">
<label class="visually-hidden" for="products-filter-max">Max price (₱)</label>
<input id="products-filter-max" type="text" name="max_price" value="<?= $this->e($filters['max_price']) ?>" placeholder="Max ₱">
<?php
$filtersHtml = (string) ob_get_clean();
?>
<?php if ($filterErrors !== []): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', [
        'tone' => 'warn',
        'title' => 'Check the filter values',
        'message' => implode(' ', array_values($filterErrors)),
    ]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="products-list-heading">
    <h2 class="visually-hidden" id="products-list-heading">Product catalog</h2>
    <?= $this->partial('partials/server-table', [
        'id' => 'products-table',
        'action' => $productsUrl,
        'searchPlaceholder' => 'Search product, SKU, barcode or brand…',
        'searchValue' => (string) ($query->search ?? ''),
        'sort' => $query->sortLabel,
        'perPage' => $query->perPage,
        'filters' => $filters,
        'filtersHtml' => $filtersHtml,
        'columns' => [
            ['label' => 'Product', 'sortKey' => 'name'],
            ['label' => 'Brand'],
            ['label' => 'Category'],
            ['label' => 'Variants', 'numeric' => true],
            ['label' => 'From price', 'sortKey' => 'price', 'numeric' => true],
            ['label' => 'Status', 'sortKey' => 'status'],
            ['label' => ''],
        ],
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'No products match these filters',
        'emptyBody' => 'Try clearing a filter or create a new product.',
    ]) ?>
</section>
