<?php
/**
 * Customer sign-in page. One message for a wrong email or a wrong password (the server's own wording), and a clear
 * message when sign-in is paused after too many tries.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $action
 * @var string $registerUrl
 * @var string $next the accepted return address, or ""
 * @var string $email
 * @var string|null $error
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Sign in');
$this->end();
$errors = $error === null || $error === '' ? [] : ['form' => $error];
?>
<section class="form-page" aria-labelledby="signin-title">
    <h1 id="signin-title">Sign in</h1>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
        <input type="hidden" name="next" value="<?= $this->e($next) ?>">
        <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email, 'autocomplete' => 'email', 'required' => true]) ?>
        <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
        <button class="button form-page__submit" type="submit">Sign in</button>
    </form>
    <p class="form-page__alt">New here? <a href="<?= $this->e($registerUrl . ($next === '' ? '' : '?next=' . rawurlencode($next))) ?>">Create an account</a></p>
    <p class="help">Forgot your password? Ask the shop for a reset link.</p>
</section>
