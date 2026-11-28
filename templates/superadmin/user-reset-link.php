<?php
/**
 * One-time password reset link screen (POST /superadmin/users/{id}/reset-link) — SA.2a.
 * Shown once on the POST response only with Cache-Control: no-store. Never stored in plain text,
 * never logged, and never written to the audit log.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var array<string, mixed> $account
 * @var array{id: string, name: string, slug: string} $organization
 * @var array{url: string, expires_at: string} $link
 * @var string $detailUrl
 */
$this->layout('layout');
$this->start('title');
echo $this->e('One-time password reset link');
$this->end();
?>
<p class="sa-breadcrumb">
    <a class="sa-back-link" href="<?= $this->e($detailUrl) ?>">← Back to <?= $this->e((string) $account['full_name']) ?></a>
</p>

<?= $this->partial('partials/page-header', [
    'title' => 'One-time password reset link',
    'subtitle' => 'For ' . (string) $account['full_name'] . ' (' . (string) $account['email'] . ') via ' . (string) $organization['name'] . '.',
]) ?>

<section class="card sa-reset-card" aria-labelledby="sa-reset-ready-heading">
    <div class="card__header">
        <div>
            <p class="sa-kicker">Shown once only</p>
            <h2 class="card__title" id="sa-reset-ready-heading">One-time link ready</h2>
            <p class="card__subtitle">Expires <?= $this->e((string) $link['expires_at']) ?> Asia/Manila</p>
        </div>
    </div>

    <div class="sa-reset-box">
        <code class="sa-mono sa-reset-box__url"><?= $this->e((string) $link['url']) ?></code>
    </div>

    <p class="sa-muted">GFH sends no email. Copy this link now and pass it to the person yourself. It works once, it is not stored in plain text, and it will not be shown again once you leave this page. Generating another link cancels this one.</p>

    <div class="sa-form-actions">
        <a class="button button--secondary sa-filter-btn" href="<?= $this->e($detailUrl) ?>">Back to account</a>
    </div>
</section>
