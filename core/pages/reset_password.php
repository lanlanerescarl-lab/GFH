<?php
/**
 * Public page reached from a reset link: choose a new password.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $cssUrl
 * @var string $organization
 * @var string $action
 * @var string $token
 * @var string|null $error
 */
?>
<?= $this->render('partials/start', ['title' => 'Choose a new password', 'cssUrl' => $cssUrl]) ?>
        <p class="error-page__code"><?= $this->e($organization) ?></p>
        <h1>Choose a new password</h1>
        <?php if ($error !== null): ?>
            <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= $this->e($action) ?>" novalidate>
            <input type="hidden" name="token" value="<?= $this->e($token) ?>">
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="password-help">
                <p class="help" id="password-help">10 to 72 characters.</p>
            </div>
            <div class="field">
                <label for="password_confirm">Repeat the new password</label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            </div>
            <button class="button" type="submit">Save password</button>
        </form>
<?= $this->render('partials/end') ?>
