<?php
/**
 * Audit Log (/superadmin/audit) — SA.1b.
 * Core append-only audit log with filter bar, sortable time column, 25-row pagination, and Inspect drawer.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var \Gfh\Core\View\SuperAdminAssetUrls $assets
 * @var string $basePath
 * @var list<array<string, mixed>> $items
 * @var array{page: int, per_page: int, total: int, pages: int, from: int, to: int} $pagination
 * @var string $sort '-time' | 'time'
 * @var array{q: string, from: string, to: string, organization: string, actor: string, action: string, entity: string} $filters
 * @var array{organizations: list<array{id: string, name: string}>, actors: list<array{id: string, name: string}>, actions: list<string>, entities: list<string>} $options
 * @var array<string, string> $errors
 * @var array<string, mixed>|null $inspected
 * @var bool $hasActiveFilters
 */
$items ??= [];
$pagination ??= ['page' => 1, 'per_page' => 25, 'total' => 0, 'pages' => 1, 'from' => 0, 'to' => 0];
$sort ??= '-time';
$filters ??= ['q' => '', 'from' => '', 'to' => '', 'organization' => '', 'actor' => '', 'action' => '', 'entity' => ''];
$options ??= ['organizations' => [], 'actors' => [], 'actions' => [], 'entities' => []];
$errors ??= [];
$inspected ??= null;
$hasActiveFilters ??= false;

$auditUrl = $basePath . '/superadmin/audit';

$buildUrl = static function (string $base, array $params): string {
    $clean = [];
    foreach ($params as $key => $val) {
        if ($val !== '' && $val !== null) {
            $clean[$key] = $val;
        }
    }

    return $clean === [] ? $base : $base . '?' . http_build_query($clean);
};

$baseParams = [
    'q' => $filters['q'],
    'from' => $filters['from'],
    'to' => $filters['to'],
    'organization' => $filters['organization'],
    'actor' => $filters['actor'],
    'action' => $filters['action'],
    'entity' => $filters['entity'],
    'sort' => $sort === 'time' ? 'time' : '',
];

$isAsc = $sort === 'time';
$ariaSort = $isAsc ? 'ascending' : 'descending';
$nextSort = $isAsc ? '-time' : 'time';
$sortUrl = $buildUrl($auditUrl, array_merge($baseParams, ['sort' => $nextSort, 'page' => '1']));

$page = (int) $pagination['page'];
$pages = (int) $pagination['pages'];
$total = (int) $pagination['total'];
$fromRow = (int) $pagination['from'];
$toRow = (int) $pagination['to'];

$currentPageParams = array_merge($baseParams, ['page' => $page > 1 ? (string) $page : '']);
$closeDrawerUrl = $buildUrl($auditUrl, $currentPageParams);

// Build a compact window of numbered page buttons around the current page.
$pageNumbers = [];
if ($pages <= 7) {
    $pageNumbers = range(1, $pages);
} else {
    $pageNumbers = array_values(array_unique(array_merge(
        [1],
        range(max(1, $page - 2), min($pages, $page + 2)),
        [$pages],
    )));
    sort($pageNumbers);
}

$this->layout('layout');
$this->start('title');
echo $this->e('Audit Log');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/audit-drawer.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Audit Log',
    'subtitle' => 'Append-only history across group governance and organization registry actions.',
]) ?>

<section class="card sa-filter-card" aria-label="Filter audit log">
    <form class="sa-filter-form sa-filter-form--audit" method="get" action="<?= $this->e($auditUrl) ?>">
<?php if ($sort === 'time'): ?>
        <input type="hidden" name="sort" value="time">
<?php endif; ?>
        <div class="field sa-filter-field sa-filter-field--wide">
            <label class="field__label" for="sa-audit-q">Search</label>
            <input
                type="search"
                id="sa-audit-q"
                name="q"
                value="<?= $this->e($filters['q']) ?>"
                placeholder="Action, actor or entity ID"
                maxlength="100"
            >
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-from">From</label>
            <input
                type="date"
                id="sa-audit-from"
                name="from"
                value="<?= $this->e($filters['from']) ?>"
                <?= isset($errors['from']) ? 'aria-invalid="true" aria-describedby="sa-audit-from-error"' : '' ?>
            >
<?php if (isset($errors['from'])): ?>
            <p class="field-error" id="sa-audit-from-error"><?= $this->e($errors['from']) ?></p>
<?php endif; ?>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-to">To</label>
            <input
                type="date"
                id="sa-audit-to"
                name="to"
                value="<?= $this->e($filters['to']) ?>"
                <?= isset($errors['to']) ? 'aria-invalid="true" aria-describedby="sa-audit-to-error"' : '' ?>
            >
<?php if (isset($errors['to'])): ?>
            <p class="field-error" id="sa-audit-to-error"><?= $this->e($errors['to']) ?></p>
<?php endif; ?>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-org">Organization</label>
            <select id="sa-audit-org" name="organization">
                <option value=""<?= $filters['organization'] === '' ? ' selected' : '' ?>>All organizations</option>
                <option value="core"<?= $filters['organization'] === 'core' ? ' selected' : '' ?>>Core group</option>
<?php foreach ($options['organizations'] as $orgOpt): ?>
                <option value="<?= $this->e($orgOpt['id']) ?>"<?= $filters['organization'] === $orgOpt['id'] ? ' selected' : '' ?>><?= $this->e($orgOpt['name']) ?></option>
<?php endforeach; ?>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-action">Action</label>
            <select id="sa-audit-action" name="action">
                <option value=""<?= $filters['action'] === '' ? ' selected' : '' ?>>All actions</option>
<?php
$actionList = $options['actions'];
if ($filters['action'] !== '' && !in_array($filters['action'], $actionList, true)) {
    $actionList[] = $filters['action'];
}
foreach ($actionList as $actOpt):
?>
                <option value="<?= $this->e($actOpt) ?>"<?= $filters['action'] === $actOpt ? ' selected' : '' ?>><?= $this->e($actOpt) ?></option>
<?php endforeach; ?>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-actor">Actor</label>
            <select id="sa-audit-actor" name="actor">
                <option value=""<?= $filters['actor'] === '' ? ' selected' : '' ?>>All actors</option>
                <option value="system"<?= $filters['actor'] === 'system' ? ' selected' : '' ?>>System</option>
<?php foreach ($options['actors'] as $actorOpt): ?>
                <option value="<?= $this->e($actorOpt['id']) ?>"<?= $filters['actor'] === $actorOpt['id'] ? ' selected' : '' ?>><?= $this->e($actorOpt['name']) ?></option>
<?php endforeach; ?>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-audit-entity">Entity</label>
            <select id="sa-audit-entity" name="entity">
                <option value=""<?= $filters['entity'] === '' ? ' selected' : '' ?>>All entities</option>
<?php
$entityList = $options['entities'];
if ($filters['entity'] !== '' && !in_array($filters['entity'], $entityList, true)) {
    $entityList[] = $filters['entity'];
}
foreach ($entityList as $entOpt):
?>
                <option value="<?= $this->e($entOpt) ?>"<?= $filters['entity'] === $entOpt ? ' selected' : '' ?>><?= $this->e($entOpt) ?></option>
<?php endforeach; ?>
            </select>
        </div>

        <div class="sa-filter-actions">
            <button class="button sa-filter-btn" type="submit">Apply</button>
            <a class="button button--ghost sa-filter-btn" href="<?= $this->e($auditUrl) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card data-table-card" aria-label="Audit log events">
<?php if ($items === []): ?>
    <div class="sa-table-empty">
        <?= $this->partial('partials/empty-state', [
            'title' => 'No audit events match',
            'body' => 'No events match the current search, date range and filters.',
            'actionsHtml' => $hasActiveFilters
                ? '<a class="button button--secondary" href="' . $this->e($auditUrl) . '">Reset</a>'
                : '',
        ]) ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col" aria-sort="<?= $this->e($ariaSort) ?>">
                        <a class="data-table__sort" href="<?= $this->e($sortUrl) ?>">
                            <span>Time</span>
                            <span aria-hidden="true"><?= $isAsc ? '↑' : '↓' ?></span>
                        </a>
                    </th>
                    <th scope="col">Acting user</th>
                    <th scope="col">Organization</th>
                    <th scope="col">Action</th>
                    <th scope="col">Entity</th>
                    <th scope="col">Summary</th>
                    <th scope="col">Inspect</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($items as $event): ?>
<?php
    $eventId = (string) $event['id'];
    $inspectUrl = $buildUrl($auditUrl, array_merge($currentPageParams, ['inspect' => $eventId]));
?>
                <tr>
                    <td class="sa-cell-nowrap"><?= $this->e((string) $event['occurred_at_manila']) ?></td>
                    <td><?= $this->e((string) $event['actor_name']) ?></td>
                    <td><?= $this->e((string) $event['organization_name']) ?></td>
                    <td><code class="sa-mono"><?= $this->e((string) $event['action']) ?></code></td>
                    <td>
                        <span><?= $this->e((string) $event['entity']) ?></span>
<?php if ($event['entity_id'] !== '—'): ?>
                        <span class="sa-cell-sub"><code class="sa-mono"><?= $this->e((string) $event['entity_id']) ?></code></span>
<?php endif; ?>
                    </td>
                    <td class="sa-cell-summary"><?= $this->e((string) $event['summary']) ?></td>
                    <td class="sa-cell-action">
                        <a
                            class="button button--secondary button--sm sa-action-link"
                            href="<?= $this->e($inspectUrl) ?>"
                            data-sa-audit-inspect="<?= $this->e($eventId) ?>"
                            aria-controls="sa-audit-drawer"
                        >Inspect</a>
                        <template id="sa-audit-tpl-<?= $this->e($eventId) ?>"><?= $this->partial('partials/audit-event-detail', ['event' => $event]) ?></template>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

    <div class="table-pagination sa-pagination">
        <span class="table-pagination__summary" aria-live="polite">
            Showing <?= $this->e((string) $fromRow) ?> to <?= $this->e((string) $toRow) ?> of <?= $this->e((string) $total) ?>
        </span>
<?php if ($total > 0): ?>
        <nav class="table-pagination__controls sa-pagination__controls" aria-label="Audit log pages">
<?php if ($page > 1): ?>
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($auditUrl, array_merge($baseParams, ['page' => (string) ($page - 1)]))) ?>" aria-label="Previous page">Previous</a>
<?php else: ?>
            <span class="button button--secondary button--sm sa-page-btn" aria-disabled="true">Previous</span>
<?php endif; ?>
<?php
$prevNum = null;
foreach ($pageNumbers as $num):
    if ($prevNum !== null && $num > $prevNum + 1):
?>
            <span class="sa-pagination__ellipsis" aria-hidden="true">…</span>
<?php endif; ?>
<?php if ($num === $page): ?>
            <span class="button button--sm sa-page-btn" aria-current="page" aria-label="Page <?= $this->e((string) $num) ?>"><?= $this->e((string) $num) ?></span>
<?php else: ?>
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($auditUrl, array_merge($baseParams, ['page' => (string) $num]))) ?>" aria-label="Page <?= $this->e((string) $num) ?>"><?= $this->e((string) $num) ?></a>
<?php endif; ?>
<?php
    $prevNum = $num;
endforeach;
?>
<?php if ($page < $pages): ?>
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($auditUrl, array_merge($baseParams, ['page' => (string) ($page + 1)]))) ?>" aria-label="Next page">Next</a>
<?php else: ?>
            <span class="button button--secondary button--sm sa-page-btn" aria-disabled="true">Next</span>
<?php endif; ?>
        </nav>
<?php endif; ?>
    </div>
</section>

<div class="overlay-backdrop overlay-backdrop--drawer sa-audit-backdrop" id="sa-audit-drawer" data-sa-audit-drawer<?= $inspected === null ? ' hidden' : '' ?>>
    <div class="drawer sa-audit-drawer" role="dialog" aria-modal="true" aria-labelledby="sa-audit-drawer-title" tabindex="-1">
        <div class="drawer__header">
            <h2 class="drawer__title" id="sa-audit-drawer-title">Audit Event</h2>
            <a class="button button--ghost button--sm sa-drawer-close" href="<?= $this->e($closeDrawerUrl) ?>" data-sa-drawer-close aria-label="Close audit event drawer">Close</a>
        </div>
        <div class="drawer__body" data-sa-drawer-body>
<?php if ($inspected !== null): ?>
            <?= $this->partial('partials/audit-event-detail', ['event' => $inspected]) ?>
<?php endif; ?>
        </div>
        <div class="drawer__footer">
            <a class="button button--secondary sa-drawer-close" href="<?= $this->e($closeDrawerUrl) ?>" data-sa-drawer-close>Close</a>
        </div>
    </div>
</div>
