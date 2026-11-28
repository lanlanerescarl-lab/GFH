<?php
/**
 * Organization detail (/superadmin/organizations/{slug}) — SA.1b.
 * Displays general registry information, enabled feature keys, and validated branding values.
 * Never reads, decrypts, or displays connection_encrypted or any secret.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var array<string, mixed> $organization
 */
$listUrl = $basePath . '/superadmin/organizations';
$features = is_array($organization['features'] ?? null) ? $organization['features'] : [];
$branding = is_array($organization['branding'] ?? null) ? $organization['branding'] : [];

$this->layout('layout');
$this->start('title');
echo $this->e((string) $organization['name']);
$this->end();
?>
<p class="sa-breadcrumb">
    <a class="sa-back-link" href="<?= $this->e($listUrl) ?>">← Back to organizations</a>
</p>

<?= $this->partial('partials/page-header', [
    'title' => (string) $organization['name'],
    'subtitle' => 'Organization registry record (' . (string) $organization['slug'] . ').',
]) ?>

<div class="sa-info-banner" role="note">
    <?= $this->partial('partials/badge', ['label' => 'Read-only record', 'tone' => 'neutral']) ?>
    <p class="sa-info-banner__text">Existing organization registry records are read-only from this console. Connection details are never displayed.</p>
</div>

<section class="card sa-detail-card" aria-labelledby="sa-org-general-heading">
    <div class="card__header">
        <div>
            <p class="sa-kicker">Registry metadata</p>
            <h2 class="card__title" id="sa-org-general-heading">General information</h2>
        </div>
        <?= $this->partial('partials/badge', ['label' => (string) $organization['status_label'], 'tone' => (string) $organization['status_tone']]) ?>
    </div>

    <dl class="sa-dl sa-dl--grid">
        <div class="sa-dl__row">
            <dt>Name</dt>
            <dd><?= $this->e((string) $organization['name']) ?></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Short name</dt>
            <dd><?= $this->e((string) $organization['short_name']) ?></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Slug</dt>
            <dd><code class="sa-mono"><?= $this->e((string) $organization['slug']) ?></code></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Status</dt>
            <dd><?= $this->partial('partials/badge', ['label' => (string) $organization['status_label'], 'tone' => (string) $organization['status_tone']]) ?></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Template version</dt>
            <dd><?= $organization['template_version'] !== null ? '<code class="sa-mono">' . $this->e((string) $organization['template_version']) . '</code>' : '<span class="sa-muted">—</span>' ?></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Migration version</dt>
            <dd><code class="sa-mono"><?= $this->e((string) $organization['migration_label']) ?></code></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Created</dt>
            <dd><?= $this->e((string) $organization['created_at_manila']) ?></dd>
        </div>
        <div class="sa-dl__row">
            <dt>Updated</dt>
            <dd><?= $this->e((string) $organization['updated_at_manila']) ?></dd>
        </div>
    </dl>

    <div class="sa-detail-subsection">
        <h3 class="sa-kicker">Enabled features</h3>
<?php if ($features === []): ?>
        <p class="sa-muted">No features enabled.</p>
<?php else: ?>
        <div class="sa-tags">
<?php foreach ($features as $feature): ?>
            <span class="tag"><?= $this->e((string) $feature) ?></span>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>

    <div class="sa-detail-subsection">
        <h3 class="sa-kicker">Branding values</h3>
<?php if ($branding === []): ?>
        <p class="sa-muted">Default GFH branding (no validated overrides).</p>
<?php else: ?>
        <ul class="sa-branding-list">
<?php foreach ($branding as $entry): ?>
            <li class="sa-branding-item">
                <span class="sa-branding-item__label"><?= $this->e((string) $entry['label']) ?></span>
                <span class="sa-branding-item__value">
<?php if ($entry['kind'] === 'color'): ?>
                    <span class="sa-swatch" style="background-color: <?= $this->e((string) $entry['value']) ?>;" aria-hidden="true"></span>
<?php endif; ?>
                    <code class="sa-mono"><?= $this->e((string) $entry['value']) ?></code>
                </span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </div>
</section>
