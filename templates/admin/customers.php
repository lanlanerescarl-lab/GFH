<?php
/**
 * Admin Customers screen (GET /{org}/admin/customers).
 * Lists customers with search/sort/filter/pagination and Admin-only one-time password reset link generation.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var list<string> $statuses
 * @var list<string> $types
 * @var array{customer_name: string, email: string, url: string, expires_at: string}|null $resetLink
 * @var string|null $resetError
 * @var bool $canResetLink
 * @var int $posReviewCount
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $posSalesUrl
 * @var string $customersUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Customers');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$filtersHtml = '<div class="field"><label class="field__label" for="customer-filter-status">Status</label>'
    . '<select id="customer-filter-status" name="status"><option value="">All statuses</option>';
foreach ($statuses as $st) {
    $filtersHtml .= '<option value="' . $this->e($st) . '"' . ($filters['status'] === $st ? ' selected' : '') . '>' . $this->e(ucfirst($st)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['status']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="customer-filter-type">Type</label>'
    . '<select id="customer-filter-type" name="customer_type"><option value="">All types</option>';
foreach ($types as $tp) {
    $filtersHtml .= '<option value="' . $this->e($tp) . '"' . ($filters['customer_type'] === $tp ? ' selected' : '') . '>' . $this->e(ucfirst($tp)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['customer_type']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['customer_type']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="customer-filter-tier">Price tier</label>'
    . '<input type="text" id="customer-filter-tier" name="tier" value="' . $this->e($filters['tier']) . '" placeholder="e.g. wholesale">'
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="customer-filter-account">Online account</label>'
    . '<select id="customer-filter-account" name="has_account">'
    . '<option value="">All customers</option>'
    . '<option value="1"' . ($filters['has_account'] === '1' ? ' selected' : '') . '>With account only</option>'
    . '</select></div>';

$rows = [];
foreach ($result['items'] as $customer) {
    $id = (string) $customer['id'];
    $hasAccount = $customer['core_customer_id'] !== null && !empty($customer['email']);
    $status = (string) $customer['status'];

    $actionHtml = '—';
    if ($canResetLink) {
        if ($hasAccount) {
            $actionHtml = '<form method="post" action="' . $this->e($customersUrl . '/' . $id . '/reset-link') . '">'
                . '<input type="hidden" name="_csrf" value="' . $this->e($csrf) . '">'
                . '<button class="button button--secondary button--sm" type="submit">Issue reset link</button>'
                . '</form>';
        } else {
            $actionHtml = '<span class="cell-meta">No online account</span>';
        }
    }

    $rows[] = [
        'cells' => [
            ['text' => (string) $customer['full_name']],
            ['text' => (string) ($customer['email'] ?? '—')],
            ['text' => (string) ($customer['phone'] ?? '—')],
            ['text' => ucfirst((string) $customer['customer_type'])],
            ['text' => (string) ($customer['tier'] ?? 'retail')],
            ['text' => (string) ((int) ($customer['order_count'] ?? 0)), 'numeric' => true],
            ['text' => Format::peso((string) ($customer['lifetime_total'] ?? '0.00')), 'numeric' => true],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $status === 'active' ? 'ok' : 'muted'])],
            ['html' => $actionHtml],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Sales',
    'title' => 'Customers',
    'subtitle' => 'View customer spend history. Organization Admins can issue one-time password reset links.',
]) ?>
<p class="cell-meta" id="customers-lifetime-note">Lifetime spent adds up the totals of every order that is not cancelled, whether or not it has been paid. It is not money received.</p>

<?= $this->partial('partials/sales-nav', [
    'ordersUrl' => $ordersUrl,
    'posSalesUrl' => $posSalesUrl,
    'customersUrl' => $customersUrl,
    'posReviewCount' => $posReviewCount,
    'activeTab' => $activeTab,
]) ?>

<?php if ($resetError !== null && $resetError !== ''): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not issue reset link', 'message' => $resetError]) ?>
</div>
<?php endif; ?>

<?php if ($resetLink !== null): ?>
<section class="kit-section" aria-labelledby="customer-reset-link-heading">
    <section class="card reset-link-card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="customer-reset-link-heading">One-time password reset link ready</h2>
                <p class="card__subtitle">
                    For <?= $this->e($resetLink['customer_name']) ?> (<?= $this->e($resetLink['email']) ?>) · Expires <?= $this->e($resetLink['expires_at']) ?>
                </p>
            </div>
        </div>
        <p class="reset-link-url"><code><?= $this->e($resetLink['url']) ?></code></p>
        <p class="cell-meta">Share this link directly with the customer. It works once and is not stored in logs or shown again after leaving this page.</p>
    </section>
</section>
<?php endif; ?>

<?= $this->partial('partials/server-table', [
    'id' => 'customers-table',
    'action' => $customersUrl,
    'searchLabel' => 'Search customers',
    'searchPlaceholder' => 'Name, email or phone…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'columns' => [
        ['label' => 'Customer', 'sortKey' => 'name'],
        ['label' => 'Email', 'sortKey' => 'email'],
        ['label' => 'Phone'],
        ['label' => 'Type'],
        ['label' => 'Tier'],
        ['label' => 'Orders', 'sortKey' => 'orders', 'numeric' => true],
        ['label' => 'Lifetime spent', 'sortKey' => 'spent', 'numeric' => true],
        ['label' => 'Status'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => 'No customers match these filters',
    'emptyBody' => 'Customers from online checkout and POS appear here.',
]) ?>
