<?php
/**
 * Create staff account in an organization (/superadmin/users/new-staff, posts to /superadmin/users/staff) — SA.2a.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var string $csrf
 * @var array{organization_id: string, full_name: string, email: string, role: string} $input
 * @var array<string, string> $errors
 * @var list<array{id: string, slug: string, name: string, status: string}> $organizations
 */
$usersUrl = $basePath . '/superadmin/users';
$input ??= ['organization_id' => '', 'full_name' => '', 'email' => '', 'role' => 'staff'];
$errors ??= [];
$organizations ??= [];

$roles = [
    'admin' => 'Admin',
    'manager' => 'Manager',
    'staff' => 'Staff',
    'cashier' => 'Cashier',
];

$this->layout('layout');
$this->start('title');
echo $this->e('Create staff account');
$this->end();
?>
<p class="sa-breadcrumb">
    <a class="sa-back-link" href="<?= $this->e($usersUrl) ?>">← Back to users and access</a>
</p>

<?= $this->partial('partials/page-header', [
    'title' => 'Create staff account',
    'subtitle' => 'Add a staff account to an organization. Leave the password blank to start the account as pending and issue a one-time reset link afterwards.',
]) ?>

<section class="card sa-form-card" aria-labelledby="sa-new-staff-heading">
    <div class="card__header">
        <div>
            <h2 class="card__title" id="sa-new-staff-heading">Account and membership details</h2>
            <p class="card__subtitle">If the email already has an account, only a membership is added and the existing account is not changed.</p>
        </div>
    </div>

    <form class="sa-stack-form" method="post" action="<?= $this->e($usersUrl . '/staff') ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">

        <div class="sa-form-grid">
            <div class="field">
                <label class="field__label" for="sa-staff-org">Organization <span class="field__required" aria-hidden="true">*</span></label>
                <select
                    id="sa-staff-org"
                    name="organization_id"
                    required
                    <?= isset($errors['organization_id']) ? 'aria-invalid="true" aria-describedby="sa-staff-org-error"' : '' ?>
                >
                    <option value="">Choose an organization</option>
<?php foreach ($organizations as $orgOpt): ?>
                    <option value="<?= $this->e($orgOpt['id']) ?>"<?= ($input['organization_id'] ?? '') === $orgOpt['id'] ? ' selected' : '' ?>><?= $this->e($orgOpt['name']) ?> (<?= $this->e($orgOpt['slug']) ?>)</option>
<?php endforeach; ?>
                </select>
<?php if (isset($errors['organization_id'])): ?>
                <p class="field-error" id="sa-staff-org-error" role="alert"><?= $this->e($errors['organization_id']) ?></p>
<?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="sa-staff-role">Role <span class="field__required" aria-hidden="true">*</span></label>
                <select
                    id="sa-staff-role"
                    name="role"
                    required
                    <?= isset($errors['role']) ? 'aria-invalid="true" aria-describedby="sa-staff-role-error"' : '' ?>
                >
<?php foreach ($roles as $roleKey => $roleLabel): ?>
                    <option value="<?= $this->e($roleKey) ?>"<?= ($input['role'] ?? 'staff') === $roleKey ? ' selected' : '' ?>><?= $this->e($roleLabel) ?></option>
<?php endforeach; ?>
                </select>
<?php if (isset($errors['role'])): ?>
                <p class="field-error" id="sa-staff-role-error" role="alert"><?= $this->e($errors['role']) ?></p>
<?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="sa-staff-name">Full name <span class="field__required" aria-hidden="true">*</span></label>
                <input
                    type="text"
                    id="sa-staff-name"
                    name="full_name"
                    value="<?= $this->e($input['full_name'] ?? '') ?>"
                    required
                    maxlength="120"
                    autocomplete="name"
                    <?= isset($errors['full_name']) ? 'aria-invalid="true" aria-describedby="sa-staff-name-error"' : '' ?>
                >
<?php if (isset($errors['full_name'])): ?>
                <p class="field-error" id="sa-staff-name-error" role="alert"><?= $this->e($errors['full_name']) ?></p>
<?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="sa-staff-email">Email <span class="field__required" aria-hidden="true">*</span></label>
                <input
                    type="email"
                    id="sa-staff-email"
                    name="email"
                    value="<?= $this->e($input['email'] ?? '') ?>"
                    required
                    maxlength="254"
                    autocomplete="email"
                    <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="sa-staff-email-error"' : '' ?>
                >
<?php if (isset($errors['email'])): ?>
                <p class="field-error" id="sa-staff-email-error" role="alert"><?= $this->e($errors['email']) ?></p>
<?php endif; ?>
            </div>

            <div class="field sa-form-grid__full">
                <label class="field__label" for="sa-staff-password">Initial password (optional)</label>
                <input
                    type="password"
                    id="sa-staff-password"
                    name="password"
                    value=""
                    autocomplete="new-password"
                    aria-describedby="<?= isset($errors['password']) ? 'sa-staff-password-error sa-staff-password-help' : 'sa-staff-password-help' ?>"
                    <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>
                >
                <p class="field__hint" id="sa-staff-password-help">Optional. Leave blank to start the account as pending (10 to 72 characters if provided).</p>
<?php if (isset($errors['password'])): ?>
                <p class="field-error" id="sa-staff-password-error" role="alert"><?= $this->e($errors['password']) ?></p>
<?php endif; ?>
            </div>
        </div>

        <div class="sa-form-actions">
            <button class="button sa-filter-btn" type="submit">Create staff account</button>
            <a class="button button--ghost sa-filter-btn" href="<?= $this->e($usersUrl) ?>">Cancel</a>
        </div>
    </form>
</section>
