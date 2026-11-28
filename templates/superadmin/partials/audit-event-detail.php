<?php
/**
 * Audit Event detail body (rendered inside the Inspect drawer and per-row <template>).
 * Every value is server-escaped with $this->e(); sensitive keys have already been omitted by the service.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var array<string, mixed> $event
 */
$pairs = is_array($event['detail_pairs'] ?? null) ? $event['detail_pairs'] : [];
?>
<div class="sa-audit-detail">
    <p class="sa-audit-detail__summary"><?= $this->e((string) ($event['summary'] ?? '')) ?></p>

    <section class="sa-audit-detail__section" aria-label="Event metadata">
        <h3 class="sa-kicker">Event metadata</h3>
        <dl class="sa-dl">
            <div class="sa-dl__row">
                <dt>Time (Asia/Manila)</dt>
                <dd><?= $this->e((string) ($event['occurred_at_manila'] ?? '—')) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Time (UTC)</dt>
                <dd><?= $this->e((string) ($event['occurred_at_utc'] ?? '—')) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Actor</dt>
                <dd><?= $this->e((string) ($event['actor_name'] ?? 'System')) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Organization</dt>
                <dd><?= $this->e((string) ($event['organization_name'] ?? 'Core group')) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Action</dt>
                <dd><code class="sa-mono"><?= $this->e((string) ($event['action'] ?? '')) ?></code></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Entity</dt>
                <dd><?= $this->e((string) ($event['entity'] ?? '—')) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Entity ID</dt>
                <dd><code class="sa-mono"><?= $this->e((string) ($event['entity_id'] ?? '—')) ?></code></dd>
            </div>
        </dl>
    </section>

    <section class="sa-audit-detail__section" aria-label="Recorded detail">
        <h3 class="sa-kicker">Recorded detail</h3>
<?php if ($pairs === []): ?>
        <p class="sa-muted">No additional detail recorded.</p>
<?php else: ?>
        <dl class="sa-dl sa-dl--pairs">
<?php foreach ($pairs as $pair): ?>
            <div class="sa-dl__row">
                <dt><code class="sa-mono"><?= $this->e((string) $pair['key']) ?></code></dt>
                <dd class="sa-audit-detail__value"><?= $this->e((string) $pair['value']) ?></dd>
            </div>
<?php endforeach; ?>
        </dl>
<?php endif; ?>
    </section>
</div>
