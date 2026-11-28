<?php
/**
 * Create group account (/superadmin/users/new-group, posts to /superadmin/users/group) — SA.2a.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var string $csrf
 * @var array{full_name: string, email: string, org_role: string} $input
 * @var array<string, string> $errors
 */
$usersUrl = $basePath . '/superadmin/users';
$input ??= ['full_name' => '', 'email' => '', 'org_role' => 'group_viewer'];
$errors ??= [];

$groupRoles = [
    'super_admin' => 'Super Admin',
    'group_viewer' => 'Group Viewer',
];

$this->layout('layout');
$this->start('title');
echo $this->e('Create group account');
$this->end();
?>
<p class="sa-breadcrumb">
    <a class="sa-back-link" href="<?= $this->e($usersUrl) ?>">← Back to users and access</a>
</p>

<?= $this->partial('partials/page-header', [
    'title' => 'Create group account',
    'subtitle' => 'Create a Super Admin or Group Viewer account. Group roles are held on the account itself.',
]) ?>

<section class="card sa-form-card" aria-labelledby="sa-new-group-heading">
    <div class="card__header">
        <div>
            <h2 class="card__title" id="sa-new-group-heading">Group account details</h2>
            <p class="card__subtitle">Group accounts have oversight access across organizations and never receive edit or delete access on operations.</p>
        </div>
    </div>

    <form class="sa-stack-form" method="post" action="<?= $this->e($usersUrl . '/group') ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">

        <div class="sa-form-grid">
            <div class="field">
                <label class="field__label" for="sa-group-name">Full name <span class="field__required" aria-hidden="true">*</span></label>
                <input
                    type="text"
                    id="sa-group-name"
                    name="full_name"
                    value="<?= $this->e($input['full_name'] ?? '') ?>"
                    required
                    maxlength="120"
                    autocomplete="name"
                    <?= isset($errors['full_name']) ? 'aria-invalid="true" aria-describedby="sa-group-name-error"' : '' ?>
                >
<?php if (isset($errors['full_name'])): ?>
                <p class="field-error" id="sa-group-name-error" role="alert"><?= $this->e($errors['full_name']) ?></p>
<?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="sa-group-email">Email <span class="field__required" aria-hidden="true">*</span></label>
                <input
                    type="email"
                    id="sa-group-email"
                    name="email"
                    value="<?= $this->e($input['email'] ?? '') ?>"
                    required
                    maxlength="254"
                    autocomplete="email"
                    <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="sa-group-email-error"' : '' ?>
                >
<?php if (isset($errors['email'])): ?>
                <p class="field-error" id="sa-group-email-error" role="alert"><?= $this->e($errors['email']) ?></p>
<?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="sa-group-role">Group role <span class="field__required" aria-hidden="true">*</span></label>
                <select
                    id="sa-group-role"
                    name="org_role"
                    required
                    <?= isset($errors['org_role']) ? 'aria-invalid="true" aria-describedby="sa-group-role-error"' : '' ?>
                >
<?php foreach ($groupRoles as $roleKey => $roleLabel): ?>
                    <option value="<?= $this->e($roleKey) ?>"<?= ($input['org_role'] ?? 'group_viewer') === $roleKey ? ' selected' : '' ?>><?= $this->e($roleLabel) ?></option>
<?php endforeach; ?>
                </select>
<?php if (isset($errors['org_role'])): ?>
                <p class="field-error" id="sa-group-role-error" role="alert"><?= $this->e($errors['org_role']) ?></p>
<?php endif; ?>
            </div>

            <div class="field sa-form-grid__full">
                <label class="field__label" for="sa-group-password">Initial password (optional)</label>
                <input
                    type="password"
                    id="sa-group-password"
                    name="password"
                    value=""
                    autocomplete="new-password"
                    aria-describedby="<?= isset($errors['password']) ? 'sa-group-password-error sa-group-password-help' : 'sa-group-password-help' ?>"
                    <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>
                >
                <p class="field__hint" id="sa-group-password-help">Optional. Leave blank to start the account as pending (10 to 72 characters if provided).</p>
<?php if (isset($errors['password'])): ?>
                <p class="field-error" id="sa-group-password-error" role="alert"><?= $this->e($errors['password']) ?></p>
<?php endif; ?>
            </div>
        </div>

        <div class="sa-form-actions">
            <button class="button sa-filter-btn" type="submit">Create group account</button>
            <a class="button button--ghost sa-filter-btn" href="<?= $this->e($usersUrl) ?>">Cancel</a>
        </div>
    </form>
</section>
