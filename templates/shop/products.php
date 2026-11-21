<?php
/**
 * Shop product listing (GET /{org}/shop/products): search, filters, sorting and pagination.
 * The address carries the whole state and the page works without JavaScript; the script only adds a filter drawer
 * on phones and sends the form when a filter changes.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $productsUrl
 * @var \Gfh\Core\Support\ShopQuery $query
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, options: array<string, mixed>, chips: list<array{label: string, param: string, value: ?string}>} $listing
 */
use Gfh\Core\Support\ShopQuery;

$this->layout('layout');
$this->start('title');
echo $this->e($query->search() !== '' ? 'Search results' : 'Products');
$this->end();

$pagination = $listing['pagination'];
$total = (int) $pagination['total'];
$first = $total === 0 ? 0 : ((int) $pagination['page'] - 1) * (int) $pagination['per_page'] + 1;
$last = min($total, (int) $pagination['page'] * (int) $pagination['per_page']);
?>
<div class="shop-listing">
    <header class="shop-listing__head">
        <h1 class="shop-listing__title"><?= $query->search() !== '' ? 'Search results' : 'Products' ?></h1>
        <p class="shop-listing__count" role="status">
<?php if ($total === 0): ?>
            No products found
<?php else: ?>
            <?= $total ?> <?= $total === 1 ? 'product' : 'products' ?><?= $total > (int) $pagination['per_page'] ? ' (showing ' . $first . ' to ' . $last . ')' : '' ?>
<?php endif; ?>
        </p>
    </header>

<?php if ($query->ignored !== []): ?>
    <div class="alert alert--error" role="alert">
        <p>Some parts of this address were not understood and were left out: <?= $this->e(implode(', ', $query->ignored)) ?>.</p>
    </div>
<?php endif; ?>

    <div class="shop-toolbar">
        <button class="button button--secondary shop-filter-toggle" type="button" hidden aria-controls="shop-filters" aria-expanded="false" data-filter-open>Filters</button>
        <div class="field shop-sort">
            <label for="shop-sort">Sort by</label>
            <select id="shop-sort" name="sort" form="shop-filter-form" data-autosubmit>
<?php foreach (ShopQuery::SORT_CHOICES as $value => $label): ?>
                <option value="<?= $this->e($value) ?>"<?= $query->sort() === $value ? ' selected' : '' ?>><?= $this->e($label) ?></option>
<?php endforeach; ?>
            </select>
            <button class="button button--secondary no-js-only" type="submit" form="shop-filter-form">Apply</button>
        </div>
    </div>

    <?= $this->partial('partials/chips', ['query' => $query, 'chips' => $listing['chips']]) ?>

    <div class="shop-listing__layout">
        <div class="shop-backdrop" hidden data-filter-close></div>
        <aside class="shop-filters" id="shop-filters" aria-labelledby="shop-filters-title" data-filter-panel>
            <div class="shop-filters__head">
                <h2 class="shop-filters__title" id="shop-filters-title">Filters</h2>
                <button class="button button--secondary shop-filters__close" type="button" hidden data-filter-close>Close</button>
            </div>
            <?= $this->partial('partials/filter-form', ['query' => $query, 'options' => $listing['options']]) ?>
        </aside>

        <section class="shop-results" aria-labelledby="shop-results-title">
            <h2 class="visually-hidden" id="shop-results-title">Product list</h2>
<?php if ($listing['items'] === []): ?>
            <div class="empty-state">
                <h3 class="empty-state__title">No products found</h3>
<?php if ($query->isNarrowed()): ?>
                <p>Try a different search, or remove some filters.</p>
                <a class="button" href="<?= $this->e($productsUrl) ?>">Show all products</a>
<?php else: ?>
                <p>There are no products to show yet.</p>
<?php endif; ?>
            </div>
<?php else: ?>
            <ul class="product-grid">
<?php foreach ($listing['items'] as $product): ?>
                <li><?= $this->partial('partials/product-card', ['product' => $product]) ?></li>
<?php endforeach; ?>
            </ul>
            <?= $this->partial('partials/pagination', ['query' => $query, 'pagination' => $pagination]) ?>
<?php endif; ?>
        </section>
    </div>
</div>
