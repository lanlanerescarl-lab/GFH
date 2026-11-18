<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AccountService;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Team (/{org}/admin/team): the organization's staff accounts. Only an Admin may open it, because every action
 * asks for reports_admin edit. The page reads input, asks can(), calls AccountService and PasswordResetService, and
 * returns a response; every rule (who may be created, changed or deactivated) lives in AccountService.
 */
final class TeamController extends AdminPageController
{
    /** @var list<string> the roles the create and edit forms offer; AccountService still decides what is allowed */
    private const FORM_ROLES = Modules::ORG_ADMIN_MANAGEABLE_ROLES;

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');

        return $this->renderIndex($request, $tenant, $staff);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');

        return $this->renderNew($request, $tenant, $staff, ['full_name' => '', 'email' => '', 'role' => 'staff']);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');
        $input = [
            'full_name' => $this->text($request, 'full_name'),
            'email' => $this->text($request, 'email'),
            'role' => $this->text($request, 'role'),
        ];

        try {
            $result = $this->services->accounts()->createStaffAccount($staff, $tenant->organization, $input['email'], $input['full_name'], $input['role']);
        } catch (HttpException $exception) {
            if ($exception->status() === 403) {
                throw $exception;
            }

            return $this->renderNew($request, $tenant, $staff, $input, $this->errorsOf($exception, 'email'), $exception->status());
        }

        $this->flash(
            $request,
            'ok',
            $result['created_user'] ? 'Team member added.' : 'Added to your team.',
            $result['created_user']
                ? 'They have no password yet. Generate a one-time reset link so they can choose one.'
                : 'This person already had an account, so their name, email and password were not changed.',
        );

        return Response::redirect($this->teamUrl($request, $tenant, '/' . $result['user_id']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');

        return $this->renderMember($request, $tenant, $staff, $this->id($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');
        $id = $this->id($request);
        $input = [
            'full_name' => $this->text($request, 'full_name'),
            'email' => $this->text($request, 'email'),
            'role' => $this->text($request, 'role'),
        ];

        try {
            $this->services->accounts()->updateTeamMember($staff, $tenant->organization, $id, $input['full_name'], $input['email'], $input['role']);
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() === 404) {
                throw $exception;
            }

            return $this->renderMember($request, $tenant, $staff, $id, $input, $this->errorsOf($exception, 'email'), $exception->status());
        }

        $this->flash($request, 'ok', 'Team member saved.');

        return Response::redirect($this->teamUrl($request, $tenant, '/' . $id));
    }

    public function deactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');
        $id = $this->id($request);

        try {
            $this->services->accounts()->deactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $id, $exception);
        }

        $this->flash($request, 'ok', 'Team member deactivated.', 'They can no longer sign in to this organization. Reactivate them any time.');

        return Response::redirect($this->teamUrl($request, $tenant, '/' . $id));
    }

    public function reactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');
        $id = $this->id($request);

        try {
            $this->services->accounts()->reactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $id, $exception);
        }

        $this->flash($request, 'ok', 'Team member reactivated.');

        return Response::redirect($this->teamUrl($request, $tenant, '/' . $id));
    }

    /**
     * Generates a one-time reset link and shows it on this response only. The link is not kept anywhere in plain text, the
     * audit entry holds no token, and the page is marked so no browser or proxy keeps a copy.
     */
    public function issueResetLink(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, 'reports_admin', 'edit');
        $id = $this->id($request);

        try {
            $member = $this->services->accounts()->teamMember($staff, $tenant->organization, $id);
            $link = $this->services->resets()->issueForStaff($staff, $tenant->organization, (string) $member['email']);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $id, $exception);
        }

        $page = $this->render($request, $tenant, $staff, 'team-reset-link', [
            'member' => $member,
            'link' => $link,
            'memberUrl' => $this->teamUrl($request, $tenant, '/' . $id),
            'teamUrl' => $this->teamUrl($request, $tenant),
        ]);

        return $page
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /** A rule refused the action: 403 and 404 stay errors, a 422 comes back as a message on the member's own page. */
    private function failedAction(Request $request, Tenant $tenant, StaffUser $staff, string $id, HttpException $exception): Response
    {
        if ($exception->status() === 403 || $exception->status() === 404) {
            throw $exception;
        }

        $errors = $this->errorsOf($exception, 'action');

        return $this->renderMember($request, $tenant, $staff, $id, [], ['action' => (string) reset($errors)], $exception->status());
    }

    private function renderIndex(Request $request, Tenant $tenant, StaffUser $staff): Response
    {
        $query = ListQuery::fromRequest($request, AccountService::TEAM_SORTS, 'name', ['role', 'status']);

        $filterErrors = [];
        $status = 200;
        try {
            $result = $this->services->accounts()->listTeam($staff, $tenant->organization, $query);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }
            $filterErrors = $exception->fields();
            $result = $query->result([], 0);
            $status = 422;
        }

        return $this->render($request, $tenant, $staff, 'team', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => [
                'role' => (string) ($query->filter('role') ?? ''),
                'status' => (string) ($query->filter('status') ?? ''),
            ],
            'roles' => Modules::ROLES,
            'statuses' => AccountService::TEAM_STATUSES,
            'teamUrl' => $this->teamUrl($request, $tenant),
            'newUrl' => $this->teamUrl($request, $tenant, '/new'),
        ], $status);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNew(Request $request, Tenant $tenant, StaffUser $staff, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $tenant, $staff, 'team-new', [
            'input' => $input,
            'errors' => $errors,
            'roles' => self::FORM_ROLES,
            'teamUrl' => $this->teamUrl($request, $tenant),
        ], $status);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderMember(Request $request, Tenant $tenant, StaffUser $staff, string $id, array $input = [], array $errors = [], int $status = 200): Response
    {
        $member = $this->services->accounts()->teamMember($staff, $tenant->organization, $id);
        $values = ['full_name' => (string) $member['full_name'], 'email' => (string) $member['email'], 'role' => (string) $member['role']];

        return $this->render($request, $tenant, $staff, 'team-member', [
            'member' => $member,
            'input' => $input + $values,
            'errors' => $errors,
            'roles' => self::FORM_ROLES,
            'memberUrl' => $this->teamUrl($request, $tenant, '/' . $id),
            'teamUrl' => $this->teamUrl($request, $tenant),
        ], $status);
    }

    private function teamUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $this->adminUrl($request, $tenant, '/team' . $path);
    }
}
