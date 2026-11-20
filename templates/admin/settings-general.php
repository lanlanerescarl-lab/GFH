<?php

declare(strict_types=1);

/**
 * Admin Settings, Organization settings (GET and POST /{org}/admin/settings/general).
 * One form for every setting: core settings first, then the ones added by extensions. A refused save changes nothing
 * and shows each refused field's message under it.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 * @var list<array<string, mixed>> $settings
 * @var array<string, string> $posted
 * @var array<string, string> $errors
 * @var string $saveUrl
 * @var string $settingsUrl
 * @var string $csrf
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Organization settings');
$this->end();

$this->start('head');
?>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$shown = [];
foreach ($settings as $setting) {
    $shown[(string) $setting['key']] = true;
}
$otherErrors = array_diff_key($errors, $shown);
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Manage · Settings',
    'title' => 'Organization settings',
    'subtitle' => 'Numbers and switches that tune how this organization runs. A change is saved only if every field is valid.',
    'actionsHtml' => '<a class="button button--ghost" href="' . $this->e($settingsUrl) . '">Back to settings</a>',
]) ?>

<?php if ($otherErrors !== []): ?>
<div class="kit-stack">
    <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'Settings not saved', 'message' => implode(' ', $otherErrors)]) ?>
</div>
<?php endif; ?>

<form method="post" action="<?= $this->e($saveUrl) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php foreach (['core' => 'Core settings', 'extension' => 'Added by extensions'] as $group => $heading): ?>
<?php
    $rows = array_values(array_filter($settings, static fn (array $s): bool => ($group === 'core') === ($s['source'] === null)));
    if ($rows === []) {
        continue;
    }
?>
    <section class="kit-section" aria-labelledby="settings-<?= $this->e($group) ?>-heading">
        <section class="card">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="settings-<?= $this->e($group) ?>-heading"><?= $this->e($heading) ?></h2>
                </div>
            </div>
<?php foreach ($rows as $setting): ?>
<?php
        $key = (string) $setting['key'];
        $type = (string) $setting['type'];
        $id = 'setting-' . preg_replace('/[^a-z0-9_-]/', '-', $key);
        $value = $setting['value'];
        $default = $setting['default'];
        $defaultText = $type === 'bool' ? ($default ? 'On' : 'Off') : (string) $default;
        $parts = ['Default: ' . ($defaultText === '' ? '(empty)' : $defaultText)];
        if ($type === 'int' && $setting['min'] !== null && $setting['max'] !== null) {
            $parts[] = 'Allowed: ' . $setting['min'] . ' to ' . $setting['max'];
        }
        if ($setting['source'] !== null) {
            $parts[] = 'Added by the ' . $setting['source'] . ' extension';
        }
        if ($setting['help'] !== '') {
            array_unshift($parts, (string) $setting['help']);
        }
        $current = $type === 'bool' ? ((bool) $value ? '1' : '0') : (string) $value;
        $shownValue = $posted[$key] ?? $current;
?>
            <div class="settings-row" data-setting="<?= $this->e($key) ?>">
<?php if ($type === 'bool'): ?>
                <?= $this->partial('partials/field', [
                    'id' => $id,
                    'name' => 'settings[' . $key . ']',
                    'type' => 'switch',
                    'label' => (string) $setting['label'],
                    'checked' => $shownValue === '1',
                    'help' => implode(' · ', $parts),
                    'error' => $errors[$key] ?? null,
                ]) ?>
<?php else: ?>
                <?= $this->partial('partials/field', [
                    'id' => $id,
                    'name' => 'settings[' . $key . ']',
                    'type' => $type === 'int' ? 'number' : 'text',
                    'label' => (string) $setting['label'],
                    'value' => $shownValue,
                    'min' => $type === 'int' && $setting['min'] !== null ? (string) $setting['min'] : null,
                    'max' => $type === 'int' && $setting['max'] !== null ? (string) $setting['max'] : null,
                    'step' => $type === 'int' ? '1' : null,
                    'help' => implode(' · ', $parts),
                    'error' => $errors[$key] ?? null,
                ]) ?>
<?php endif; ?>
            </div>
<?php endforeach; ?>
        </section>
    </section>
<?php endforeach; ?>
    <div class="form-actions">
        <?= $this->partial('partials/button', ['label' => 'Save settings', 'variant' => 'primary', 'type' => 'submit']) ?>
    </div>
</form>
