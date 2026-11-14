<?php
/**
 * Staff sign-in page.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $cssUrl
 * @var string $organization
 * @var string $action
 * @var string $next
 * @var string|null $error
 * @var string $email
 * @var string $csrf
 */
?>
<?= $this->render('partials/start', ['title' => 'Staff sign-in', 'cssUrl' => $cssUrl]) ?>
        <p class="error-page__code"><?= $this->e($organization) ?></p>
        <h1>Staff sign-in</h1>
        <?php if ($error !== null): ?>
            <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= $this->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <input type="hidden" name="next" value="<?= $this->e($next) ?>">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" required value="<?= $this->e($email) ?>">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="button" type="submit">Sign in</button>
        </form>
<?= $this->render('partials/end') ?>
