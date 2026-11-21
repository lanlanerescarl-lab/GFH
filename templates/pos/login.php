<?php
/**
 * POS sign-in: the Admin sign-in's rules (lockout, one message for a wrong email or password) in the POS look.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $action
 * @var string|null $error
 * @var string $email
 * @var string $csrf
 */
$this->layout('layout', ['bare' => true]);
$this->start('title');
?>Sign in<?php
$this->end();
?>
<div class="pos-signin">
    <div class="pos-card pos-signin__card">
        <p class="pos-signin__org"><?= $this->e($organizationName) ?></p>
        <h1 class="pos-signin__title">Point of sale</h1>
        <p class="pos-signin__hint">Sign in to start selling.</p>
<?php if ($error !== null): ?>
        <div class="pos-notice pos-notice--error" role="alert"><?= $this->e($error) ?></div>
<?php endif; ?>
        <form method="post" action="<?= $this->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="pos-field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= $this->e($email) ?>">
            </div>
            <div class="pos-field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="pos-button pos-button--primary pos-button--block" type="submit">Sign in</button>
        </form>
    </div>
</div>
