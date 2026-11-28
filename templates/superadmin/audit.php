<?php
/**
 * Audit Log (/superadmin/audit).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Audit Log');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Audit Log',
    'subtitle' => 'Append-only history across group governance and organization registry actions.',
]) ?>
<?= $this->partial('partials/placeholder-notice') ?>
