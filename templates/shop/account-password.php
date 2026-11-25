<?php
/**
 * Change the password. A password typed here is never printed back into a form, not even after an error.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $action
 * @var string $passwordHelp
 * @var array<string, string> $errors field name => message ("form" for a message about the whole form)
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 * @var string|null $confirmation
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Password');
$this->end();
?>
<section class="account-page form-page" aria-labelledby="password-title">
    <?= $this->partial('partials/account-menu', ['accountMenu' => $accountMenu]) ?>
    <h1 id="password-title">Password</h1>
<?php if ($confirmation !== null): ?>
    <p class="alert alert--ok" role="status"><?= $this->e($confirmation) ?></p>
<?php endif; ?>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
        <?= $this->partial('partials/field', ['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['current_password'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'new_password', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'help' => $passwordHelp, 'error' => $errors['new_password'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'new_password_confirmation', 'label' => 'Type the new password again', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['new_password_confirmation'] ?? '']) ?>
        <button class="button form-page__submit" type="submit">Change password</button>
    </form>
    <p class="help">Changing your password does not sign you out on your other devices. If you used a shared device, sign out there yourself.</p>
</section>
