<?php
/**
 * Admin Suppliers screen (GET /{org}/admin/procurement/suppliers).
 * Lists suppliers with search/sort/status filter/pagination and lets editors create new suppliers.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var array<string, string> $createInput
 * @var array<string, string> $createErrors
 * @var bool $canEdit
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $suppliersUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Suppliers');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$filtersHtml = '<div class="field"><label class="field__label" for="supplier-filter-status">Status</label>'
    . '<select id="supplier-filter-status" name="status">'
    . '<option value="">All statuses</option>'
    . '<option value="active"' . ($filters['status'] === 'active' ? ' selected' : '') . '>Active</option>'
    . '<option value="inactive"' . ($filters['status'] === 'inactive' ? ' selected' : '') . '>Inactive</option>'
    . '</select>'
    . (!empty($filterErrors['status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['status']) . '</p>' : '')
    . '</div>';

$rows = [];
foreach ($result['items'] as $supplier) {
    $id = (string) $supplier['id'];
    $detailUrl = $suppliersUrl . '/' . $id;
    $status = (string) $supplier['status'];
    $lead = $supplier['lead_time_days'] !== null ? ((int) $supplier['lead_time_days']) . ' days' : '—';

    $rows[] = [
        'cells' => [
            ['html' => '<a class="table-link" href="' . $this->e($detailUrl) . '">' . $this->e((string) $supplier['name']) . '</a>'],
            ['text' => (string) ($supplier['contact_name'] ?? '—')],
            ['text' => (string) ($supplier['contact_email'] ?? '—')],
            ['text' => (string) ($supplier['contact_phone'] ?? '—')],
            ['text' => $lead, 'numeric' => true],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $status === 'active' ? 'ok' : 'muted'])],
            ['html' => '<a class="button button--ghost button--sm" href="' . $this->e($detailUrl) . '">' . $this->e($canEdit ? 'Manage' : 'View') . '</a>'],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Procurement',
    'title' => 'Suppliers',
    'subtitle' => 'Manage supplier contacts, payment terms and delivery lead times.',
]) ?>

<?= $this->partial('partials/procurement-nav', [
    'ordersUrl' => $ordersUrl,
    'suppliersUrl' => $suppliersUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if ($canEdit): ?>
<section class="kit-section" aria-labelledby="create-supplier-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="create-supplier-heading">New supplier</h2>
                <p class="card__subtitle">Suppliers appear in purchase order forms immediately once active.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($suppliersUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-name',
                    'name' => 'name',
                    'label' => 'Supplier name',
                    'value' => $createInput['name'] ?? '',
                    'placeholder' => 'e.g. Pampanga Timber Supply',
                    'required' => true,
                    'error' => $createErrors['name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-contact-name',
                    'name' => 'contact_name',
                    'label' => 'Contact person',
                    'value' => $createInput['contact_name'] ?? '',
                    'error' => $createErrors['contact_name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-contact-email',
                    'name' => 'contact_email',
                    'label' => 'Email',
                    'value' => $createInput['contact_email'] ?? '',
                    'placeholder' => 'orders@supplier.example',
                    'error' => $createErrors['contact_email'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-contact-phone',
                    'name' => 'contact_phone',
                    'label' => 'Phone',
                    'value' => $createInput['contact_phone'] ?? '',
                    'placeholder' => '+63 917 555 0100',
                    'error' => $createErrors['contact_phone'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-payment-terms',
                    'name' => 'payment_terms',
                    'label' => 'Payment terms',
                    'value' => $createInput['payment_terms'] ?? '',
                    'placeholder' => 'Net 30',
                    'error' => $createErrors['payment_terms'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-lead-time',
                    'name' => 'lead_time_days',
                    'type' => 'number',
                    'label' => 'Lead time (days)',
                    'value' => $createInput['lead_time_days'] ?? '',
                    'min' => '0',
                    'max' => '3650',
                    'step' => '1',
                    'error' => $createErrors['lead_time_days'] ?? null,
                ]) ?>
            </div>
            <div class="kit-stack">
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-address',
                    'name' => 'address',
                    'label' => 'Address',
                    'value' => $createInput['address'] ?? '',
                    'error' => $createErrors['address'] ?? null,
                ]) ?>
            </div>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Add supplier', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>

<?= $this->partial('partials/server-table', [
    'id' => 'suppliers-table',
    'action' => $suppliersUrl,
    'searchLabel' => 'Search suppliers',
    'searchPlaceholder' => 'Name, contact or email…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'columns' => [
        ['label' => 'Supplier', 'sortKey' => 'name'],
        ['label' => 'Contact'],
        ['label' => 'Email'],
        ['label' => 'Phone'],
        ['label' => 'Lead time', 'sortKey' => 'lead_time', 'numeric' => true],
        ['label' => 'Status', 'sortKey' => 'status'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => 'No suppliers match these filters',
    'emptyBody' => 'Clear filters or add a supplier above.',
]) ?>
