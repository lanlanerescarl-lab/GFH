<?php
/**
 * Accessible Tabs partial (role="tablist", arrow-key navigation via ui.js).
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $id
 * @var string $label accessible label for the tablist
 * @var list<array{id: string, label: string, contentHtml: string, active?: bool}> $tabs
 */
$label ??= 'Sections';
?>
<div class="tabs" data-tabs id="<?= $this->e($id) ?>">
    <div class="tabs__list" role="tablist" aria-label="<?= $this->e($label) ?>">
<?php foreach ($tabs as $index => $tab): ?>
<?php $isActive = !empty($tab['active']) || ($index === 0 && !array_filter($tabs, static fn (array $t): bool => !empty($t['active']))); ?>
        <button
            class="tabs__tab"
            type="button"
            role="tab"
            id="tab-<?= $this->e($tab['id']) ?>"
            aria-controls="panel-<?= $this->e($tab['id']) ?>"
            aria-selected="<?= $isActive ? 'true' : 'false' ?>"
            tabindex="<?= $isActive ? '0' : '-1' ?>"
        ><?= $this->e($tab['label']) ?></button>
<?php endforeach; ?>
    </div>
<?php foreach ($tabs as $index => $tab): ?>
<?php $isActive = !empty($tab['active']) || ($index === 0 && !array_filter($tabs, static fn (array $t): bool => !empty($t['active']))); ?>
    <div
        class="tabs__panel"
        role="tabpanel"
        id="panel-<?= $this->e($tab['id']) ?>"
        aria-labelledby="tab-<?= $this->e($tab['id']) ?>"
        tabindex="0"<?= $isActive ? '' : ' hidden' ?>
    >
        <?= $tab['contentHtml'] ?>
    </div>
<?php endforeach; ?>
</div>
