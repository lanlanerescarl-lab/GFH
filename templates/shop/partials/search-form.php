<?php
/**
 * Product search box (GET, so the address carries the search).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $productsUrl
 * @var string $searchText
 */
?>
<form class="shop-search" role="search" method="get" action="<?= $this->e($productsUrl) ?>">
    <label class="visually-hidden" for="shop-search-input">Search products</label>
    <input class="shop-search__input" id="shop-search-input" type="search" name="q" value="<?= $this->e($searchText) ?>" placeholder="Search products" maxlength="100" autocomplete="off" enterkeyhint="search">
    <button class="shop-search__button" type="submit">
        <svg class="shop-search__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5 21 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span class="visually-hidden">Search</span>
    </button>
</form>
