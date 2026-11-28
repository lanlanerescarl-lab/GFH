<?php
/**
 * Users and Access (/superadmin/users) — SA.2a.
 * One directory across all organizations with search, filters, sorting, 25-row pagination,
 * separate account and membership statuses, and Super Admin creation actions.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var list<array<string, mixed>> $items
 * @var array{page: int, per_page: int, total: int, pages: int, from: int, to: int} $pagination
 * @var string $sort 'name' | '-name' | 'last_login' | '-last_login'
 * @var array{q: string, account_type: string, organization: string, role: string, status: string} $filters
 * @var array{organizations: list<array{id: string, slug: string, name: string, status: string}>} $options
 * @var bool $hasActiveFilters
 * @var bool $canEdit
 */
$items ??= [];
$pagination ??= ['page' => 1, 'per_page' => 25, 'total' => 0, 'pages' => 1, 'from' => 0, 'to' => 0];
$sort ??= 'name';
$filters ??= ['q' => '', 'account_type' => '', 'organization' => '', 'role' => '', 'status' => ''];
$options ??= ['organizations' => []];
$hasActiveFilters ??= false;
$canEdit ??= false;

$usersUrl = $basePath . '/superadmin/users';

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
    'account_type' => $filters['account_type'],
    'organization' => $filters['organization'],
    'role' => $filters['role'],
    'status' => $filters['status'],
    'sort' => $sort !== 'name' ? $sort : '',
];

$nameAriaSort = match ($sort) {
    'name' => 'ascending',
    '-name' => 'descending',
    default => 'none',
};
$nextNameSort = $sort === 'name' ? '-name' : 'name';
$nameSortUrl = $buildUrl($usersUrl, array_merge($baseParams, ['sort' => $nextNameSort === 'name' ? '' : $nextNameSort, 'page' => '1']));

$loginAriaSort = match ($sort) {
    'last_login' => 'ascending',
    '-last_login' => 'descending',
    default => 'none',
};
$nextLoginSort = $sort === '-last_login' ? 'last_login' : '-last_login';
$loginSortUrl = $buildUrl($usersUrl, array_merge($baseParams, ['sort' => $nextLoginSort, 'page' => '1']));

$page = (int) $pagination['page'];
$pages = (int) $pagination['pages'];
$total = (int) $pagination['total'];
$fromRow = (int) $pagination['from'];
$toRow = (int) $pagination['to'];

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

$headerActionsHtml = '';
if ($canEdit) {
    $headerActionsHtml = '<a class="button sa-action-link" href="' . $this->e($usersUrl . '/new-staff') . '">Create staff account</a>'
        . '<a class="button button--secondary sa-action-link" href="' . $this->e($usersUrl . '/new-group') . '">Create group account</a>';
}

$this->layout('layout');
$this->start('title');
echo $this->e('Users and Access');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Users and Access',
    'subtitle' => 'Group-level roles are held on the account; organization roles are held per membership.',
    'actionsHtml' => $headerActionsHtml,
]) ?>

<section class="card sa-filter-card" aria-label="Filter accounts">
    <form class="sa-filter-form sa-filter-form--users" method="get" action="<?= $this->e($usersUrl) ?>">
<?php if ($sort !== 'name'): ?>
        <input type="hidden" name="sort" value="<?= $this->e($sort) ?>">
<?php endif; ?>
        <div class="field sa-filter-field sa-filter-field--wide">
            <label class="field__label" for="sa-users-q">Search</label>
            <input
                type="search"
                id="sa-users-q"
                name="q"
                value="<?= $this->e($filters['q']) ?>"
                placeholder="Name or email"
                maxlength="100"
            >
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-users-account-type">Account type</label>
            <select id="sa-users-account-type" name="account_type">
                <option value=""<?= $filters['account_type'] === '' ? ' selected' : '' ?>>All</option>
                <option value="super_admin"<?= $filters['account_type'] === 'super_admin' ? ' selected' : '' ?>>Super Admin</option>
                <option value="group_viewer"<?= $filters['account_type'] === 'group_viewer' ? ' selected' : '' ?>>Group Viewer</option>
                <option value="org_staff"<?= $filters['account_type'] === 'org_staff' ? ' selected' : '' ?>>Organization staff only</option>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-users-org">Organization membership</label>
            <select id="sa-users-org" name="organization">
                <option value=""<?= $filters['organization'] === '' ? ' selected' : '' ?>>All</option>
                <option value="none"<?= $filters['organization'] === 'none' ? ' selected' : '' ?>>None</option>
<?php foreach ($options['organizations'] as $orgOpt): ?>
                <option value="<?= $this->e($orgOpt['id']) ?>"<?= $filters['organization'] === $orgOpt['id'] ? ' selected' : '' ?>><?= $this->e($orgOpt['name']) ?></option>
<?php endforeach; ?>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-users-role">Organization role</label>
            <select id="sa-users-role" name="role">
                <option value=""<?= $filters['role'] === '' ? ' selected' : '' ?>>All</option>
                <option value="admin"<?= $filters['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                <option value="manager"<?= $filters['role'] === 'manager' ? ' selected' : '' ?>>Manager</option>
                <option value="staff"<?= $filters['role'] === 'staff' ? ' selected' : '' ?>>Staff</option>
                <option value="cashier"<?= $filters['role'] === 'cashier' ? ' selected' : '' ?>>Cashier</option>
            </select>
        </div>

        <div class="field sa-filter-field">
            <label class="field__label" for="sa-users-status">Account status</label>
            <select id="sa-users-status" name="status">
                <option value=""<?= $filters['status'] === '' ? ' selected' : '' ?>>All</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                <option value="pending"<?= $filters['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
            </select>
        </div>

        <div class="sa-filter-actions">
            <button class="button sa-filter-btn" type="submit">Apply</button>
            <a class="button button--ghost sa-filter-btn" href="<?= $this->e($usersUrl) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card data-table-card" aria-label="Accounts directory">
<?php if ($items === []): ?>
    <div class="sa-table-empty">
        <?= $this->partial('partials/empty-state', [
            'title' => 'No accounts match',
            'body' => 'No accounts match the current search and filter selections.',
            'actionsHtml' => $hasActiveFilters
                ? '<a class="button button--secondary" href="' . $this->e($usersUrl) . '">Reset</a>'
                : '',
        ]) ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col" aria-sort="<?= $this->e($nameAriaSort) ?>">
                        <a class="data-table__sort" href="<?= $this->e($nameSortUrl) ?>">
                            <span>Name and email</span>
                            <span aria-hidden="true"><?= $sort === '-name' ? '↓' : ($sort === 'name' ? '↑' : '↕') ?></span>
                        </a>
                    </th>
                    <th scope="col">Group role</th>
                    <th scope="col">Organization memberships</th>
                    <th scope="col">Account status</th>
                    <th scope="col" aria-sort="<?= $this->e($loginAriaSort) ?>">
                        <a class="data-table__sort" href="<?= $this->e($loginSortUrl) ?>">
                            <span>Last sign-in</span>
                            <span aria-hidden="true"><?= $sort === 'last_login' ? '↑' : ($sort === '-last_login' ? '↓' : '↕') ?></span>
                        </a>
                    </th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($items as $account): ?>
<?php
    $detailUrl = $usersUrl . '/' . rawurlencode((string) $account['id']);
    $memberships = is_array($account['memberships'] ?? null) ? $account['memberships'] : [];
?>
                <tr>
                    <td>
                        <a class="sa-table-link" href="<?= $this->e($detailUrl) ?>"><?= $this->e((string) $account['full_name']) ?></a>
                        <span class="sa-cell-sub"><?= $this->e((string) $account['email']) ?></span>
                    </td>
                    <td>
<?php if ($account['org_role'] !== null): ?>
                        <span class="sa-role-badge<?= $account['org_role'] === 'group_viewer' ? ' sa-role-badge--viewer' : '' ?>"><?= $this->e((string) $account['group_role_label']) ?></span>
<?php else: ?>
                        <span class="sa-muted">None</span>
<?php endif; ?>
                    </td>
                    <td>
<?php if ($memberships === []): ?>
                        <span class="sa-muted">None</span>
<?php else: ?>
                        <ul class="sa-membership-list">
<?php foreach ($memberships as $m): ?>
                            <li class="sa-membership-pill">
                                <span class="sa-membership-pill__org"><?= $this->e((string) $m['organization_name']) ?></span>
                                <span class="sa-membership-pill__role"><?= $this->e((string) $m['role_label']) ?></span>
                                <?= $this->partial('partials/badge', ['label' => (string) $m['status_label'], 'tone' => (string) $m['status_tone']]) ?>
                            </li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>
                    </td>
                    <td>
                        <?= $this->partial('partials/badge', ['label' => (string) $account['status_label'], 'tone' => (string) $account['status_tone']]) ?>
                    </td>
                    <td class="sa-cell-nowrap"><?= $this->e((string) $account['last_login_at_manila']) ?></td>
                    <td class="sa-cell-action">
                        <a class="button button--secondary button--sm sa-action-link" href="<?= $this->e($detailUrl) ?>"><?= $canEdit ? 'Manage' : 'View details' ?></a>
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
        <nav class="table-pagination__controls sa-pagination__controls" aria-label="Accounts pages">
<?php if ($page > 1): ?>
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($usersUrl, array_merge($baseParams, ['page' => (string) ($page - 1)]))) ?>" aria-label="Previous page">Previous</a>
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
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($usersUrl, array_merge($baseParams, ['page' => (string) $num]))) ?>" aria-label="Page <?= $this->e((string) $num) ?>"><?= $this->e((string) $num) ?></a>
<?php endif; ?>
<?php
    $prevNum = $num;
endforeach;
?>
<?php if ($page < $pages): ?>
            <a class="button button--secondary button--sm sa-page-btn" href="<?= $this->e($buildUrl($usersUrl, array_merge($baseParams, ['page' => (string) ($page + 1)]))) ?>" aria-label="Next page">Next</a>
<?php else: ?>
            <span class="button button--secondary button--sm sa-page-btn" aria-disabled="true">Next</span>
<?php endif; ?>
        </nav>
<?php endif; ?>
    </div>
</section>
