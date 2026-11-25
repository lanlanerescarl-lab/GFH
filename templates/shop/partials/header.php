<?php
/**
 * Shop header: brand, search box, the Cart link with its count, the sign-in links (Sign in and Register for a guest,
 * Account, My orders and Sign out for a signed-in customer), the menu button and the navigation. The link to the page the
 * visitor is on carries aria-current="page".
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $organizationName
 * @var string $homeUrl
 * @var string $navigationHtml the printed navigation partial, or "" when there is none
 * @var string $productsUrl
 * @var string $searchText
 * @var array<string, mixed> $shopAccount signedIn, csrf and the link addresses
 */
$navigationHtml ??= '';
$productsUrl ??= '';
$searchText ??= '';
$shopAccount ??= ['signedIn' => false, 'csrf' => '', 'cartUrl' => '', 'signInUrl' => '', 'registerUrl' => '', 'ordersUrl' => '', 'signOutUrl' => '', 'accountUrl' => '', 'accountCurrent' => false, 'ordersCurrent' => false];
?>
    <header class="shell-header shop-header">
        <a class="shell-brand shop-header__brand" href="<?= $this->e($homeUrl) ?>"><?= $this->e($organizationName) ?></a>
<?php if ($productsUrl !== ''): ?>
        <?= $this->partial('partials/search-form', ['productsUrl' => $productsUrl, 'searchText' => $searchText]) ?>
<?php endif; ?>
        <div class="shop-header__actions">
<?php if ($shopAccount['cartUrl'] !== ''): ?>
            <a class="shop-header__link shop-header__cart" href="<?= $this->e($shopAccount['cartUrl']) ?>">Cart<span class="cart-badge" data-cart-badge hidden> <span data-cart-number>0</span><span class="visually-hidden"> products in your cart</span></span></a>
<?php endif; ?>
<?php if ($shopAccount['signedIn']): ?>
            <a class="shop-header__link" href="<?= $this->e($shopAccount['accountUrl']) ?>"<?= !empty($shopAccount['accountCurrent']) ? ' aria-current="page"' : '' ?>>Account</a>
            <a class="shop-header__link" href="<?= $this->e($shopAccount['ordersUrl']) ?>"<?= !empty($shopAccount['ordersCurrent']) ? ' aria-current="page"' : '' ?>>My orders</a>
            <form class="shop-header__signout" method="post" action="<?= $this->e($shopAccount['signOutUrl']) ?>">
                <input type="hidden" name="_csrf" value="<?= $this->e($shopAccount['csrf']) ?>">
                <button class="shop-header__link shop-header__button" type="submit">Sign out</button>
            </form>
<?php elseif ($shopAccount['signInUrl'] !== ''): ?>
            <a class="shop-header__link" href="<?= $this->e($shopAccount['signInUrl']) ?>">Sign in</a>
            <a class="shop-header__link" href="<?= $this->e($shopAccount['registerUrl']) ?>">Register</a>
<?php endif; ?>
        </div>
<?php if ($navigationHtml !== ''): ?>
        <button class="shell-menu-button" type="button" aria-expanded="false" aria-controls="shell-nav" data-nav-toggle>Menu</button>
<?php endif; ?>
        <?= $navigationHtml ?>
    </header>
