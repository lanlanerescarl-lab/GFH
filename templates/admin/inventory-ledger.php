<?php
/**
 * Admin Inventory Movement Ledger screen (GET /{org}/admin/inventory/ledger).
 * Displays append-only stock_movements with search, sort, filter and pagination.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, pages: int}, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var list<array<string, mixed>> $branches
 * @var list<array<string, mixed>> $variants
 * @var string $baseUrl
 * @var string $ledgerUrl
 * @var string $reorderUrl
 * @var bool $canEdit
 * @var string $activeTab
 * @var array<string, string> $filters
 */

use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Movement ledger · Inventory');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$movementTypes = [
    'receipt' => 'Receipt',
    'sale_pos' => 'POS sale',
    'sale_online' => 'Online sale',
    'adjustment' => 'Adjustment',
    'write_off' => 'Write-off',
    'return_in' => 'Return in',
    'return_out' => 'Return out',
    'transfer_in' => 'Transfer in',
    'transfer_out' => 'Transfer out',
    'convert_in' => 'Conversion in',
    'convert_out' => 'Conversion out',
];

$filtersHtml = '<label class="visually-hidden" for="ledger-filter-branch">Branch</label>'
    . '<select id="ledger-filter-branch" name="branch_id"><option value="">All branches</option>';
foreach ($branches as $b) {
    $id = (string) $b['id'];
    $filtersHtml .= '<option value="' . $this->e($id) . '"' . ($filters['branch_id'] === $id ? ' selected' : '') . '>' . $this->e((string) $b['name']) . '</option>';
}
$filtersHtml .= '</select>';

$filtersHtml .= '<label class="visually-hidden" for="ledger-filter-variant">Variant</label>'
    . '<select id="ledger-filter-variant" name="variant_id"><option value="">All variants</option>';
foreach ($variants as $v) {
    $id = (string) $v['id'];
    $filtersHtml .= '<option value="' . $this->e($id) . '"' . ($filters['variant_id'] === $id ? ' selected' : '') . '>' . $this->e((string) $v['sku'] . ' · ' . (string) $v['product_name']) . '</option>';
}
$filtersHtml .= '</select>';

$filtersHtml .= '<label class="visually-hidden" for="ledger-filter-type">Movement type</label>'
    . '<select id="ledger-filter-type" name="type"><option value="">All types</option>';
foreach ($movementTypes as $typeKey => $typeLabel) {
    $filtersHtml .= '<option value="' . $this->e($typeKey) . '"' . ($filters['type'] === $typeKey ? ' selected' : '') . '>' . $this->e($typeLabel) . '</option>';
}
$filtersHtml .= '</select>';

$filtersHtml .= '<label class="visually-hidden" for="ledger-filter-from">From date</label>'
    . '<input type="date" id="ledger-filter-from" name="from" value="' . $this->e($filters['from']) . '" title="From date">'
    . '<label class="visually-hidden" for="ledger-filter-to">To date</label>'
    . '<input type="date" id="ledger-filter-to" name="to" value="' . $this->e($filters['to']) . '" title="To date">';

$columns = [
    ['label' => 'Occurred (Manila)', 'sortKey' => 'time'],
    ['label' => 'SKU', 'sortKey' => 'sku'],
    ['label' => 'Product', 'sortKey' => 'product'],
    ['label' => 'Branch'],
    ['label' => 'Type', 'sortKey' => 'type'],
    ['label' => 'Quantity', 'numeric' => true],
    ['label' => 'Unit cost', 'numeric' => true],
    ['label' => 'Batch'],
    ['label' => 'Reason / Reference'],
];

$rows = [];
foreach ($result['items'] as $row) {
    $typeKey = (string) $row['movement_type'];
    $typeLabel = $movementTypes[$typeKey] ?? $typeKey;
    $tone = match ($typeKey) {
        'receipt', 'return_in', 'transfer_in', 'convert_in' => 'ok',
        'write_off' => 'danger',
        'adjustment' => 'warn',
        default => 'info',
    };

    $deltaMilli = Decimal::parse((string) $row['quantity_delta'], 3) ?? 0;
    $deltaText = ($deltaMilli > 0 ? '+' : '') . Format::qty((string) $row['quantity_delta']);
    $unitCost = '—';
    if ($row['unit_cost'] !== null) {
        $costUnits = Decimal::parse((string) $row['unit_cost'], 4);
        $formattedCost = $costUnits !== null ? Decimal::format($costUnits, 4) : (string) $row['unit_cost'];
        [$whole, $frac] = explode('.', $formattedCost, 2);
        $unitCost = '₱' . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) . '.' . $frac;
    }

    $reasonParts = [];
    if (!empty($row['reason'])) {
        $reasonParts[] = (string) $row['reason'];
    }
    if (!empty($row['reference_type'])) {
        $reasonParts[] = str_replace('_', ' ', (string) $row['reference_type']);
    }

    $rows[] = [
        'cells' => [
            ['text' => Format::dateTime((string) $row['occurred_at'])],
            ['html' => '<code>' . $this->e((string) $row['sku']) . '</code>'],
            ['text' => (string) $row['product_name']],
            ['text' => (string) $row['branch_name']],
            ['html' => $this->partial('partials/badge', ['label' => $typeLabel, 'tone' => $tone])],
            ['text' => $deltaText, 'numeric' => true],
            ['text' => $unitCost, 'numeric' => true],
            ['text' => !empty($row['batch_code']) ? (string) $row['batch_code'] : '—'],
            ['text' => $reasonParts !== [] ? implode(' · ', $reasonParts) : '—'],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Movement ledger',
    'subtitle' => 'Append-only audit trail of every stock receipt, sale, adjustment, write-off and conversion.',
    'actionsHtml' => $canEdit ? '<a class="button button--primary button--sm" href="' . $this->e($baseUrl . '#adjust-stock') . '">New adjustment</a>' : '',
]) ?>

<?= $this->partial('partials/inventory-nav', [
    'baseUrl' => $baseUrl,
    'ledgerUrl' => $ledgerUrl,
    'reorderUrl' => $reorderUrl,
    'activeTab' => $activeTab,
]) ?>

<section class="kit-section" aria-label="Stock movement ledger">
    <?= $this->partial('partials/server-table', [
        'id' => 'inventory-ledger-table',
        'action' => $ledgerUrl,
        'searchLabel' => 'Search movement ledger',
        'searchPlaceholder' => 'Search product, SKU or reason…',
        'searchValue' => $filters['q'],
        'sort' => $result['sort'],
        'perPage' => $query->perPage,
        'filters' => [
            'branch_id' => $filters['branch_id'],
            'variant_id' => $filters['variant_id'],
            'type' => $filters['type'],
            'from' => $filters['from'],
            'to' => $filters['to'],
        ],
        'filtersHtml' => $filtersHtml,
        'columns' => $columns,
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'No stock movements found',
        'emptyBody' => 'Try clearing the date range, branch, or movement type filter.',
    ]) ?>
</section>
