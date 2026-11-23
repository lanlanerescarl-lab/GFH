<?php
/**
 * Shown after a password was changed from a reset link.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $cssUrl
 * @var string $loginUrl the Admin sign-in page (staff)
 * @var string $shopSignInUrl the Shop sign-in page (customers)
 * @var bool $isStaff
 */
?>
<?= $this->render('partials/start', ['title' => 'Password changed', 'cssUrl' => $cssUrl]) ?>
        <h1>Password changed</h1>
        <p>Your new password is saved and the link can no longer be used.</p>
        <?php if ($isStaff): ?>
            <p><a class="button" href="<?= $this->e($loginUrl) ?>">Go to sign-in</a></p>
        <?php else: ?>
            <p>You can now sign in to the shop with your new password.</p>
            <p><a class="button" href="<?= $this->e($shopSignInUrl) ?>">Go to the shop sign-in</a></p>
        <?php endif; ?>
<?= $this->render('partials/end') ?>
