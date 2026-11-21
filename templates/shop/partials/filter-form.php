<?php
/**
 * The filter form of the listing: category (two levels), brand, price range and attributes, built from the filter
 * options the catalog returns (with counts). A GET form, so the address carries the state and it works without
 * JavaScript. The sort box in the page toolbar belongs to this form (form="shop-filter-form").
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\Support\ShopQuery $query
 * @var array<string, mixed> $options brands, categories, price, attributes
 * @var string $productsUrl
 */
use Gfh\Core\Support\Format;

$selectedCategory = (string) ($query->state['category_id'] ?? '');
$categoryListed = false;
$errors = $query->fieldErrors;
$minPlaceholder = $options['price']['min'] !== null ? Format::peso((string) $options['price']['min']) : '';
$maxPlaceholder = $options['price']['max'] !== null ? Format::peso((string) $options['price']['max']) : '';
?>
<form class="filter-form" id="shop-filter-form" method="get" action="<?= $this->e($productsUrl) ?>" data-filter-form>
<?php if ($query->search() !== ''): ?>
    <input type="hidden" name="q" value="<?= $this->e($query->search()) ?>">
<?php endif; ?>
<?php if (isset($query->state['per_page'])): ?>
    <input type="hidden" name="per_page" value="<?= (int) $query->state['per_page'] ?>">
<?php endif; ?>

<?php if ($options['categories'] !== []): ?>
    <fieldset class="filter-group">
        <legend>Category</legend>
        <ul class="filter-list">
            <li>
                <label class="choice"><input type="radio" name="category_id" value=""<?= $selectedCategory === '' ? ' checked' : '' ?>><span class="choice__label">All categories</span></label>
            </li>
<?php foreach ($options['categories'] as $category): ?>
<?php $categoryListed = $categoryListed || (string) $category['id'] === $selectedCategory; ?>
            <li>
                <label class="choice"><input type="radio" name="category_id" value="<?= $this->e($category['id']) ?>"<?= (string) $category['id'] === $selectedCategory ? ' checked' : '' ?>><span class="choice__label"><?= $this->e($category['name']) ?></span><span class="choice__count"><?= (int) $category['product_count'] ?></span></label>
<?php if ($category['children'] !== []): ?>
                <ul class="filter-list filter-list--nested">
<?php foreach ($category['children'] as $child): ?>
<?php $categoryListed = $categoryListed || (string) $child['id'] === $selectedCategory; ?>
                    <li><label class="choice"><input type="radio" name="category_id" value="<?= $this->e($child['id']) ?>"<?= (string) $child['id'] === $selectedCategory ? ' checked' : '' ?>><span class="choice__label"><?= $this->e($child['name']) ?></span><span class="choice__count"><?= (int) $child['product_count'] ?></span></label></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
    </fieldset>
<?php endif; ?>
<?php if ($selectedCategory !== '' && !$categoryListed): ?>
    <input type="hidden" name="category_id" value="<?= $this->e($selectedCategory) ?>">
<?php endif; ?>

<?php if ($options['brands'] !== []): ?>
    <fieldset class="filter-group">
        <legend>Brand</legend>
        <ul class="filter-list">
<?php foreach ($options['brands'] as $brand): ?>
            <li><label class="choice"><input type="checkbox" name="brand_id[]" value="<?= $this->e($brand['id']) ?>"<?= $query->hasBrand((string) $brand['id']) ? ' checked' : '' ?>><span class="choice__label"><?= $this->e($brand['name']) ?></span><span class="choice__count"><?= (int) $brand['product_count'] ?></span></label></li>
<?php endforeach; ?>
        </ul>
    </fieldset>
<?php endif; ?>

    <fieldset class="filter-group">
        <legend>Price</legend>
        <div class="filter-range">
            <div class="field">
                <label for="shop-min-price">Lowest price</label>
                <input id="shop-min-price" type="text" inputmode="decimal" autocomplete="off" name="min_price" value="<?= $this->e($query->priceText('min_price')) ?>"<?= $minPlaceholder !== '' ? ' placeholder="' . $this->e($minPlaceholder) . '"' : '' ?><?= isset($errors['min_price']) ? ' aria-invalid="true" aria-describedby="shop-min-price-error"' : '' ?>>
<?php if (isset($errors['min_price'])): ?>
                <p class="field-error" id="shop-min-price-error"><?= $this->e($errors['min_price']) ?></p>
<?php endif; ?>
            </div>
            <div class="field">
                <label for="shop-max-price">Highest price</label>
                <input id="shop-max-price" type="text" inputmode="decimal" autocomplete="off" name="max_price" value="<?= $this->e($query->priceText('max_price')) ?>"<?= $maxPlaceholder !== '' ? ' placeholder="' . $this->e($maxPlaceholder) . '"' : '' ?><?= isset($errors['max_price']) ? ' aria-invalid="true" aria-describedby="shop-max-price-error"' : '' ?>>
<?php if (isset($errors['max_price'])): ?>
                <p class="field-error" id="shop-max-price-error"><?= $this->e($errors['max_price']) ?></p>
<?php endif; ?>
            </div>
        </div>
    </fieldset>

<?php $listedAttributes = []; ?>
<?php foreach ($options['attributes'] as $attribute): ?>
<?php
    $key = (string) $attribute['key'];
    $listedAttributes[$key] = true;
    $label = Format::attributeLabel($key);
    $current = $query->attribute($key);
?>
<?php if ($attribute['type'] === 'number'): ?>
<?php $bounds = is_array($current) ? $current : []; ?>
    <fieldset class="filter-group">
        <legend><?= $this->e($label) ?></legend>
        <div class="filter-range">
            <div class="field">
                <label for="shop-attr-<?= $this->e($key) ?>-min">At least</label>
                <input id="shop-attr-<?= $this->e($key) ?>-min" type="text" inputmode="decimal" autocomplete="off" name="attr[<?= $this->e($key) ?>][min]" value="<?= $this->e($bounds['min'] ?? '') ?>" placeholder="<?= $this->e(Format::attributeValue($attribute['min'])) ?>">
            </div>
            <div class="field">
                <label for="shop-attr-<?= $this->e($key) ?>-max">At most</label>
                <input id="shop-attr-<?= $this->e($key) ?>-max" type="text" inputmode="decimal" autocomplete="off" name="attr[<?= $this->e($key) ?>][max]" value="<?= $this->e($bounds['max'] ?? '') ?>" placeholder="<?= $this->e(Format::attributeValue($attribute['max'])) ?>">
            </div>
        </div>
    </fieldset>
<?php else: ?>
    <div class="field filter-select">
        <label for="shop-attr-<?= $this->e($key) ?>"><?= $this->e($label) ?></label>
        <select id="shop-attr-<?= $this->e($key) ?>" name="attr[<?= $this->e($key) ?>]">
            <option value="">Any</option>
<?php foreach ($attribute['values'] as $option): ?>
<?php $value = (string) $option['value']; ?>
            <option value="<?= $this->e($value) ?>"<?= $current === $value ? ' selected' : '' ?>><?= $this->e($attribute['type'] === 'boolean' ? ($value === 'true' ? 'Yes' : 'No') : $value) ?> (<?= (int) $option['count'] ?>)</option>
<?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>
<?php endforeach; ?>
<?php // An attribute filter in the address that has no box (no product matches it now) is kept when the form is sent. ?>
<?php foreach ((array) ($query->state['attr'] ?? []) as $key => $rule): ?>
<?php if (!isset($listedAttributes[(string) $key])): ?>
<?php foreach ((array) $rule as $bound => $value): ?>
    <input type="hidden" name="attr[<?= $this->e($key) ?>]<?= is_array($rule) ? '[' . $this->e($bound) . ']' : '' ?>" value="<?= $this->e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php endforeach; ?>

    <div class="filter-actions">
        <button class="button" type="submit">Apply filters</button>
<?php if ($query->hasFilters()): ?>
        <a class="button button--secondary" href="<?= $this->e($query->hrefClearFilters($productsUrl)) ?>">Clear all</a>
<?php endif; ?>
    </div>
</form>
