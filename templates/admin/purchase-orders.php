<?php
/**
 * Admin Purchase Orders list (GET /{org}/admin/procurement).
 * Supports search, sorting, status/supplier/branch filtering and pagination.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var list<string> $statuses
 * @var list<array<string, mixed>> $suppliers
 * @var list<array<string, mixed>> $branches
 * @var bool $canEdit
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $suppliersUrl
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Purchase orders');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$statusLabels = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'partially_received' => 'Partially received',
    'received' => 'Received',
    'cancelled' => 'Cancelled',
];
$statusTones = [
    'draft' => 'muted',
    'sent' => 'info',
    'partially_received' => 'warn',
    'received' => 'ok',
    'cancelled' => 'danger',
];

$filtersHtml = '<div class="field"><label class="field__label" for="po-filter-status">Status</label>'
    . '<select id="po-filter-status" name="status"><option value="">All statuses</option>';
foreach ($statuses as $st) {
    $filtersHtml .= '<option value="' . $this->e($st) . '"' . ($filters['status'] === $st ? ' selected' : '') . '>' . $this->e($statusLabels[$st] ?? ucfirst($st)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['status']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="po-filter-supplier">Supplier</label>'
    . '<select id="po-filter-supplier" name="supplier_id"><option value="">All suppliers</option>';
foreach ($suppliers as $supplier) {
    $sid = (string) $supplier['id'];
    $filtersHtml .= '<option value="' . $this->e($sid) . '"' . ($filters['supplier_id'] === $sid ? ' selected' : '') . '>' . $this->e((string) $supplier['name']) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['supplier_id']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['supplier_id']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="po-filter-branch">Receiving branch</label>'
    . '<select id="po-filter-branch" name="branch_id"><option value="">All branches</option>';
foreach ($branches as $branch) {
    $bid = (string) $branch['id'];
    $filtersHtml .= '<option value="' . $this->e($bid) . '"' . ($filters['branch_id'] === $bid ? ' selected' : '') . '>' . $this->e((string) $branch['name']) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['branch_id']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['branch_id']) . '</p>' : '')
    . '</div>';

$rows = [];
foreach ($result['items'] as $po) {
    $id = (string) $po['id'];
    $detailUrl = $ordersUrl . '/' . $id;
    $status = (string) $po['status'];
    $expected = !empty($po['expected_on']) ? Format::date((string) $po['expected_on']) : '—';

    $rows[] = [
        'cells' => [
            ['html' => '<a class="table-link" href="' . $this->e($detailUrl) . '">' . $this->e((string) $po['po_number']) . '</a>'],
            ['text' => (string) $po['supplier_name']],
            ['text' => (string) $po['branch_name']],
            ['html' => $this->partial('partials/badge', ['label' => $statusLabels[$status] ?? ucfirst($status), 'tone' => $statusTones[$status] ?? 'muted'])],
            ['text' => $expected],
            ['text' => Format::peso((string) ($po['total_cost'] ?? '0.00')), 'numeric' => true],
            ['text' => Format::dateTime((string) $po['created_at'])],
            ['html' => '<a class="button button--ghost button--sm" href="' . $this->e($detailUrl) . '">Open</a>'],
        ],
    ];
}

$actionsHtml = $canEdit ? '<a class="button button--primary" href="' . $this->e($ordersUrl . '/new') . '">New purchase order</a>' : '';
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Procurement',
    'title' => 'Purchase orders',
    'subtitle' => 'Order stock from suppliers and receive shipments into weighted-average inventory.',
    'actionsHtml' => $actionsHtml,
]) ?>

<?= $this->partial('partials/procurement-nav', [
    'ordersUrl' => $ordersUrl,
    'suppliersUrl' => $suppliersUrl,
    'activeTab' => $activeTab,
]) ?>

<?= $this->partial('partials/server-table', [
    'id' => 'purchase-orders-table',
    'action' => $ordersUrl,
    'searchLabel' => 'Search purchase orders',
    'searchPlaceholder' => 'PO number or supplier…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'columns' => [
        ['label' => 'PO number', 'sortKey' => 'number'],
        ['label' => 'Supplier', 'sortKey' => 'supplier'],
        ['label' => 'Branch'],
        ['label' => 'Status'],
        ['label' => 'Expected', 'sortKey' => 'expected'],
        ['label' => 'Estimated total', 'numeric' => true],
        ['label' => 'Created', 'sortKey' => 'created'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => 'No purchase orders yet',
    'emptyBody' => 'Create a purchase order to order stock from an active supplier.',
    'emptyActionsHtml' => $canEdit ? '<a class="button button--primary" href="' . $this->e($ordersUrl . '/new') . '">New purchase order</a>' : '',
]) ?>
