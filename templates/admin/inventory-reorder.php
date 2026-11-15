<?php
/**
 * Admin Reorder Suggestions screen (GET /{org}/admin/inventory/reorder).
 * Lists active variants whose available stock is at or below reorder_level, accounting for open PO quantities.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, pages: int}, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var list<array<string, mixed>> $suppliers
 * @var string $baseUrl
 * @var string $ledgerUrl
 * @var string $reorderUrl
 * @var string|null $procurementUrl
 * @var string $activeTab
 * @var array<string, string> $filters
 */

use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Reorder suggestions · Inventory');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$filtersHtml = '<label class="visually-hidden" for="reorder-filter-supplier">Supplier</label>'
    . '<select id="reorder-filter-supplier" name="supplier_id"><option value="">All suppliers</option>';
foreach ($suppliers as $s) {
    $id = (string) $s['id'];
    $filtersHtml .= '<option value="' . $this->e($id) . '"' . ($filters['supplier_id'] === $id ? ' selected' : '') . '>' . $this->e((string) $s['name']) . '</option>';
}
$filtersHtml .= '</select>';

$statusOptions = [
    '' => 'All low & out of stock',
    'out_of_stock' => 'Out of stock',
    'low_stock' => 'Low stock (> 0)',
    'needs_po' => 'Needs purchase order',
];
$filtersHtml .= '<label class="visually-hidden" for="reorder-filter-status">Status</label>'
    . '<select id="reorder-filter-status" name="status">';
foreach ($statusOptions as $key => $label) {
    $filtersHtml .= '<option value="' . $this->e($key) . '"' . ($filters['status'] === $key ? ' selected' : '') . '>' . $this->e($label) . '</option>';
}
$filtersHtml .= '</select>';

$columns = [
    ['label' => 'SKU', 'sortKey' => 'sku'],
    ['label' => 'Product & variant', 'sortKey' => 'product'],
    ['label' => 'Supplier', 'sortKey' => 'supplier'],
    ['label' => 'On hand', 'sortKey' => 'on_hand', 'numeric' => true],
    ['label' => 'Available', 'sortKey' => 'available', 'numeric' => true],
    ['label' => 'Reorder at', 'sortKey' => 'reorder_level', 'numeric' => true],
    ['label' => 'On order', 'numeric' => true],
    ['label' => 'Suggested order', 'sortKey' => 'suggested_qty', 'numeric' => true],
    ['label' => 'Status'],
];

$rows = [];
foreach ($result['items'] as $row) {
    $onHand = Decimal::parse((string) $row['on_hand'], 3) ?? 0;
    $suggested = Decimal::parse((string) $row['suggested_qty'], 3) ?? 0;
    if ($onHand <= 0) {
        $badge = $this->partial('partials/badge', ['label' => 'Out of stock', 'tone' => 'danger']);
    } elseif ($suggested > 0) {
        $badge = $this->partial('partials/badge', ['label' => 'Reorder needed', 'tone' => 'warn']);
    } else {
        $badge = $this->partial('partials/badge', ['label' => 'Covered by PO', 'tone' => 'info']);
    }

    $supplierText = !empty($row['supplier_name'])
        ? (string) $row['supplier_name'] . ($row['lead_time_days'] !== null ? ' (' . (string) $row['lead_time_days'] . 'd)' : '')
        : 'Unassigned';

    $rows[] = [
        'cells' => [
            ['html' => '<code>' . $this->e((string) $row['sku']) . '</code>'],
            ['html' => '<strong>' . $this->e((string) $row['product_name']) . '</strong><br><span class="text-muted">' . $this->e((string) $row['variant_name']) . ' (' . $this->e((string) $row['unit']) . ')</span>'],
            ['text' => $supplierText],
            ['text' => Format::qty((string) $row['on_hand']), 'numeric' => true],
            ['text' => Format::qty((string) $row['available']), 'numeric' => true],
            ['text' => Format::qty((string) $row['reorder_level']), 'numeric' => true],
            ['text' => Format::qty((string) $row['on_order']), 'numeric' => true],
            ['html' => '<strong>' . $this->e(Format::qty((string) $row['suggested_qty'])) . '</strong>', 'numeric' => true],
            ['html' => $badge],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Reorder suggestions',
    'subtitle' => 'Active variants whose available stock across branches is at or below their reorder level.',
    'actionsHtml' => $procurementUrl !== null ? '<a class="button button--primary button--sm" href="' . $this->e($procurementUrl) . '">Open procurement</a>' : '',
]) ?>

<?= $this->partial('partials/inventory-nav', [
    'baseUrl' => $baseUrl,
    'ledgerUrl' => $ledgerUrl,
    'reorderUrl' => $reorderUrl,
    'activeTab' => $activeTab,
]) ?>

<section class="kit-section" aria-label="Reorder suggestions">
    <?= $this->partial('partials/server-table', [
        'id' => 'inventory-reorder-table',
        'action' => $reorderUrl,
        'searchLabel' => 'Search reorder suggestions',
        'searchPlaceholder' => 'Search product, SKU or supplier…',
        'searchValue' => $filters['q'],
        'sort' => $result['sort'],
        'perPage' => $query->perPage,
        'filters' => [
            'supplier_id' => $filters['supplier_id'],
            'status' => $filters['status'],
        ],
        'filtersHtml' => $filtersHtml,
        'columns' => $columns,
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'Nothing needs reordering',
        'emptyBody' => 'All active variants with a reorder point have sufficient available stock.',
    ]) ?>
</section>
