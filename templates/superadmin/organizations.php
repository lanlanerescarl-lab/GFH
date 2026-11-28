<?php
/**
 * Organizations (/superadmin/organizations).
 *
 * Extension point (registration module):
 *   Named slot: page-header `actionsHtml` (renders inside `.page-header__actions` in `partials/page-header.php`).
 *   The separate organization registration module will later place its primary "New organization" action here,
 *   rendered only for users holding `organizations:create`. SA.1 renders nothing in this slot.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
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
<?= $this->partial('partials/placeholder-notice') ?>
