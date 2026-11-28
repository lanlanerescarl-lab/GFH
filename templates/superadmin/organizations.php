<?php
/**
 * Organizations list (/superadmin/organizations) — SA.1b.
 *
 * Extension point (registration module):
 *   Named slot: page-header `actionsHtml` (renders inside `.page-header__actions` in `partials/page-header.php`).
 *   The separate organization registration module will later place its primary "New organization" action here,
 *   rendered only for users holding `organizations:create`. SA.1 renders nothing in this slot.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var list<array<string, mixed>> $items
 * @var array{total: int, active: int, suspended: int, archived: int} $counts
 * @var bool $hasAny
 * @var array{q: string, status: string} $filters
 */
$items ??= [];
$hasAny ??= false;
$filters ??= ['q' => '', 'status' => ''];

$listUrl = $basePath . '/superadmin/organizations';

$this->layout('layout');
$this->start('title');
echo $this->e('Organizations');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Organizations',
    'subtitle' => 'Registered organizations, reporting contracts and data freshness. Existing records are read-only.',
    // Extension slot `actionsHtml` intentionally left empty in SA.1; filled later by the registration module.
    'actionsHtml' => '',
]) ?>

<section class="card sa-filter-card" aria-label="Filter organizations">
    <form class="sa-filter-form sa-filter-form--orgs" method="get" action="<?= $this->e($listUrl) ?>">
        <div class="field sa-filter-field">
            <label class="field__label" for="sa-org-search">Search</label>
            <input
                type="search"
                id="sa-org-search"
                name="q"
                value="<?= $this->e($filters['q']) ?>"
                placeholder="Name or slug"
                maxlength="100"
            >
        </div>
        <div class="field sa-filter-field">
            <label class="field__label" for="sa-org-status">Status</label>
            <select id="sa-org-status" name="status">
                <option value=""<?= $filters['status'] === '' ? ' selected' : '' ?>>All</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="suspended"<?= $filters['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                <option value="archived"<?= $filters['status'] === 'archived' ? ' selected' : '' ?>>Archived</option>
            </select>
        </div>
        <div class="sa-filter-actions">
            <button class="button sa-filter-btn" type="submit">Apply</button>
            <a class="button button--ghost sa-filter-btn" href="<?= $this->e($listUrl) ?>">Reset</a>
        </div>
    </form>
</section>

<?php if (!$hasAny): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No organizations registered yet',
    'body' => 'Register an organization in the core registry using php bin/make-org.php to see it listed here.',
]) ?>
<?php elseif ($items === []): ?>
<?= $this->partial('partials/empty-state', [
    'title' => 'No organizations match',
    'body' => 'No registered organizations match the current search and status filter.',
    'actionsHtml' => '<a class="button button--secondary" href="' . $this->e($listUrl) . '">Reset</a>',
]) ?>
<?php else: ?>
<section class="card data-table-card" aria-label="Registered organizations">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Organization</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Status</th>
                    <th scope="col">Enabled features</th>
                    <th scope="col">Versions</th>
                    <th scope="col">Data freshness</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($items as $org): ?>
                <tr>
                    <td>
                        <a class="sa-table-link" href="<?= $this->e($listUrl . '/' . rawurlencode((string) $org['slug'])) ?>"><?= $this->e((string) $org['name']) ?></a>
                        <span class="sa-cell-sub"><?= $this->e((string) $org['short_name']) ?></span>
                    </td>
                    <td><code class="sa-mono"><?= $this->e((string) $org['slug']) ?></code></td>
                    <td><?= $this->partial('partials/badge', ['label' => (string) $org['status_label'], 'tone' => (string) $org['status_tone']]) ?></td>
                    <td>
<?php if ($org['features'] === []): ?>
                        <span class="sa-muted">None</span>
<?php else: ?>
                        <div class="sa-tags">
<?php foreach ($org['features'] as $feature): ?>
                            <span class="tag"><?= $this->e((string) $feature) ?></span>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                    </td>
                    <td>
                        <span class="sa-cell-stack">
                            <span>Migration <code class="sa-mono"><?= $this->e((string) $org['migration_label']) ?></code></span>
                            <span class="sa-cell-sub">Template <?= $org['template_version'] !== null ? '<code class="sa-mono">' . $this->e((string) $org['template_version']) . '</code>' : '—' ?></span>
                        </span>
                    </td>
                    <td><span class="sa-muted"><?= $this->e((string) $org['freshness_label']) ?></span></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
