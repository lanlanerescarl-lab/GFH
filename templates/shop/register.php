<?php
/**
 * Customer registration page. Registering also signs the new customer in.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $action
 * @var string $signInUrl
 * @var string $next
 * @var array<string, string> $errors field name => message ("form" for a message about the whole form)
 * @var array{full_name: string, email: string, phone: string} $values
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Create an account');
$this->end();
$emailTaken = isset($errors['form']) && isset($errors['email']);
?>
<section class="form-page" aria-labelledby="register-title">
    <h1 id="register-title">Create an account</h1>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
<?php if ($emailTaken): ?>
    <p class="form-page__alt"><a href="<?= $this->e($signInUrl . ($next === '' ? '' : '?next=' . rawurlencode($next))) ?>">Sign in with this email instead</a></p>
<?php endif; ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
        <input type="hidden" name="next" value="<?= $this->e($next) ?>">
        <?= $this->partial('partials/field', ['name' => 'full_name', 'label' => 'Full name', 'value' => $values['full_name'], 'autocomplete' => 'name', 'required' => true, 'error' => $errors['full_name'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $values['email'], 'autocomplete' => 'email', 'required' => true, 'error' => $errors['email'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'phone', 'label' => 'Phone number (optional)', 'type' => 'tel', 'value' => $values['phone'], 'autocomplete' => 'tel', 'error' => $errors['phone'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'help' => 'At least 10 characters.', 'error' => $errors['password'] ?? '']) ?>
        <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Type the password again', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['password_confirmation'] ?? '']) ?>
        <button class="button form-page__submit" type="submit">Create account</button>
    </form>
    <p class="form-page__alt">Already have an account? <a href="<?= $this->e($signInUrl . ($next === '' ? '' : '?next=' . rawurlencode($next))) ?>">Sign in</a></p>
</section>
