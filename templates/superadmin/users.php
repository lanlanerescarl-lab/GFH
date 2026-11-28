<?php
/**
 * Users and Access (/superadmin/users).
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 */
$this->layout('layout');
$this->start('title');
echo $this->e('Users and Access');
$this->end();
?>
<?= $this->partial('partials/page-header', [
    'title' => 'Users and Access',
    'subtitle' => 'Group-level roles are held on the account; organization roles are held per membership.',
]) ?>
<?= $this->partial('partials/placeholder-notice') ?>
