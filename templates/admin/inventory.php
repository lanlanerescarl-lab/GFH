<?php
/**
 * Admin Inventory Stock Levels screen (GET /{org}/admin/inventory).
 * Shows stock by branch and variant with search, sort, filter and pagination, plus adjustment/write-off forms.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, pages: int}, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var list<array<string, mixed>> $branches
 * @var list<array<string, mixed>> $activeBranches
 * @var list<array<string, mixed>> $variants
 * @var string $baseUrl
 * @var string $ledgerUrl
 * @var string $reorderUrl
 * @var string $adjustAction
 * @var string $writeOffAction
 * @var string $returnTo
 * @var bool $canEdit
 * @var string $activeTab
 * @var string $csrf
 * @var array<string, string> $adjustInput
 * @var array<string, string> $adjustErrors
 * @var array<string, string> $writeOffInput
 * @var array<string, string> $writeOffErrors
 * @var array<string, string> $filters
 */

use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Inventory');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$headerActions = '';
if ($canEdit) {
    $headerActions = '<a class="button button--secondary button--sm" href="#adjust-stock">Adjust stock</a> '
        . '<a class="button button--danger button--sm" href="#write-off-stock">Write off stock</a>';
}

$filtersHtml = '<label class="visually-hidden" for="levels-filter-branch">Branch</label>'
    . '<select id="levels-filter-branch" name="branch_id"><option value="">All branches</option>';
foreach ($branches as $b) {
    $id = (string) $b['id'];
    $filtersHtml .= '<option value="' . $this->e($id) . '"' . ($filters['branch_id'] === $id ? ' selected' : '') . '>' . $this->e((string) $b['name']) . '</option>';
}
$filtersHtml .= '</select>';

$filtersHtml .= '<label class="visually-hidden" for="levels-filter-variant">Variant</label>'
    . '<select id="levels-filter-variant" name="variant_id"><option value="">All variants</option>';
foreach ($variants as $v) {
    $id = (string) $v['id'];
    $label = (string) $v['sku'] . ' · ' . (string) $v['product_name'];
    $filtersHtml .= '<option value="' . $this->e($id) . '"' . ($filters['variant_id'] === $id ? ' selected' : '') . '>' . $this->e($label) . '</option>';
}
$filtersHtml .= '</select>';

$filtersHtml .= '<label class="visually-hidden" for="levels-filter-low">Stock filter</label>'
    . '<select id="levels-filter-low" name="low_stock">'
    . '<option value=""' . ($filters['low_stock'] === '' ? ' selected' : '') . '>All stock levels</option>'
    . '<option value="1"' . ($filters['low_stock'] === '1' ? ' selected' : '') . '>Low stock only</option>'
    . '</select>';

$columns = [
    ['label' => 'SKU', 'sortKey' => 'sku'],
    ['label' => 'Product & variant', 'sortKey' => 'product'],
    ['label' => 'Branch', 'sortKey' => 'branch'],
    ['label' => 'On hand', 'sortKey' => 'on_hand', 'numeric' => true],
    ['label' => 'Reserved', 'numeric' => true],
    ['label' => 'Available', 'sortKey' => 'available', 'numeric' => true],
    ['label' => 'Reorder at', 'numeric' => true],
    ['label' => 'Status'],
];
if ($canEdit) {
    $columns[] = ['label' => 'Actions'];
}

$rows = [];
foreach ($result['items'] as $row) {
    $onHand = Decimal::parse((string) $row['on_hand'], 3) ?? 0;
    $reorder = Decimal::parse((string) $row['reorder_level'], 3) ?? 0;
    if ($onHand <= 0) {
        $badge = $this->partial('partials/badge', ['label' => 'Out of stock', 'tone' => 'danger']);
    } elseif ($reorder > 0 && $onHand <= $reorder) {
        $badge = $this->partial('partials/badge', ['label' => 'Low stock', 'tone' => 'warn']);
    } else {
        $badge = $this->partial('partials/badge', ['label' => 'In stock', 'tone' => 'ok']);
    }

    $cells = [
        ['html' => '<code>' . $this->e((string) $row['sku']) . '</code>'],
        ['html' => '<strong>' . $this->e((string) $row['product_name']) . '</strong><br><span class="text-muted">' . $this->e((string) $row['variant_name']) . ' (' . $this->e((string) $row['unit']) . ')</span>'],
        ['text' => (string) $row['branch_name']],
        ['text' => Format::qty((string) $row['on_hand']), 'numeric' => true],
        ['text' => Format::qty((string) $row['reserved']), 'numeric' => true],
        ['text' => Format::qty((string) $row['available']), 'numeric' => true],
        ['text' => $reorder > 0 ? Format::qty((string) $row['reorder_level']) : '—', 'numeric' => true],
        ['html' => $badge],
    ];

    if ($canEdit) {
        $prefill = $baseUrl . '?' . http_build_query([
            'prefill_branch' => (string) $row['branch_id'],
            'prefill_variant' => (string) $row['variant_id'],
        ]);
        $cells[] = [
            'html' => '<div class="row-actions">'
                . '<a class="button button--secondary button--sm" href="' . $this->e($prefill . '#adjust-stock') . '">Adjust</a>'
                . '<a class="button button--ghost button--sm" href="' . $this->e($prefill . '#write-off-stock') . '">Write off</a>'
                . '</div>',
        ];
    }

    $rows[] = ['cells' => $cells];
}

$chipParams = array_filter([
    'q' => $filters['q'],
    'branch_id' => $filters['branch_id'],
    'variant_id' => $filters['variant_id'],
    'sort' => $result['sort'],
    'per_page' => $filters['per_page'],
], static fn (string $v): bool => $v !== '');
$chips = [
    [
        'label' => 'All stock',
        'url' => $baseUrl . ($chipParams !== [] ? '?' . http_build_query($chipParams) : ''),
        'active' => $filters['low_stock'] === '',
    ],
    [
        'label' => 'Low stock',
        'url' => $baseUrl . '?' . http_build_query($chipParams + ['low_stock' => '1']),
        'active' => $filters['low_stock'] === '1',
    ],
];
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stock',
    'title' => 'Inventory',
    'subtitle' => 'Stock on hand is the sum of append-only movements per branch and variant.',
    'actionsHtml' => $headerActions,
]) ?>

<?= $this->partial('partials/inventory-nav', [
    'baseUrl' => $baseUrl,
    'ledgerUrl' => $ledgerUrl,
    'reorderUrl' => $reorderUrl,
    'activeTab' => $activeTab,
]) ?>

<section class="kit-section" aria-label="Stock by branch and variant">
    <?= $this->partial('partials/server-table', [
        'id' => 'inventory-levels-table',
        'action' => $baseUrl,
        'searchLabel' => 'Search stock levels',
        'searchPlaceholder' => 'Search product, SKU or variant…',
        'searchValue' => $filters['q'],
        'sort' => $result['sort'],
        'perPage' => $query->perPage,
        'filters' => [
            'branch_id' => $filters['branch_id'],
            'variant_id' => $filters['variant_id'],
            'low_stock' => $filters['low_stock'],
        ],
        'filtersHtml' => $filtersHtml,
        'chips' => $chips,
        'columns' => $columns,
        'rows' => $rows,
        'pagination' => $result['pagination'],
        'emptyTitle' => 'No stock movements match these filters',
        'emptyBody' => 'Receive a purchase order or record a positive stock adjustment to initialize branch inventory.',
    ]) ?>
</section>

<?php if ($canEdit): ?>
<?= $this->partial('partials/inventory-forms', [
    'csrf' => $csrf,
    'adjustAction' => $adjustAction,
    'writeOffAction' => $writeOffAction,
    'returnTo' => $returnTo,
    'activeBranches' => $activeBranches,
    'variants' => $variants,
    'adjustInput' => $adjustInput,
    'adjustErrors' => $adjustErrors,
    'writeOffInput' => $writeOffInput,
    'writeOffErrors' => $writeOffErrors,
]) ?>
<?php endif; ?>
