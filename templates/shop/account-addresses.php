<?php
/**
 * The customer's saved delivery addresses (at most ten): the default first, each with Edit and Make default, and a
 * form to add one. Delete is on the edit page. Checkout starts from the default address.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var list<array<string, mixed>> $addresses
 * @var int $maxAddresses
 * @var string $addressBase
 * @var string $action
 * @var array<string, string> $values the add form's fields
 * @var array<string, string> $errors field name => message ("form" for a message about the whole form)
 * @var list<array{label: string, url: string, current?: bool}> $accountMenu
 * @var string|null $confirmation
 * @var array<string, mixed> $shopAccount
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Addresses');
$this->end();
$count = count($addresses);
$full = $count >= $maxAddresses;
?>
<section class="account-page" aria-labelledby="addresses-title">
    <?= $this->partial('partials/account-menu', ['accountMenu' => $accountMenu]) ?>
    <h1 id="addresses-title">Addresses</h1>
<?php if ($confirmation !== null): ?>
    <p class="alert alert--ok" role="status"><?= $this->e($confirmation) ?></p>
<?php endif; ?>
    <p class="account-page__count" id="address-count"><?= $this->e((string) $count) ?> of <?= $this->e((string) $maxAddresses) ?> addresses saved.<?= $full ? ' You have reached the limit. Delete an address to add another.' : '' ?></p>

<?php if ($addresses === []): ?>
    <div class="empty-state">
        <h2 class="empty-state__title">No saved addresses yet</h2>
        <p>Add one below. Your first address becomes your default, and checkout starts from it.</p>
    </div>
<?php else: ?>
    <ul class="address-list">
<?php foreach ($addresses as $address): ?>
        <li class="address-card">
            <p class="address-card__title"><?= $this->e($address['label'] ?? 'Address') ?><?php if ($address['is_default']): ?> <span class="badge">Default</span><?php endif; ?></p>
            <address class="order-address">
                <?= $this->e($address['line1']) ?><br>
<?php if ($address['barangay'] !== null): ?>
                <?= $this->e($address['barangay']) ?><br>
<?php endif; ?>
                <?= $this->e($address['city']) ?>, <?= $this->e($address['province']) ?><?= $address['postal_code'] !== null ? ' ' . $this->e($address['postal_code']) : '' ?>
            </address>
            <div class="address-card__actions">
                <a class="button button--secondary" href="<?= $this->e($addressBase . '/' . $address['id'] . '/edit') ?>">Edit<span class="visually-hidden"> <?= $this->e($address['label'] ?? 'address') ?>, <?= $this->e($address['line1']) ?></span></a>
<?php if (!$address['is_default']): ?>
                <form method="post" action="<?= $this->e($addressBase . '/' . $address['id'] . '/default') ?>">
                    <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
                    <button class="button button--secondary" type="submit">Make default<span class="visually-hidden"> <?= $this->e($address['label'] ?? 'address') ?>, <?= $this->e($address['line1']) ?></span></button>
                </form>
<?php endif; ?>
            </div>
        </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (!$full): ?>
    <section class="account-section form-page" aria-labelledby="add-address-title">
        <h2 id="add-address-title">Add an address</h2>
        <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
        <form method="post" action="<?= $this->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
<?= $this->partial('partials/address-form', ['values' => $values, 'errors' => $errors]) ?>
            <button class="button form-page__submit" type="submit">Add address</button>
        </form>
    </section>
<?php elseif ($errors !== []): ?>
    <?= $this->partial('partials/form-errors', ['errors' => $errors]) ?>
<?php endif; ?>
</section>
