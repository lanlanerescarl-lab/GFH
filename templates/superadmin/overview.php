<?php
/**
 * Group Overview (/superadmin).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Group Overview');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Group Overview',
    'subtitle' => 'Consolidated performance and operational health across GFH organizations.',
]) ?>
<?= $this->partial('partials/placeholder-notice') ?>
