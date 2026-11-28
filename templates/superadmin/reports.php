<?php
/**
 * Consolidated Reports (/superadmin/reports).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Consolidated Reports');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Consolidated Reports',
    'subtitle' => 'Group reporting built only from documented organization reporting views.',
]) ?>
<?= $this->partial('partials/placeholder-notice') ?>
