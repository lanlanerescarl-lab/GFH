<?php
/**
 * Removable chips for the active search and filters. Each chip is a link to the same listing without that filter.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\Support\ShopQuery $query
 * @var list<array{label: string, param: string, value: ?string}> $chips
 * @var string $productsUrl
 */
if ($chips === []) {
    return;
}
?>
<ul class="chip-list" aria-label="Active filters">
<?php foreach ($chips as $chip): ?>
    <li>
        <a class="chip" href="<?= $this->e($query->hrefWithout($productsUrl, $chip['param'], $chip['value'])) ?>" aria-label="<?= $this->e('Remove filter: ' . $chip['label']) ?>">
            <span><?= $this->e($chip['label']) ?></span>
            <svg class="chip__x" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="M3 3l10 10M13 3L3 13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </a>
    </li>
<?php endforeach; ?>
<?php if (count($chips) > 1 && $query->hasFilters()): ?>
    <li><a class="chip chip--clear" href="<?= $this->e($query->hrefClearFilters($productsUrl)) ?>">Clear all filters</a></li>
<?php endif; ?>
</ul>
