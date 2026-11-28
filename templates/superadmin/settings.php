<?php
/**
 * System Settings (/superadmin/settings).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
$this->layout('layout');
$this->start('title');
echo $this->e('System Settings');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'System Settings',
    'subtitle' => 'Organization role-permission matrix, organization controls and platform reference.',
]) ?>
<?= $this->partial('partials/placeholder-notice') ?>
