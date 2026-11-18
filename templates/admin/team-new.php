<?php

declare(strict_types=1);

/**
 * Admin Add team member screen (GET /{org}/admin/team/new, posts to /{org}/admin/team).
 * Creates a Staff or Cashier account with no password; the person sets one through a one-time reset link.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, string> $input
 * @var array<string, string> $errors
 * @var list<string> $roles
 * @var string $teamUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Add team member');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$roleOptions = [];
foreach ($roles as $role) {
    $roleOptions[$role] = ucfirst($role);
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Team',
    'title' => 'Add team member',
    'subtitle' => 'Create a Staff or Cashier account. No password is set here: generate a one-time reset link afterwards.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($teamUrl) . '">Back to team</a>',
]) ?>

<section class="kit-section" aria-labelledby="team-new-heading">
    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="team-new-heading">Account details</h2>
                <p class="card__subtitle">If the email already has an account, it is added to this team and the account is not changed.</p>
            </div>
        </div>
        <form method="post" action="<?= $this->e($teamUrl) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="kit-grid">
                <?= $this->partial('partials/field', [
                    'id' => 'team-full-name',
                    'name' => 'full_name',
                    'label' => 'Full name',
                    'value' => $input['full_name'] ?? '',
                    'required' => true,
                    'error' => $errors['full_name'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'team-email',
                    'name' => 'email',
                    'type' => 'email',
                    'label' => 'Email',
                    'value' => $input['email'] ?? '',
                    'required' => true,
                    'error' => $errors['email'] ?? null,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'team-role',
                    'name' => 'role',
                    'type' => 'select',
                    'label' => 'Role',
                    'value' => $input['role'] ?? 'staff',
                    'options' => $roleOptions,
                    'required' => true,
                    'error' => $errors['role'] ?? null,
                ]) ?>
            </div>
            <div class="form-actions">
                <?= $this->partial('partials/button', ['label' => 'Add team member', 'variant' => 'primary', 'type' => 'submit']) ?>
            </div>
        </form>
    </section>
</section>
