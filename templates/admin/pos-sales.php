<?php
/**
 * Admin POS Sales list & needs-review queue (GET /{org}/admin/pos-sales).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var list<string> $statuses
 * @var list<array<string, mixed>> $terminals
 * @var int $posReviewCount
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $posSalesUrl
 * @var string $customersUrl
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('POS sales');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$statusLabels = [
    'completed' => 'Completed',
    'needs_review' => 'Needs review',
    'voided' => 'Voided',
];
$statusTones = [
    'completed' => 'ok',
    'needs_review' => 'warn',
    'voided' => 'danger',
];

$filtersHtml = '<div class="field"><label class="field__label" for="pos-filter-status">Status</label>'
    . '<select id="pos-filter-status" name="status"><option value="">All statuses</option>';
foreach ($statuses as $st) {
    $filtersHtml .= '<option value="' . $this->e($st) . '"' . ($filters['status'] === $st ? ' selected' : '') . '>' . $this->e($statusLabels[$st] ?? ucfirst($st)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['status']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="pos-filter-terminal">Terminal</label>'
    . '<select id="pos-filter-terminal" name="terminal_id"><option value="">All terminals</option>';
foreach ($terminals as $terminal) {
    $tid = (string) $terminal['id'];
    $filtersHtml .= '<option value="' . $this->e($tid) . '"' . ($filters['terminal_id'] === $tid ? ' selected' : '') . '>' . $this->e((string) $terminal['name'] . ' (' . $terminal['code'] . ')') . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['terminal_id']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['terminal_id']) . '</p>' : '')
    . '</div>';

$chips = [
    [
        'label' => 'All sales',
        'url' => $posSalesUrl,
        'active' => $filters['status'] === '',
    ],
    [
        'label' => 'Needs review (' . $posReviewCount . ')',
        'url' => $posSalesUrl . '?status=needs_review',
        'active' => $filters['status'] === 'needs_review',
    ],
];

$rows = [];
foreach ($result['items'] as $sale) {
    $id = (string) $sale['id'];
    $detailUrl = $posSalesUrl . '/' . $id;
    $status = (string) $sale['status'];

    $rows[] = [
        'cells' => [
            ['html' => '<a class="table-link" href="' . $this->e($detailUrl) . '">' . $this->e((string) $sale['receipt_number']) . '</a>'],
            ['text' => (string) $sale['terminal_code']],
            ['text' => (string) $sale['branch_name']],
            ['html' => $this->partial('partials/badge', ['label' => $statusLabels[$status] ?? ucfirst($status), 'tone' => $statusTones[$status] ?? 'muted'])],
            ['text' => (string) ($sale['review_reason'] ?? '—')],
            ['text' => Format::peso((string) $sale['total']), 'numeric' => true],
            ['text' => Format::dateTime((string) $sale['sold_at'])],
            ['html' => '<a class="button button--ghost button--sm" href="' . $this->e($detailUrl) . '">Inspect</a>'],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Sales',
    'title' => 'POS sales',
    'subtitle' => 'Review synced counter receipts and resolve sales flagged during offline sync.',
]) ?>

<?= $this->partial('partials/sales-nav', [
    'ordersUrl' => $ordersUrl,
    'posSalesUrl' => $posSalesUrl,
    'customersUrl' => $customersUrl,
    'posReviewCount' => $posReviewCount,
    'activeTab' => $activeTab,
]) ?>

<?= $this->partial('partials/server-table', [
    'id' => 'pos-sales-table',
    'action' => $posSalesUrl,
    'searchLabel' => 'Search POS sales',
    'searchPlaceholder' => 'Receipt number or review reason…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'chips' => $chips,
    'columns' => [
        ['label' => 'Receipt #', 'sortKey' => 'receipt'],
        ['label' => 'Terminal'],
        ['label' => 'Branch'],
        ['label' => 'Status'],
        ['label' => 'Review reason'],
        ['label' => 'Total', 'sortKey' => 'total', 'numeric' => true],
        ['label' => 'Sold', 'sortKey' => 'sold'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => $filters['status'] === 'needs_review' ? 'No POS sales waiting for review' : 'No POS sales match these filters',
    'emptyBody' => $filters['status'] === 'needs_review' ? 'Offline counter receipts with stock or discount flags appear here.' : 'Clear filters to see all synced POS receipts.',
]) ?>
