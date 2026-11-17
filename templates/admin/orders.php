<?php
/**
 * Admin Orders list (GET /{org}/admin/orders).
 * Lists online/POS orders with search, sorting, progress/payment/channel/date filters and pagination.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var list<array<string, mixed>> $steps
 * @var list<string> $paymentStatuses
 * @var int $posReviewCount
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $posSalesUrl
 * @var string $customersUrl
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Orders');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$paymentLabels = [
    'unpaid' => 'Unpaid',
    'deposit_paid' => 'Deposit paid',
    'paid' => 'Paid',
    'refunded' => 'Refunded',
];
$paymentTones = [
    'unpaid' => 'warn',
    'deposit_paid' => 'info',
    'paid' => 'ok',
    'refunded' => 'muted',
];

$filtersHtml = '<div class="field"><label class="field__label" for="order-filter-progress">Progress step</label>'
    . '<select id="order-filter-progress" name="progress_key"><option value="">All steps</option>';
foreach ($steps as $step) {
    $key = (string) $step['key'];
    $filtersHtml .= '<option value="' . $this->e($key) . '"' . ($filters['progress_key'] === $key ? ' selected' : '') . '>' . $this->e((string) $step['label']) . '</option>';
}
$filtersHtml .= '</select></div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="order-filter-payment">Payment status</label>'
    . '<select id="order-filter-payment" name="payment_status"><option value="">All payment states</option>';
foreach ($paymentStatuses as $ps) {
    $filtersHtml .= '<option value="' . $this->e($ps) . '"' . ($filters['payment_status'] === $ps ? ' selected' : '') . '>' . $this->e($paymentLabels[$ps] ?? ucfirst($ps)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['payment_status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['payment_status']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="order-filter-channel">Channel</label>'
    . '<select id="order-filter-channel" name="channel">'
    . '<option value="">All channels</option>'
    . '<option value="online"' . ($filters['channel'] === 'online' ? ' selected' : '') . '>Online</option>'
    . '<option value="pos"' . ($filters['channel'] === 'pos' ? ' selected' : '') . '>POS</option>'
    . '</select>'
    . (!empty($filterErrors['channel']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['channel']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="order-filter-cancelled">Cancelled</label>'
    . '<select id="order-filter-cancelled" name="cancelled">'
    . '<option value="">Active &amp; cancelled</option>'
    . '<option value="false"' . ($filters['cancelled'] === 'false' ? ' selected' : '') . '>Active only</option>'
    . '<option value="true"' . ($filters['cancelled'] === 'true' ? ' selected' : '') . '>Cancelled only</option>'
    . '</select></div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="order-filter-from">From date</label>'
    . '<input type="date" id="order-filter-from" name="from" value="' . $this->e($filters['from']) . '">'
    . (!empty($filterErrors['from']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['from']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="order-filter-to">To date</label>'
    . '<input type="date" id="order-filter-to" name="to" value="' . $this->e($filters['to']) . '">'
    . (!empty($filterErrors['to']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['to']) . '</p>' : '')
    . '</div>';

$rows = [];
foreach ($result['items'] as $order) {
    $id = (string) $order['id'];
    $detailUrl = $ordersUrl . '/' . $id;
    $isCancelled = $order['cancelled_at'] !== null;
    $progressBadge = $isCancelled
        ? $this->partial('partials/badge', ['label' => 'Cancelled', 'tone' => 'danger'])
        : $this->partial('partials/badge', ['label' => (string) $order['progress_label'], 'tone' => 'info']);

    $ps = (string) $order['payment_status'];
    $partlyPaid = Format::isPartlyPaid((string) ($order['paid_total'] ?? '0'), (string) $order['total']);
    $paymentBadge = $this->partial('partials/badge', [
        'label' => Format::paymentStatus($ps, (string) ($order['paid_total'] ?? '0'), (string) $order['total']),
        'tone' => $partlyPaid ? 'info' : ($paymentTones[$ps] ?? 'muted'),
    ]);
    if (!empty($order['refund_review'])) {
        $paymentBadge .= ' ' . $this->partial('partials/badge', ['label' => 'Refund review', 'tone' => 'warn']);
    }

    $rows[] = [
        'cells' => [
            ['html' => '<a class="table-link" href="' . $this->e($detailUrl) . '">' . $this->e((string) $order['order_number']) . '</a>'],
            ['text' => (string) $order['customer_name']],
            ['text' => ucfirst((string) $order['fulfillment_method'])],
            ['html' => $progressBadge],
            ['html' => $paymentBadge],
            ['text' => Format::peso((string) $order['total']), 'numeric' => true],
            ['text' => Format::dateTime((string) $order['placed_at'])],
            ['html' => '<a class="button button--ghost button--sm" href="' . $this->e($detailUrl) . '">Open</a>'],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Sales',
    'title' => 'Orders',
    'subtitle' => 'Track order fulfilment progress separately from payment status.',
]) ?>

<?= $this->partial('partials/sales-nav', [
    'ordersUrl' => $ordersUrl,
    'posSalesUrl' => $posSalesUrl,
    'customersUrl' => $customersUrl,
    'posReviewCount' => $posReviewCount,
    'activeTab' => $activeTab,
]) ?>

<?= $this->partial('partials/server-table', [
    'id' => 'orders-table',
    'action' => $ordersUrl,
    'searchLabel' => 'Search orders',
    'searchPlaceholder' => 'Order number or customer…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'columns' => [
        ['label' => 'Order #', 'sortKey' => 'number'],
        ['label' => 'Customer', 'sortKey' => 'customer'],
        ['label' => 'Fulfilment'],
        ['label' => 'Progress'],
        ['label' => 'Payment'],
        ['label' => 'Total', 'sortKey' => 'total', 'numeric' => true],
        ['label' => 'Placed', 'sortKey' => 'placed'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => 'No orders match these filters',
    'emptyBody' => 'Orders placed online appear here with independent progress and payment states.',
]) ?>
