<?php
/**
 * Data table partial with search input (labeled), filter chips, sortable column headers, and pagination.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $id
 * @var string $searchLabel
 * @var string $searchPlaceholder
 * @var list<array{value: string, label: string, active?: bool}> $chips
 * @var list<array{label: string, sortable?: bool, sortType?: string, sortDirection?: string, numeric?: bool}> $columns
 * @var list<array{status?: string, cells: list<array{text?: string, html?: string, sortValue?: string, numeric?: bool}>}> $rows
 * @var int $pageSize
 */
$searchLabel ??= 'Search records';
$searchPlaceholder ??= 'Search by reference, name or SKU…';
$chips ??= [];
$pageSize ??= 5;
?>
<section class="card data-table-card" data-table data-page-size="<?= $this->e((string) $pageSize) ?>" id="<?= $this->e($id) ?>">
    <div class="table-toolbar">
        <div class="table-toolbar__search">
            <label class="visually-hidden" for="<?= $this->e($id) ?>-search"><?= $this->e($searchLabel) ?></label>
            <input
                type="search"
                id="<?= $this->e($id) ?>-search"
                data-table-search
                placeholder="<?= $this->e($searchPlaceholder) ?>"
            >
        </div>
<?php if ($chips !== []): ?>
        <div class="filter-chips" role="group" aria-label="Filter by status">
<?php foreach ($chips as $chip): ?>
<?php $pressed = !empty($chip['active']); ?>
            <button
                class="filter-chip"
                type="button"
                data-table-filter="<?= $this->e($chip['value']) ?>"
                aria-pressed="<?= $pressed ? 'true' : 'false' ?>"
            ><?= $this->e($chip['label']) ?></button>
<?php endforeach; ?>
            <button class="button button--ghost button--sm" type="button" data-table-clear>Clear filters</button>
        </div>
<?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
<?php foreach ($columns as $col): ?>
<?php
    $sortable = !empty($col['sortable']);
    $sortDir = $col['sortDirection'] ?? 'none';
    $numeric = !empty($col['numeric']);
?>
                    <th scope="col"<?= $numeric ? ' class="data-table__num"' : '' ?><?= $sortable ? ' aria-sort="' . $this->e($sortDir) . '"' : '' ?>>
<?php if ($sortable): ?>
                        <button class="data-table__sort" type="button" data-table-sort data-sort-type="<?= $this->e($col['sortType'] ?? 'text') ?>">
                            <span><?= $this->e($col['label']) ?></span>
                            <span aria-hidden="true">↕</span>
                        </button>
<?php else: ?>
                        <?= $this->e($col['label']) ?>
<?php endif; ?>
                    </th>
<?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php foreach ($rows as $row): ?>
                <tr<?= isset($row['status']) ? ' data-status="' . $this->e($row['status']) . '"' : '' ?>>
<?php foreach ($row['cells'] as $cell): ?>
<?php
    $isNum = !empty($cell['numeric']);
    $sortVal = $cell['sortValue'] ?? ($cell['text'] ?? '');
?>
                    <td<?= $isNum ? ' class="data-table__num"' : '' ?><?= $sortVal !== '' ? ' data-sort-value="' . $this->e($sortVal) . '"' : '' ?>>
                        <?= $cell['html'] ?? $this->e($cell['text'] ?? '') ?>
                    </td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-pagination">
        <span class="table-pagination__summary" data-page-summary aria-live="polite">
            Showing 1–<?= $this->e((string) min($pageSize, count($rows))) ?> of <?= $this->e((string) count($rows)) ?>
        </span>
        <div class="table-pagination__controls">
            <button class="button button--secondary button--sm" type="button" data-page-prev>Previous</button>
            <button class="button button--secondary button--sm" type="button" data-page-next>Next</button>
        </div>
    </div>
</section>
