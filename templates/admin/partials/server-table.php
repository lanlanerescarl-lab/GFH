<?php
/**
 * Server-driven data table partial using ListQuery parameters (page, per_page, sort, q, and filters).
 * Uses Stage 4 .data-table-card styles without attaching the client-side [data-table] JS controller.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $id
 * @var string $action base URL for search/filter form and sort/page links
 * @var string $searchLabel
 * @var string $searchPlaceholder
 * @var string $searchValue
 * @var string $sort current ListQuery sort string (e.g. "product" or "-time")
 * @var int $perPage
 * @var array<string, string> $filters active filter query parameters
 * @var string $filtersHtml pre-escaped HTML of extra filter controls
 * @var list<array{label: string, url: string, active?: bool}> $chips optional filter chip links
 * @var list<array{label: string, sortKey?: string, numeric?: bool}> $columns
 * @var list<array{cells: list<array{text?: string, html?: string, numeric?: bool}>}> $rows
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var string $emptyTitle
 * @var string $emptyBody
 * @var string $emptyActionsHtml
 */
$searchLabel ??= 'Search';
$searchPlaceholder ??= 'Search…';
$searchValue ??= '';
$sort ??= '';
$perPage ??= 20;
$filters ??= [];
$filtersHtml ??= '';
$chips ??= [];
$emptyTitle ??= 'No records found';
$emptyBody ??= 'Try adjusting your search or filters.';
$emptyActionsHtml ??= '';

$buildUrl = static function (string $base, array $params): string {
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $clean[$k] = $v;
        }
    }

    return $clean === [] ? $base : $base . '?' . http_build_query($clean);
};

$baseParams = array_merge($filters, [
    'q' => $searchValue,
    'sort' => $sort,
    'per_page' => (string) $perPage,
]);

$page = max(1, (int) ($pagination['page'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? 0));
$pages = max(1, (int) ($pagination['pages'] ?? 1));
$fromRow = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$toRow = min($total, $page * $perPage);
?>
<section class="card data-table-card" id="<?= $this->e($id) ?>">
    <form class="table-toolbar table-toolbar--server" method="get" action="<?= $this->e($action) ?>">
        <input type="hidden" name="sort" value="<?= $this->e($sort) ?>">
        <div class="table-toolbar__search">
            <label class="visually-hidden" for="<?= $this->e($id) ?>-search"><?= $this->e($searchLabel) ?></label>
            <input
                type="search"
                id="<?= $this->e($id) ?>-search"
                name="q"
                value="<?= $this->e($searchValue) ?>"
                placeholder="<?= $this->e($searchPlaceholder) ?>"
            >
        </div>
<?php if ($filtersHtml !== ''): ?>
        <div class="table-toolbar__filters"><?= $filtersHtml ?></div>
<?php endif; ?>
        <div class="table-toolbar__actions">
            <label class="visually-hidden" for="<?= $this->e($id) ?>-per-page">Rows per page</label>
            <select id="<?= $this->e($id) ?>-per-page" name="per_page">
<?php foreach ([10, 20, 50, 100] as $size): ?>
                <option value="<?= $this->e((string) $size) ?>"<?= $perPage === $size ? ' selected' : '' ?>><?= $this->e((string) $size) ?> / page</option>
<?php endforeach; ?>
            </select>
            <button class="button button--secondary button--sm" type="submit">Apply</button>
            <a class="button button--ghost button--sm" href="<?= $this->e($action) ?>">Clear</a>
        </div>
    </form>
<?php if ($chips !== []): ?>
    <div class="table-toolbar table-toolbar--chips">
        <div class="filter-chips" role="group" aria-label="Quick filters">
<?php foreach ($chips as $chip): ?>
            <a
                class="filter-chip"
                href="<?= $this->e($chip['url']) ?>"
                aria-pressed="<?= !empty($chip['active']) ? 'true' : 'false' ?>"
            ><?= $this->e($chip['label']) ?></a>
<?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php if ($rows === []): ?>
    <div class="table-empty-wrap">
        <?= $this->partial('partials/empty-state', [
            'title' => $emptyTitle,
            'body' => $emptyBody,
            'actionsHtml' => $emptyActionsHtml,
        ]) ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
<?php foreach ($columns as $col): ?>
<?php
    $sortKey = $col['sortKey'] ?? null;
    $numeric = !empty($col['numeric']);
    $isAsc = $sortKey !== null && $sort === $sortKey;
    $isDesc = $sortKey !== null && $sort === '-' . $sortKey;
    $ariaSort = $isAsc ? 'ascending' : ($isDesc ? 'descending' : 'none');
    $nextSort = $sortKey !== null ? ($isAsc ? '-' . $sortKey : $sortKey) : '';
    $sortUrl = $sortKey !== null ? $buildUrl($action, array_merge($baseParams, ['sort' => $nextSort, 'page' => '1'])) : '';
    $arrow = $isAsc ? '↑' : ($isDesc ? '↓' : '↕');
?>
                    <th scope="col"<?= $numeric ? ' class="data-table__num"' : '' ?><?= $sortKey !== null ? ' aria-sort="' . $this->e($ariaSort) . '"' : '' ?>>
<?php if ($sortKey !== null): ?>
                        <a class="data-table__sort" href="<?= $this->e($sortUrl) ?>">
                            <span><?= $this->e($col['label']) ?></span>
                            <span aria-hidden="true"><?= $arrow ?></span>
                        </a>
<?php else: ?>
                        <?= $this->e($col['label']) ?>
<?php endif; ?>
                    </th>
<?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php foreach ($rows as $row): ?>
                <tr>
<?php foreach ($row['cells'] as $cell): ?>
                    <td<?= !empty($cell['numeric']) ? ' class="data-table__num"' : '' ?>>
                        <?= $cell['html'] ?? $this->e((string) ($cell['text'] ?? '')) ?>
                    </td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
    <div class="table-pagination">
        <span class="table-pagination__summary" aria-live="polite">
            Showing <?= $this->e((string) $fromRow) ?>–<?= $this->e((string) $toRow) ?> of <?= $this->e((string) $total) ?> (Page <?= $this->e((string) $page) ?> of <?= $this->e((string) $pages) ?>)
        </span>
        <div class="table-pagination__controls">
<?php if ($page > 1): ?>
            <a class="button button--secondary button--sm" href="<?= $this->e($buildUrl($action, array_merge($baseParams, ['page' => (string) ($page - 1)]))) ?>">Previous</a>
<?php else: ?>
            <span class="button button--secondary button--sm" aria-disabled="true">Previous</span>
<?php endif; ?>
<?php if ($page < $pages): ?>
            <a class="button button--secondary button--sm" href="<?= $this->e($buildUrl($action, array_merge($baseParams, ['page' => (string) ($page + 1)]))) ?>">Next</a>
<?php else: ?>
            <span class="button button--secondary button--sm" aria-disabled="true">Next</span>
<?php endif; ?>
        </div>
    </div>
</section>
