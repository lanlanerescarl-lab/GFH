<?php

declare(strict_types=1);

/**
 * Admin Settings hub (GET /{org}/admin/settings).
 * One card per section registered in Gfh\Core\Support\SettingsSections, each with a short description and a link.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array{key: string, label: string, description: string, path: string, url: string}> $sections
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Settings');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Settings',
    'subtitle' => 'How this organization is set up. Only an Admin can open these pages.',
]) ?>

<section class="kit-section" aria-label="Settings sections">
    <div class="kit-grid">
<?php foreach ($sections as $section): ?>
        <?= $this->partial('partials/card', [
            'title' => (string) $section['label'],
            'bodyHtml' => '<p>' . $this->e((string) $section['description']) . '</p>',
            'footerHtml' => '<a class="button button--secondary button--sm" href="' . $this->e((string) $section['url']) . '">Open ' . $this->e((string) $section['label']) . '</a>',
            'variant' => 'interactive',
        ]) ?>
<?php endforeach; ?>
    </div>
</section>
