<?php
/**
 * Shop header: brand, search box, the slots Stage 6.2 fills (cart, sign in or account), the menu button and the
 * navigation. The two slots stay empty (and take no room) in Batch 6.1.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var string $homeUrl
 * @var string $navigationHtml the printed navigation partial, or "" when there is none
 * @var string $productsUrl
 * @var string $searchText
 */
$navigationHtml ??= '';
$productsUrl ??= '';
$searchText ??= '';
?>
    <header class="shell-header shop-header">
        <a class="shell-brand shop-header__brand" href="<?= $this->e($homeUrl) ?>"><?= $this->e($organizationName) ?></a>
<?php if ($productsUrl !== ''): ?>
        <?= $this->partial('partials/search-form', ['productsUrl' => $productsUrl, 'searchText' => $searchText]) ?>
<?php endif; ?>
        <div class="shop-header__actions">
            <?php // SLOT (Batch 6.2): the Cart link goes inside this element. ?>
            <span class="shop-slot" data-slot="cart"></span>
            <?php // SLOT (Batch 6.2): the Sign in link, or the Account link for a signed-in customer, goes inside this element. ?>
            <span class="shop-slot" data-slot="account"></span>
        </div>
<?php if ($navigationHtml !== ''): ?>
        <button class="shell-menu-button" type="button" aria-expanded="false" aria-controls="shell-nav" data-nav-toggle>Menu</button>
<?php endif; ?>
        <?= $navigationHtml ?>
    </header>
