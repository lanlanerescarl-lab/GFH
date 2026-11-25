<?php
/**
 * The customer's profile: full name and phone can be changed; the email address is shown and cannot.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $action
 * @var string $email
 * @var array{full_name: string, phone: string} $values
 * @var array<string, string> $errors field name => message ("form" for a message about the whole form)
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 * @var string|null $confirmation
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Profile');
$this->end();
?>
<section class="account-page form-page" aria-labelledby="profile-title">
    <?= $this->partial('partials/account-menu', ['accountMenu' => $accountMenu]) ?>
    <h1 id="profile-title">Profile</h1>
<?php if ($confirmation !== null): ?>
    <p class="alert alert--ok" role="status"><?= $this->e($confirmation) ?></p>
<?php endif; ?>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
        <?= $this->partial('partials/field', ['name' => 'full_name', 'label' => 'Full name', 'value' => $values['full_name'], 'autocomplete' => 'name', 'required' => true, 'error' => $errors['full_name'] ?? '']) ?>
        <div class="field">
            <p class="field__label" id="account-email-label">Email address</p>
            <p class="field__static" aria-labelledby="account-email-label" id="account-email"><?= $this->e($email) ?></p>
            <p class="help" id="account-email-help">This is the address you sign in with. It cannot be changed here.</p>
        </div>
        <?= $this->partial('partials/field', ['name' => 'phone', 'label' => 'Phone number (optional)', 'type' => 'tel', 'value' => $values['phone'], 'autocomplete' => 'tel', 'error' => $errors['phone'] ?? '']) ?>
        <button class="button form-page__submit" type="submit">Save profile</button>
    </form>
</section>
