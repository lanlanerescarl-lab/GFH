<?php
/**
 * Admin Supplier detail and edit screen (GET /{org}/admin/procurement/suppliers/{id}).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $supplier
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var bool $canEdit
 * @var string $activeTab
 * @var string $ordersUrl
 * @var string $suppliersUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e((string) $supplier['name']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$id = (string) $supplier['id'];
$status = (string) $supplier['status'];
$nextStatus = $status === 'active' ? 'inactive' : 'active';
$detailUrl = $suppliersUrl . '/' . $id;
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Supplier',
    'title' => (string) $supplier['name'],
    'subtitle' => $status === 'active' ? 'Active supplier available on purchase orders.' : 'Inactive supplier (hidden from new purchase orders; existing history is kept).',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($suppliersUrl) . '">Back to suppliers</a>',
]) ?>

<?= $this->partial('partials/procurement-nav', [
    'ordersUrl' => $ordersUrl,
    'suppliersUrl' => $suppliersUrl,
    'activeTab' => $activeTab,
]) ?>

<?php if (!empty($errors['status'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not update supplier', 'message' => $errors['status']]) ?>
</div>
<?php endif; ?>

<section class="kit-section">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Supplier details</h2>
                <p class="card__subtitle">Status: <?= $this->e(ucfirst($status)) ?></p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $status === 'active' ? 'ok' : 'muted']) ?>
            </div>
        </div>
        <form method="post" action="<?= $this->e($detailUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-name',
                    'name' => 'name',
                    'label' => 'Supplier name',
                    'value' => $input['name'] ?? '',
                    'required' => true,
                    'disabled' => !$canEdit,
                    'error' => $errors['name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-contact-name',
                    'name' => 'contact_name',
                    'label' => 'Contact person',
                    'value' => $input['contact_name'] ?? '',
                    'disabled' => !$canEdit,
                    'error' => $errors['contact_name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-contact-email',
                    'name' => 'contact_email',
                    'label' => 'Email',
                    'value' => $input['contact_email'] ?? '',
                    'disabled' => !$canEdit,
                    'error' => $errors['contact_email'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-contact-phone',
                    'name' => 'contact_phone',
                    'label' => 'Phone',
                    'value' => $input['contact_phone'] ?? '',
                    'disabled' => !$canEdit,
                    'error' => $errors['contact_phone'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-terms',
                    'name' => 'payment_terms',
                    'label' => 'Payment terms',
                    'value' => $input['payment_terms'] ?? '',
                    'disabled' => !$canEdit,
                    'error' => $errors['payment_terms'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-lead',
                    'name' => 'lead_time_days',
                    'type' => 'number',
                    'label' => 'Lead time (days)',
                    'value' => $input['lead_time_days'] ?? '',
                    'min' => '0',
                    'max' => '3650',
                    'step' => '1',
                    'disabled' => !$canEdit,
                    'error' => $errors['lead_time_days'] ?? null,
                ]) ?>
            </div>
            <div class="kit-stack">
                <?= $this->partial('partials/field', [
                    'id' => 'supplier-edit-address',
                    'name' => 'address',
                    'label' => 'Address',
                    'value' => $input['address'] ?? '',
                    'disabled' => !$canEdit,
                    'error' => $errors['address'] ?? null,
                ]) ?>
            </div>
<?php if ($canEdit): ?>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
<?php endif; ?>
        </form>
<?php if ($canEdit): ?>
        <form class="kit-stack" method="post" action="<?= $this->e($detailUrl . '/status') ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="status" value="<?= $this->e($nextStatus) ?>">
            <?= $this->partial('partials/button', [
                'label' => $status === 'active' ? 'Deactivate supplier' : 'Reactivate supplier',
                'variant' => $status === 'active' ? 'secondary' : 'primary',
                'type' => 'submit',
            ]) ?>
        </form>
<?php endif; ?>
    </section>
</section>
