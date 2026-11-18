<?php

declare(strict_types=1);

/**
 * Admin Team screen (GET /{org}/admin/team).
 * The organization's team with search, sort, role and status filters and pagination. Staff and Cashier rows link to
 * their own screen; Admin and Manager rows are read-only and have no actions.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array{items: list<array<string, mixed>>, pagination: array<string, int>, sort: string} $result
 * @var \Gfh\Core\Support\ListQuery $query
 * @var array<string, string> $filterErrors
 * @var array<string, string> $filters
 * @var list<string> $roles
 * @var list<string> $statuses
 * @var string $teamUrl
 * @var string $newUrl
 * @var string $csrf
 */
use Gfh\Core\Support\Format;

$this->layout('layout');
$this->start('title');
echo $this->e('Team');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$filtersHtml = '<div class="field"><label class="field__label" for="team-filter-role">Role</label>'
    . '<select id="team-filter-role" name="role"><option value="">All roles</option>';
foreach ($roles as $role) {
    $filtersHtml .= '<option value="' . $this->e($role) . '"' . ($filters['role'] === $role ? ' selected' : '') . '>' . $this->e(ucfirst($role)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['role']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['role']) . '</p>' : '')
    . '</div>';

$filtersHtml .= '<div class="field"><label class="field__label" for="team-filter-status">Status</label>'
    . '<select id="team-filter-status" name="status"><option value="">All statuses</option>';
foreach ($statuses as $st) {
    $filtersHtml .= '<option value="' . $this->e($st) . '"' . ($filters['status'] === $st ? ' selected' : '') . '>' . $this->e(ucfirst($st)) . '</option>';
}
$filtersHtml .= '</select>'
    . (!empty($filterErrors['status']) ? '<p class="field-error" role="alert">' . $this->e($filterErrors['status']) . '</p>' : '')
    . '</div>';

$tones = ['active' => 'ok', 'pending' => 'warn', 'inactive' => 'neutral'];
$rows = [];
foreach ($result['items'] as $member) {
    $id = (string) $member['id'];
    $status = (string) $member['status'];
    $manageable = !empty($member['manageable']);

    $rows[] = [
        'cells' => [
            $manageable
                ? ['html' => '<a class="table-link" href="' . $this->e($teamUrl . '/' . $id) . '">' . $this->e((string) $member['full_name']) . '</a>']
                : ['text' => (string) $member['full_name']],
            ['text' => (string) $member['email']],
            ['text' => ucfirst((string) $member['role'])],
            ['html' => $this->partial('partials/badge', ['label' => ucfirst($status), 'tone' => $tones[$status] ?? 'neutral'])],
            ['text' => $member['last_login_at'] !== null ? Format::date((string) $member['last_login_at']) : 'Never'],
            $manageable
                ? ['html' => '<a class="button button--ghost button--sm" href="' . $this->e($teamUrl . '/' . $id) . '">Manage</a>']
                : ['html' => '<span class="cell-meta">Read-only</span>'],
        ],
    ];
}
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Team',
    'title' => 'Team',
    'subtitle' => 'Staff and Cashier accounts for this organization. Admin and Manager accounts are shown for reference and are changed by a Super Admin.',
    'actionsHtml' => '<a class="button button--primary" href="' . $this->e($newUrl) . '">Add team member</a>',
]) ?>

<?= $this->partial('partials/server-table', [
    'id' => 'team-table',
    'action' => $teamUrl,
    'searchLabel' => 'Search team',
    'searchPlaceholder' => 'Name or email…',
    'searchValue' => $query->search ?? '',
    'sort' => $result['sort'],
    'perPage' => $query->perPage,
    'filters' => $filters,
    'filtersHtml' => $filtersHtml,
    'columns' => [
        ['label' => 'Name', 'sortKey' => 'name'],
        ['label' => 'Email', 'sortKey' => 'email'],
        ['label' => 'Role', 'sortKey' => 'role'],
        ['label' => 'Status', 'sortKey' => 'status'],
        ['label' => 'Last sign-in', 'sortKey' => 'last_login'],
        ['label' => 'Actions'],
    ],
    'rows' => $rows,
    'pagination' => $result['pagination'],
    'emptyTitle' => 'No team members match these filters',
    'emptyBody' => 'Clear the filters or add a team member.',
]) ?>
