<?php
/**
 * Admin screen that generates a one-time password reset link and shows it, because there is no email service.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $cssUrl
 * @var string $organization
 * @var string $action
 * @var string $accountUrl
 * @var array{url: string, expires_at: string}|null $link
 * @var string|null $error
 * @var string $type
 * @var string $email
 * @var string $csrf
 */
?>
<?= $this->render('partials/start', ['title' => 'Password reset link', 'cssUrl' => $cssUrl]) ?>
        <p class="error-page__code"><?= $this->e($organization) ?></p>
        <h1>Password reset link</h1>
        <p>Generate a one-time link and give it to the person yourself. It works once and expires.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
        <?php endif; ?>
        <?php if ($link !== null): ?>
            <div class="alert alert--ok" role="status">
                <p><strong>Link created.</strong> It expires on <?= $this->e($link['expires_at']) ?>. Copy it now; it is not shown again.</p>
                <label for="reset-url">Reset link</label>
                <input id="reset-url" type="text" readonly value="<?= $this->e($link['url']) ?>">
            </div>
        <?php endif; ?>
        <form method="post" action="<?= $this->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
            <div class="field">
                <label for="type">Account type</label>
                <select id="type" name="type">
                    <option value="staff"<?= $type === 'staff' ? ' selected' : '' ?>>Staff or cashier</option>
                    <option value="customer"<?= $type === 'customer' ? ' selected' : '' ?>>Customer</option>
                </select>
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="off" required value="<?= $this->e($email) ?>">
            </div>
            <button class="button" type="submit">Generate link</button>
        </form>
        <p><a href="<?= $this->e($accountUrl) ?>">Back</a></p>
<?= $this->render('partials/end') ?>
