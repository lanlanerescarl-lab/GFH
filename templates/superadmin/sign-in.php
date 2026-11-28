<?php
/**
 * Super Admin sign-in (/superadmin/sign-in).
 * Follows docs/superadmin-design/screens/sign-in.md: centered card on --color-bg,
 * Source Serif 4 product name, one-line subtitle, two labelled fields, one error region,
 * primary Sign in button, no forgot-password link and no registration link.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $action
 * @var string $next
 * @var string $email
 * @var string|null $error
 * @var string $csrf
 */
$this->layout('layout', ['bare' => true]);
$this->start('title');
echo $this->e('Sign in');
$this->end();
?>
<section class="card sa-auth-card" aria-labelledby="sa-signin-heading">
    <h1 class="sa-auth-card__title" id="sa-signin-heading">GFH Super Admin</h1>
    <p class="sa-auth-card__subtitle">Group oversight console</p>
<?php if ($error !== null && $error !== ''): ?>
    <div class="alert alert--error sa-auth-card__error" role="alert" aria-live="assertive"><?= $this->e($error) ?></div>
<?php endif; ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
        <input type="hidden" name="next" value="<?= $this->e($next) ?>">
        <div class="field">
            <label class="field__label" for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="<?= $this->e($email) ?>">
        </div>
        <div class="field">
            <label class="field__label" for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="button sa-auth-card__submit" type="submit">Sign in</button>
    </form>
</section>
