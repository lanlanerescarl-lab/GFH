<?php
/**
 * The account home: who is signed in and the account menu.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $customer id, full_name, email, phone
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 * @var string|null $confirmation
 */
$this->layout('layout');
$this->start('title');
echo $this->e('My account');
$this->end();
?>
<section class="account-page" aria-labelledby="account-title">
    <h1 id="account-title">My account</h1>
<?php if ($confirmation !== null): ?>
    <p class="alert alert--ok" role="status"><?= $this->e($confirmation) ?></p>
<?php endif; ?>
    <p class="account-page__who">Signed in as <strong><?= $this->e($customer['full_name']) ?></strong> (<?= $this->e($customer['email']) ?>).</p>
    <?= $this->partial('partials/account-menu', ['accountMenu' => $accountMenu]) ?>
</section>
