<?php
/**
 * Edit one saved address, and delete it. Deleting the default makes the newest remaining address the default.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, mixed> $address
 * @var string $listUrl
 * @var string $action
 * @var string $deleteAction
 * @var array<string, string> $values
 * @var array<string, string> $errors field name => message ("form" for a message about the whole form)
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Edit address');
$this->end();
?>
<section class="account-page form-page" aria-labelledby="edit-address-title">
    <?= $this->partial('partials/account-menu', ['accountMenu' => $accountMenu]) ?>
    <h1 id="edit-address-title">Edit address</h1>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
    <form method="post" action="<?= $this->e($action) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
<?= $this->partial('partials/address-form', ['values' => $values, 'errors' => $errors]) ?>
        <button class="button form-page__submit" type="submit">Save address</button>
    </form>
    <p class="form-page__alt"><a href="<?= $this->e($listUrl) ?>">Back to my addresses</a></p>

    <section class="account-section" aria-labelledby="delete-address-title">
        <h2 id="delete-address-title">Delete this address</h2>
        <p><?= $address['is_default'] ? 'This is your default address. If you delete it, your newest remaining address becomes the default.' : 'Orders you already placed keep their own copy of the delivery address.' ?></p>
        <form method="post" action="<?= $this->e($deleteAction) ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
            <button class="button button--secondary form-page__submit" type="submit">Delete address</button>
        </form>
    </section>
</section>
