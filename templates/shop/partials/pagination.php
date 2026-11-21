<?php
/**
 * Page links for a listing. The address of every page keeps the whole filter state.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\Support\ShopQuery $query
 * @var array<string, int> $pagination page, per_page, total, pages
 * @var string $productsUrl
 */
$page = (int) $pagination['page'];
$pages = (int) $pagination['pages'];
if ($pages < 2) {
    return;
}

$numbers = [];
foreach (range(1, $pages) as $number) {
    if ($number === 1 || $number === $pages || abs($number - $page) <= 1) {
        $numbers[] = $number;
    }
}
?>
<nav class="pagination" aria-label="Pagination">
    <ul class="pagination__list">
<?php if ($page > 1): ?>
        <li><a class="pagination__link" href="<?= $this->e($query->hrefPage($productsUrl, $page - 1)) ?>" rel="prev">Previous<span class="visually-hidden"> page</span></a></li>
<?php endif; ?>
<?php $previous = 0; foreach ($numbers as $number): ?>
<?php if ($number - $previous > 1): ?>
        <li class="pagination__gap" aria-hidden="true">…</li>
<?php endif; ?>
<?php if ($number === $page): ?>
        <li><span class="pagination__link is-current" aria-current="page"><span class="visually-hidden">Page </span><?= $number ?></span></li>
<?php else: ?>
        <li><a class="pagination__link" href="<?= $this->e($query->hrefPage($productsUrl, $number)) ?>"><span class="visually-hidden">Page </span><?= $number ?></a></li>
<?php endif; ?>
<?php $previous = $number; endforeach; ?>
<?php if ($page < $pages): ?>
        <li><a class="pagination__link" href="<?= $this->e($query->hrefPage($productsUrl, $page + 1)) ?>" rel="next">Next<span class="visually-hidden"> page</span></a></li>
<?php endif; ?>
    </ul>
</nav>
