<?php
/**
 * Landing page after staff sign-in (replaced by the Admin dashboard in Stage 5).
 *
 * @var \Gfh\Core\View\View $this
 * @var string $cssUrl
 * @var string $organization
 * @var string $name
 * @var string $email
 * @var string $role
 * @var bool $canIssueLinks
 * @var string $logoutUrl
 * @var string $resetLinksUrl
 * @var string $csrf
 */
?>
<?= $this->render('partials/start', ['title' => 'Signed in', 'cssUrl' => $cssUrl]) ?>
        <p class="error-page__code"><?= $this->e($organization) ?></p>
        <h1>You are signed in</h1>
        <p><?= $this->e($name) ?> (<?= $this->e($email) ?>) · role: <?= $this->e(ucfirst($role)) ?></p>
        <?php if ($canIssueLinks): ?>
            <p><a href="<?= $this->e($resetLinksUrl) ?>">Generate a password reset link</a></p>
        <?php endif; ?>
        <form method="post" action="<?= $this->e($logoutUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <button class="button button--secondary" type="submit">Sign out</button>
        </form>
<?= $this->render('partials/end') ?>
