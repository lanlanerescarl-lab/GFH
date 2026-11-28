<?php
/**
 * Account detail and access management (/superadmin/users/{id}) — SA.2a.
 * Shows account status and each membership status separately. Group Viewer sees read-only details
 * with no write forms; Super Admin sees membership, role, status, and reset-link forms.
 *
 * @var \Gfh\Core\View\SuperAdminView $this
 * @var string $basePath
 * @var string $csrf
 * @var array<string, mixed> $account
 * @var list<array{id: string, slug: string, name: string, status: string}> $organizations
 * @var bool $canEdit
 * @var bool $canDelete
 * @var string|null $actorId
 * @var array{organization_id: string, role: string} $addInput
 * @var array<string, string> $addErrors
 * @var array<string, string> $membershipRoles
 * @var array<string, string> $membershipErrors
 * @var array{organization_id: string} $resetInput
 * @var array<string, string> $resetErrors
 */
$usersUrl = $basePath . '/superadmin/users';
$accountId = (string) $account['id'];
$detailUrl = $usersUrl . '/' . rawurlencode($accountId);
$memberships = is_array($account['memberships'] ?? null) ? $account['memberships'] : [];
$canEdit ??= false;
$canDelete ??= false;
$actorId ??= null;
$isSelf = $actorId !== null && $actorId === $accountId;
$addInput ??= ['organization_id' => '', 'role' => 'staff'];
$addErrors ??= [];
$membershipRoles ??= [];
$membershipErrors ??= [];
$resetInput ??= ['organization_id' => ''];
$resetErrors ??= [];

$roles = [
    'admin' => 'Admin',
    'manager' => 'Manager',
    'staff' => 'Staff',
    'cashier' => 'Cashier',
];

$this->layout('layout');
$this->start('title');
echo $this->e((string) $account['full_name']);
$this->end();
?>
<p class="sa-breadcrumb">
    <a class="sa-back-link" href="<?= $this->e($usersUrl) ?>">← Back to users and access</a>
</p>

<?= $this->partial('partials/page-header', [
    'title' => (string) $account['full_name'],
    'subtitle' => (string) $account['email'],
]) ?>

<section class="sa-section" aria-labelledby="sa-account-facts-heading">
    <div class="card sa-detail-card">
        <div class="card__header">
            <div>
                <p class="sa-kicker">Account record</p>
                <h2 class="card__title" id="sa-account-facts-heading">Account details</h2>
            </div>
            <?= $this->partial('partials/badge', ['label' => (string) $account['status_label'], 'tone' => (string) $account['status_tone']]) ?>
        </div>

        <dl class="sa-dl sa-dl--grid">
            <div class="sa-dl__row">
                <dt>Full name</dt>
                <dd><?= $this->e((string) $account['full_name']) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Email</dt>
                <dd><?= $this->e((string) $account['email']) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Group role</dt>
                <dd>
<?php if ($account['org_role'] !== null): ?>
                    <span class="sa-role-badge<?= $account['org_role'] === 'group_viewer' ? ' sa-role-badge--viewer' : '' ?>"><?= $this->e((string) $account['group_role_label']) ?></span>
<?php else: ?>
                    <span class="sa-muted">None (organization staff only)</span>
<?php endif; ?>
                </dd>
            </div>
            <div class="sa-dl__row">
                <dt>Account status</dt>
                <dd><?= $this->partial('partials/badge', ['label' => (string) $account['status_label'], 'tone' => (string) $account['status_tone']]) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Last sign-in</dt>
                <dd><?= $this->e((string) $account['last_login_at_manila']) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Created</dt>
                <dd><?= $this->e((string) $account['created_at_manila']) ?></dd>
            </div>
            <div class="sa-dl__row">
                <dt>Updated</dt>
                <dd><?= $this->e((string) $account['updated_at_manila']) ?></dd>
            </div>
        </dl>
    </div>
</section>

<section class="sa-section" aria-labelledby="sa-memberships-heading">
    <div class="card sa-detail-card">
        <div class="card__header">
            <div>
                <p class="sa-kicker">Organization access</p>
                <h2 class="card__title" id="sa-memberships-heading">Organization memberships</h2>
                <p class="card__subtitle">Account status and each membership status are tracked separately. The last active Admin of an organization cannot be deactivated or moved to another role.</p>
            </div>
        </div>

<?php if ($isSelf && $canEdit): ?>
        <p class="sa-muted">This is your own account. You cannot change your own role or deactivate your own membership.</p>
<?php endif; ?>

<?php if ($memberships === []): ?>
        <p class="sa-muted">This account has no organization memberships.</p>
<?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Organization</th>
                        <th scope="col">Role</th>
                        <th scope="col">Membership status</th>
                        <th scope="col">Joined</th>
<?php if ($canEdit || $canDelete): ?>
                        <th scope="col">Actions</th>
<?php endif; ?>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($memberships as $m): ?>
<?php
    $orgId = (string) $m['organization_id'];
    $currentRole = $membershipRoles[$orgId] ?? (string) $m['role'];
    $rowError = $membershipErrors[$orgId] ?? null;
    $memberOrgUrl = $detailUrl . '/memberships/' . rawurlencode($orgId);
    $roleSelectId = 'sa-member-role-' . $orgId;
    $rowErrorId = 'sa-member-err-' . $orgId;
?>
                    <tr>
                        <td>
                            <span class="sa-table-link"><?= $this->e((string) $m['organization_name']) ?></span>
                            <span class="sa-cell-sub"><code class="sa-mono"><?= $this->e((string) $m['organization_slug']) ?></code></span>
                        </td>
                        <td><?= $this->e((string) $m['role_label']) ?></td>
                        <td><?= $this->partial('partials/badge', ['label' => (string) $m['status_label'], 'tone' => (string) $m['status_tone']]) ?></td>
                        <td class="sa-cell-nowrap"><?= $this->e((string) $m['joined_at_manila']) ?></td>
<?php if ($canEdit || $canDelete): ?>
                        <td>
                            <div class="sa-member-actions">
<?php if ($canEdit): ?>
                                <form class="sa-inline-form" method="post" action="<?= $this->e($memberOrgUrl . '/role') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                                    <label class="sr-only" for="<?= $this->e($roleSelectId) ?>">Role for <?= $this->e((string) $m['organization_name']) ?></label>
                                    <select
                                        id="<?= $this->e($roleSelectId) ?>"
                                        name="role"
                                        class="sa-inline-select"
                                        <?= $rowError !== null ? 'aria-invalid="true" aria-describedby="' . $this->e($rowErrorId) . '"' : '' ?>
                                    >
<?php foreach ($roles as $roleKey => $roleLabel): ?>
                                        <option value="<?= $this->e($roleKey) ?>"<?= $currentRole === $roleKey ? ' selected' : '' ?>><?= $this->e($roleLabel) ?></option>
<?php endforeach; ?>
                                    </select>
                                    <button class="button button--secondary button--sm sa-action-link" type="submit">Save role</button>
                                </form>
<?php endif; ?>
<?php if ((string) $m['status'] === 'inactive'): ?>
<?php if ($canEdit): ?>
                                <form class="sa-inline-form" method="post" action="<?= $this->e($memberOrgUrl . '/reactivate') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                                    <button class="button button--sm sa-action-link" type="submit">Reactivate</button>
                                </form>
<?php endif; ?>
<?php else: ?>
<?php if ($canDelete): ?>
                                <form class="sa-inline-form" method="post" action="<?= $this->e($memberOrgUrl . '/deactivate') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                                    <button class="button button--danger button--sm sa-action-link" type="submit">Deactivate</button>
                                </form>
<?php endif; ?>
<?php endif; ?>
                            </div>
<?php if ($rowError !== null): ?>
                            <p class="field-error sa-inline-error" id="<?= $this->e($rowErrorId) ?>" role="alert"><?= $this->e($rowError) ?></p>
<?php endif; ?>
                        </td>
<?php endif; ?>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>

<?php if ($canEdit): ?>
        <div class="sa-detail-subsection">
            <h3 class="sa-section__subhead" id="sa-add-membership-heading">Add organization membership</h3>
<?php if ($organizations === []): ?>
            <p class="sa-muted">No organizations are registered yet.</p>
<?php else: ?>
            <form class="sa-form-row" method="post" action="<?= $this->e($detailUrl . '/memberships') ?>" aria-labelledby="sa-add-membership-heading" novalidate>
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
                <div class="field sa-filter-field">
                    <label class="field__label" for="sa-add-org">Organization</label>
                    <select
                        id="sa-add-org"
                        name="organization_id"
                        required
                        <?= isset($addErrors['organization_id']) ? 'aria-invalid="true" aria-describedby="sa-add-org-error"' : '' ?>
                    >
                        <option value="">Choose an organization</option>
<?php foreach ($organizations as $orgOpt): ?>
                        <option value="<?= $this->e($orgOpt['id']) ?>"<?= ($addInput['organization_id'] ?? '') === $orgOpt['id'] ? ' selected' : '' ?>><?= $this->e($orgOpt['name']) ?> (<?= $this->e($orgOpt['slug']) ?>)</option>
<?php endforeach; ?>
                    </select>
<?php if (isset($addErrors['organization_id'])): ?>
                    <p class="field-error" id="sa-add-org-error" role="alert"><?= $this->e($addErrors['organization_id']) ?></p>
<?php endif; ?>
                </div>

                <div class="field sa-filter-field">
                    <label class="field__label" for="sa-add-role">Role</label>
                    <select
                        id="sa-add-role"
                        name="role"
                        required
                        <?= isset($addErrors['role']) ? 'aria-invalid="true" aria-describedby="sa-add-role-error"' : '' ?>
                    >
<?php foreach ($roles as $roleKey => $roleLabel): ?>
                        <option value="<?= $this->e($roleKey) ?>"<?= ($addInput['role'] ?? 'staff') === $roleKey ? ' selected' : '' ?>><?= $this->e($roleLabel) ?></option>
<?php endforeach; ?>
                    </select>
<?php if (isset($addErrors['role'])): ?>
                    <p class="field-error" id="sa-add-role-error" role="alert"><?= $this->e($addErrors['role']) ?></p>
<?php endif; ?>
                </div>

                <div class="sa-filter-actions">
                    <button class="button sa-filter-btn" type="submit">Add membership</button>
                </div>
            </form>
<?php endif; ?>
        </div>
<?php endif; ?>
    </div>
</section>

<?php if ($canEdit): ?>
<section class="sa-section" aria-labelledby="sa-reset-heading">
    <div class="card sa-detail-card">
        <div class="card__header">
            <div>
                <p class="sa-kicker">Password access</p>
                <h2 class="card__title" id="sa-reset-heading">One-time password reset link</h2>
                <p class="card__subtitle">GFH sends no email. A one-time reset link is shown once so you can pass it on yourself, and generating a new link cancels any unused link.</p>
            </div>
        </div>
<?php if ($memberships === []): ?>
        <p class="sa-muted">One-time reset links are available only for accounts with at least one organization membership.</p>
<?php else: ?>
        <form class="sa-form-row" method="post" action="<?= $this->e($detailUrl . '/reset-link') ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf) ?>">
<?php if (count($memberships) === 1): ?>
            <input type="hidden" name="organization_id" value="<?= $this->e((string) $memberships[0]['organization_id']) ?>">
            <p class="sa-muted sa-reset-org-note">Reset link will use <strong><?= $this->e((string) $memberships[0]['organization_name']) ?></strong> (<code class="sa-mono"><?= $this->e((string) $memberships[0]['organization_slug']) ?></code>).</p>
<?php else: ?>
            <div class="field sa-filter-field">
                <label class="field__label" for="sa-reset-org">Organization for reset link</label>
                <select
                    id="sa-reset-org"
                    name="organization_id"
                    required
                    <?= isset($resetErrors['organization_id']) ? 'aria-invalid="true" aria-describedby="sa-reset-org-error"' : '' ?>
                >
<?php
    $selectedResetOrg = $resetInput['organization_id'] !== ''
        ? $resetInput['organization_id']
        : (string) $memberships[0]['organization_id'];
    foreach ($memberships as $m):
?>
                    <option value="<?= $this->e((string) $m['organization_id']) ?>"<?= $selectedResetOrg === (string) $m['organization_id'] ? ' selected' : '' ?>><?= $this->e((string) $m['organization_name']) ?> (<?= $this->e((string) $m['role_label']) ?>)</option>
<?php endforeach; ?>
                </select>
<?php if (isset($resetErrors['organization_id'])): ?>
                <p class="field-error" id="sa-reset-org-error" role="alert"><?= $this->e($resetErrors['organization_id']) ?></p>
<?php endif; ?>
            </div>
<?php endif; ?>
<?php if (count($memberships) === 1 && isset($resetErrors['organization_id'])): ?>
            <p class="field-error" role="alert"><?= $this->e($resetErrors['organization_id']) ?></p>
<?php endif; ?>
            <div class="sa-filter-actions">
                <button class="button button--secondary sa-filter-btn" type="submit">Generate password reset link</button>
            </div>
        </form>
<?php endif; ?>
    </div>
</section>
<?php endif; ?>
