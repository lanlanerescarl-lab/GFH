<?php

declare(strict_types=1);

/**
 * Admin Team reset link screen (the response to POST /{org}/admin/team/{id}/reset-link).
 * Shows a one-time password reset link once. It is not stored in plain text, not written to any log, and this page
 * is sent with no-cache headers, so leaving it means the link cannot be shown again.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var array<string, mixed> $member
 * @var array{url: string, expires_at: string} $link
 * @var string $memberUrl
 * @var string $teamUrl
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Password reset link');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Team',
    'title' => 'Password reset link',
    'subtitle' => 'For ' . (string) $member['full_name'] . ' (' . (string) $member['email'] . ')',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($memberUrl) . '">Back to ' . $this->e((string) $member['full_name']) . '</a>',
]) ?>

<section class="kit-section" aria-labelledby="team-reset-link-heading">
    <section class="card reset-link-card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="team-reset-link-heading">One-time link ready</h2>
                <p class="card__subtitle">Expires <?= $this->e($link['expires_at']) ?></p>
            </div>
        </div>
        <p class="reset-link-url"><code><?= $this->e($link['url']) ?></code></p>
        <p class="cell-meta">Copy it now and send it to the person yourself. It works once, and it will not be shown again. Generating a new link cancels this one. No password is ever shown.</p>
    </section>
</section>
