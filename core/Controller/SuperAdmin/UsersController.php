<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/**
 * Users and Access (/superadmin/users, SA.2a).
 *
 * Group-wide account directory and management console. Group Viewers can browse the list and
 * account details read-only; Super Admins can create staff and group accounts, add memberships,
 * change roles, deactivate/reactivate memberships, and issue one-time password reset links for
 * accounts with at least one membership. All business rules live in AccountService and PasswordResetService.
 */
final class UsersController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'view');
        $listing = $this->services->superAdminUserQueries()->list($staff, $request->query());

        return $this->render($request, $staff, 'users_access', 'users', [
            'items' => $listing['items'],
            'pagination' => $listing['pagination'],
            'sort' => $listing['sort'],
            'filters' => $listing['filters'],
            'options' => $listing['options'],
            'hasActiveFilters' => $listing['has_active_filters'],
            'canEdit' => $this->can($staff, 'users_access', 'edit'),
        ]);
    }

    public function newStaffForm(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');

        return $this->renderNewStaff($request, $staff, [
            'organization_id' => '',
            'full_name' => '',
            'email' => '',
            'role' => 'staff',
        ]);
    }

    public function createStaff(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $input = [
            'organization_id' => $this->text($request, 'organization_id'),
            'full_name' => $this->text($request, 'full_name'),
            'email' => $this->text($request, 'email'),
            'role' => $this->text($request, 'role'),
        ];
        // Never keep the posted password in $input so it is never echoed back on validation error.
        $password = $this->text($request, 'password');

        try {
            $result = $this->services->superAdminUsers()->createStaffAccount(
                $staff,
                $input['organization_id'],
                $input['email'],
                $input['full_name'],
                $input['role'],
                $password === '' ? null : $password,
            );
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            return $this->renderNewStaff($request, $staff, $input, $this->errorsOf($exception, 'email'), $exception->status());
        }

        if ($result['created_user']) {
            $this->flash(
                $request,
                'ok',
                'Staff account created.',
                $password === ''
                    ? 'No password was set, so the account starts pending. Generate a one-time reset link so they can choose a password.'
                    : 'The account is active and assigned to the selected organization.',
            );
        } else {
            $this->flash(
                $request,
                'ok',
                $result['membership'] === 'reactivated' ? 'Membership reactivated.' : 'Membership added to existing account.',
                'That email already had an account, so its name, email and password were not changed.',
            );
        }

        return Response::redirect($request->basePath() . '/superadmin/users/' . $result['user_id']);
    }

    public function newGroupForm(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');

        return $this->renderNewGroup($request, $staff, [
            'full_name' => '',
            'email' => '',
            'org_role' => 'group_viewer',
        ]);
    }

    public function createGroup(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $input = [
            'full_name' => $this->text($request, 'full_name'),
            'email' => $this->text($request, 'email'),
            'org_role' => $this->text($request, 'org_role'),
        ];
        $password = $this->text($request, 'password');

        try {
            $result = $this->services->superAdminUsers()->createGroupAccount(
                $staff,
                $input['email'],
                $input['full_name'],
                $input['org_role'],
                $password === '' ? null : $password,
            );
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            return $this->renderNewGroup($request, $staff, $input, $this->errorsOf($exception, 'email'), $exception->status());
        }

        $this->flash(
            $request,
            'ok',
            'Group account created.',
            $password === ''
                ? 'No password was set, so the account starts pending.'
                : 'The group account is active and ready to sign in.',
        );

        return Response::redirect($request->basePath() . '/superadmin/users/' . $result['user_id']);
    }

    public function show(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'view');
        $id = (string) $request->param('id', '');

        return $this->renderDetail($request, $staff, $id);
    }

    public function addMembership(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $id = (string) $request->param('id', '');
        $input = [
            'organization_id' => $this->text($request, 'organization_id'),
            'role' => $this->text($request, 'role'),
        ];

        try {
            $result = $this->services->superAdminUsers()->addMembership(
                $staff,
                $id,
                $input['organization_id'],
                $input['role'],
            );
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'organization_id');
            if (isset($errors['email']) && !isset($errors['organization_id'])) {
                $errors['organization_id'] = $errors['email'];
                unset($errors['email']);
            }

            return $this->renderDetail($request, $staff, $id, [
                'addInput' => $input,
                'addErrors' => $errors,
            ], $exception->status());
        }

        $this->flash(
            $request,
            'ok',
            $result['membership'] === 'reactivated' ? 'Membership reactivated.' : 'Membership added.',
        );

        return Response::redirect($request->basePath() . '/superadmin/users/' . $id);
    }

    public function changeRole(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $id = (string) $request->param('id', '');
        $orgId = (string) $request->param('orgId', '');
        $role = $this->text($request, 'role');

        try {
            $this->services->superAdminUsers()->changeRole($staff, $id, $orgId, $role);
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'role');

            return $this->renderDetail($request, $staff, $id, [
                'membershipRoles' => [$orgId => $role],
                'membershipErrors' => [$orgId => (string) reset($errors)],
            ], $exception->status());
        }

        $this->flash($request, 'ok', 'Organization role updated.');

        return Response::redirect($request->basePath() . '/superadmin/users/' . $id);
    }

    public function deactivateMembership(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'delete');
        $id = (string) $request->param('id', '');
        $orgId = (string) $request->param('orgId', '');

        try {
            $this->services->superAdminUsers()->deactivateMembership($staff, $id, $orgId);
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'user');

            return $this->renderDetail($request, $staff, $id, [
                'membershipErrors' => [$orgId => (string) reset($errors)],
            ], $exception->status());
        }

        $this->flash(
            $request,
            'ok',
            'Membership deactivated.',
            'Access to this organization ended immediately. Nothing was deleted.',
        );

        return Response::redirect($request->basePath() . '/superadmin/users/' . $id);
    }

    public function reactivateMembership(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $id = (string) $request->param('id', '');
        $orgId = (string) $request->param('orgId', '');

        try {
            $this->services->superAdminUsers()->reactivateMembership($staff, $id, $orgId);
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'user');

            return $this->renderDetail($request, $staff, $id, [
                'membershipErrors' => [$orgId => (string) reset($errors)],
            ], $exception->status());
        }

        $this->flash($request, 'ok', 'Membership reactivated.');

        return Response::redirect($request->basePath() . '/superadmin/users/' . $id);
    }

    /**
     * Issues a one-time password reset link and displays it once on this POST response only.
     * Never stored in session/flash, never logged, never put in the audit log, sent with no-store headers.
     */
    public function issueResetLink(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'edit');
        $id = (string) $request->param('id', '');
        $orgId = $this->text($request, 'organization_id');

        try {
            $issued = $this->services->superAdminUsers()->issueResetLink($staff, $id, $orgId);
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            return $this->renderDetail($request, $staff, $id, [
                'resetInput' => ['organization_id' => $orgId],
                'resetErrors' => $this->errorsOf($exception, 'organization_id'),
            ], $exception->status());
        }

        return $this->render($request, $staff, 'users_access', 'user-reset-link', [
            'account' => $issued['user'],
            'organization' => $issued['organization'],
            'link' => $issued['link'],
            'detailUrl' => $request->basePath() . '/superadmin/users/' . $id,
        ])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNewStaff(Request $request, StaffUser $staff, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $staff, 'users_access', 'user-new-staff', [
            'input' => $input,
            'errors' => $errors,
            'organizations' => $this->services->superAdminUserQueries()->organizationOptions($staff),
        ], $status);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNewGroup(Request $request, StaffUser $staff, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $staff, 'users_access', 'user-new-group', [
            'input' => $input,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderDetail(Request $request, StaffUser $staff, string $id, array $extra = [], int $status = 200): Response
    {
        $detail = $this->services->superAdminUserQueries()->detail($staff, $id);

        return $this->render($request, $staff, 'users_access', 'user-detail', $extra + [
            'account' => $detail['user'],
            'organizations' => $detail['organizations'],
            'canEdit' => $this->can($staff, 'users_access', 'edit'),
            'canDelete' => $this->can($staff, 'users_access', 'delete'),
            'actorId' => $staff->id,
            'addInput' => ['organization_id' => '', 'role' => 'staff'],
            'addErrors' => [],
            'membershipRoles' => [],
            'membershipErrors' => [],
            'resetInput' => ['organization_id' => ''],
            'resetErrors' => [],
        ], $status);
    }
}
