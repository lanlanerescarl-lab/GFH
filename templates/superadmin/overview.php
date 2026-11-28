<?php
/**
 * Group Overview (/superadmin) — SA.1b registry-only summary.
 * Shows organization status counts, the registered organizations list, and the 10 newest core audit events.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var array{total: int, active: int, suspended: int, archived: int} $counts
 * @var list<array<string, mixed>> $organizations
 * @var list<array<string, mixed>> $recentActivity
 */
$counts ??= ['total' => 0, 'active' => 0, 'suspended' => 0, 'archived' => 0];
$organizations ??= [];
$recentActivity ??= [];

$total = (int) ($counts['total'] ?? 0);
$orgNoun = $total === 1 ? 'organization' : 'organizations';
$subtitle = sprintf('Consolidated performance and operational health across %d GFH %s.', $total, $orgNoun);

$this->layout('layout');
$this->start('title');
echo $this->e('Group Overview');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Group Overview',
    'subtitle' => $subtitle,
]) ?>

<section class="sa-section" aria-labelledby="sa-overview-counts-heading">
    <p class="sa-kicker" id="sa-overview-counts-heading">Organization registry</p>
    <div class="kpi-grid sa-kpi-grid">
        <article class="kpi-card">
            <div class="kpi-card__header">
                <p class="kpi-card__label">Organizations</p>
                <?= $this->partial('partials/badge', ['label' => 'Total', 'tone' => 'info']) ?>
            </div>
            <p class="kpi-card__value"><?= $this->e((string) $total) ?></p>
            <div class="kpi-card__meta"><span>Registered in core</span></div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card__header">
                <p class="kpi-card__label">Active</p>
                <?= $this->partial('partials/badge', ['label' => 'Active', 'tone' => 'ok']) ?>
            </div>
            <p class="kpi-card__value"><?= $this->e((string) ($counts['active'] ?? 0)) ?></p>
            <div class="kpi-card__meta"><span>Serving shops, POS and Admin</span></div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card__header">
                <p class="kpi-card__label">Suspended</p>
                <?= $this->partial('partials/badge', ['label' => 'Suspended', 'tone' => 'warn']) ?>
            </div>
            <p class="kpi-card__value"><?= $this->e((string) ($counts['suspended'] ?? 0)) ?></p>
            <div class="kpi-card__meta"><span>Temporarily unavailable</span></div>
        </article>
        <article class="kpi-card">
            <div class="kpi-card__header">
                <p class="kpi-card__label">Archived</p>
                <?= $this->partial('partials/badge', ['label' => 'Archived', 'tone' => 'neutral']) ?>
            </div>
            <p class="kpi-card__value"><?= $this->e((string) ($counts['archived'] ?? 0)) ?></p>
            <div class="kpi-card__meta"><span>Retired from service</span></div>
        </article>
    </div>
</section>

<section class="sa-section" aria-labelledby="sa-overview-orgs-heading">
    <div class="sa-section__head">
        <div>
            <p class="sa-kicker">Registry</p>
            <h2 class="sa-section__title" id="sa-overview-orgs-heading">Organizations</h2>
        </div>
        <a class="button button--secondary button--sm sa-action-link" href="<?= $this->e($basePath . '/superadmin/organizations') ?>">View all organizations</a>
    </div>

<?php if ($organizations === []): ?>
    <?= $this->partial('partials/empty-state', [
        'title' => 'No organizations registered yet',
        'body' => 'Register an organization in the core database using php bin/make-org.php to see it here.',
    ]) ?>
<?php else: ?>
    <div class="card data-table-card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Organization</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Status</th>
                        <th scope="col">Migration</th>
                        <th scope="col">Template</th>
                        <th scope="col"><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($organizations as $org): ?>
                    <tr>
                        <td>
                            <a class="sa-table-link" href="<?= $this->e($basePath . '/superadmin/organizations/' . rawurlencode((string) $org['slug'])) ?>"><?= $this->e((string) $org['name']) ?></a>
                            <span class="sa-cell-sub"><?= $this->e((string) $org['short_name']) ?></span>
                        </td>
                        <td><code class="sa-mono"><?= $this->e((string) $org['slug']) ?></code></td>
                        <td><?= $this->partial('partials/badge', ['label' => (string) $org['status_label'], 'tone' => (string) $org['status_tone']]) ?></td>
                        <td><code class="sa-mono"><?= $this->e((string) $org['migration_label']) ?></code></td>
                        <td><?= $org['template_version'] !== null ? '<code class="sa-mono">' . $this->e((string) $org['template_version']) . '</code>' : '<span class="sa-muted">—</span>' ?></td>
                        <td class="sa-cell-action">
                            <a class="button button--ghost button--sm sa-action-link" href="<?= $this->e($basePath . '/superadmin/organizations/' . rawurlencode((string) $org['slug'])) ?>">Details</a>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</section>

<section class="sa-section" aria-labelledby="sa-overview-activity-heading">
    <div class="sa-section__head">
        <div>
            <p class="sa-kicker">Core audit log</p>
            <h2 class="sa-section__title" id="sa-overview-activity-heading">Recent system activity</h2>
        </div>
        <a class="button button--secondary button--sm sa-action-link" href="<?= $this->e($basePath . '/superadmin/audit') ?>">View audit log</a>
    </div>

<?php if ($recentActivity === []): ?>
    <?= $this->partial('partials/empty-state', [
        'title' => 'No system activity recorded yet',
        'body' => 'Group sign-ins, account changes and registry actions will appear here as they happen.',
    ]) ?>
<?php else: ?>
    <div class="card data-table-card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Acting user</th>
                        <th scope="col">Action</th>
                        <th scope="col">Summary</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($recentActivity as $event): ?>
                    <tr>
                        <td class="sa-cell-nowrap"><?= $this->e((string) $event['occurred_at_manila']) ?></td>
                        <td><?= $this->e((string) $event['actor_name']) ?></td>
                        <td><code class="sa-mono"><?= $this->e((string) $event['action']) ?></code></td>
                        <td><?= $this->e((string) $event['summary']) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</section>
