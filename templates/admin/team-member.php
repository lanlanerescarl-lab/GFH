<?php

declare(strict_types=1);

/**
 * Admin Team member screen (GET /{org}/admin/team/{id}).
 * Edit name, email and role (Staff or Cashier), deactivate or reactivate, and generate a one-time reset link. An Admin
 * or Manager, or the signed-in person's own account, is shown read-only.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $member
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var list<string> $roles
 * @var string $memberUrl
 * @var string $teamUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e((string) $member['full_name']);
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$status = (string) $member['status'];
$manageable = !empty($member['manageable']);
$tones = ['active' => 'ok', 'pending' => 'warn', 'inactive' => 'neutral'];

$roleOptions = [];
foreach ($roles as $role) {
    $roleOptions[$role] = ucfirst($role);
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Team',
    'title' => (string) $member['full_name'],
    'subtitle' => ucfirst((string) $member['role']) . ' · ' . (string) $member['email'],
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($teamUrl) . '">Back to team</a>',
]) ?>

<?php if (!empty($errors['action'])): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Could not complete that action', 'message' => $errors['action']]) ?>
</div>
<?php endif; ?>

<section class="kit-section" aria-labelledby="team-member-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="team-member-heading">Account details</h2>
                <p class="card__subtitle">
                    Last sign-in: <?= $this->e($member['last_login_at'] !== null ? Format::date((string) $member['last_login_at']) : 'Never') ?>
                    · On the team since <?= $this->e(Format::date((string) $member['joined_at'])) ?>
                </p>
            </div>
            <div>
                <?= $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $tones[$status] ?? 'neutral']) ?>
            </div>
        </div>
<?php if (!$manageable): ?>
        <p class="cell-meta">This account is read-only here. Admin and Manager accounts, and your own account, are changed by a Super Admin.</p>
<?php else: ?>
        <form method="post" action="<?= $this->e($memberUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'member-full-name',
                    'name' => 'full_name',
                    'label' => 'Full name',
                    'value' => $input['full_name'] ?? '',
                    'required' => true,
                    'error' => $errors['full_name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'member-email',
                    'name' => 'email',
                    'type' => 'email',
                    'label' => 'Email',
                    'value' => $input['email'] ?? '',
                    'required' => true,
                    'error' => $errors['email'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'member-role',
                    'name' => 'role',
                    'type' => 'select',
                    'label' => 'Role',
                    'value' => $input['role'] ?? (string) $member['role'],
                    'options' => $roleOptions,
                    'required' => true,
                    'error' => $errors['role'] ?? null,
                ]) ?>
            </div>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
<?php endif; ?>
    </section>
</section>

<?php if ($manageable): ?>
<section class="kit-section" aria-labelledby="team-access-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="team-access-heading">Access</h2>
                <p class="card__subtitle">Deactivating ends their access at once. Nothing is deleted, and you can reactivate them later.</p>
            </div>
        </div>
        <div class="form-actions">
<?php if ($status === 'inactive'): ?>
            <form method="post" action="<?= $this->e($memberUrl . '/reactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Reactivate', 'variant' => 'primary', 'type' => 'submit']) ?>
            </form>
<?php else: ?>
            <form method="post" action="<?= $this->e($memberUrl . '/reset-link') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Generate password reset link', 'variant' => 'secondary', 'type' => 'submit']) ?>
            </form>
            <form method="post" action="<?= $this->e($memberUrl . '/deactivate') ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <?= $this->partial('partials/button', ['label' => 'Deactivate', 'variant' => 'danger', 'type' => 'submit']) ?>
            </form>
<?php endif; ?>
        </div>
    </section>
</section>
<?php endif; ?>
