<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;

/**
 * Shared base for Super Admin HTML page controllers.
 * Every action checks PermissionService::can($staff, null, $module, $action),
 * builds the sidebar via SuperAdminNavigationService (which re-checks permission at the service level),
 * and renders through templates/superadmin/ with Cache-Control: no-store.
 */
abstract class SuperAdminPageController
{
    public function __construct(protected readonly Services $services)
    {
    }

    /** @throws HttpException 401 when not signed in, 403 when not permitted */
    protected function authorize(Request $request, string $module, string $action = 'view'): StaffUser
    {
        $staff = $this->staff($request);
        $this->services->permissions()->assert($staff, null, $module, $action);

        return $staff;
    }

    /** @param array<string, mixed> $data */
    protected function render(
        Request $request,
        StaffUser $staff,
        string $module,
        string $template,
        array $data = [],
        int $status = 200,
    ): Response {
        $currentPath = $request->basePath() . $request->path();
        $navigation = $this->services->superAdminNavigation()
            ->forPage($staff, $module, $request->basePath(), $currentPath);

        $session = $request->attribute('session');
        $csrf = $session instanceof Session ? $this->services->csrf($session)->token() : '';

        $view = $this->services->superAdminView($request, [
            'navigation' => $navigation,
            'staffName' => $staff->fullName,
            'staffRole' => $this->roleLabel($staff->orgRole),
            'isGroupViewer' => $staff->orgRole === 'group_viewer',
            'logoutUrl' => $request->basePath() . '/superadmin/sign-out',
            'csrf' => $csrf,
        ]);

        return Response::html($view->render($template, $data), $status, ['Cache-Control' => 'no-store']);
    }

    protected function staff(Request $request): StaffUser
    {
        $staff = $request->attribute('staff');
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        return $staff;
    }

    private function roleLabel(?string $orgRole): string
    {
        return match ($orgRole) {
            'super_admin' => 'Super Admin',
            'group_viewer' => 'Group Viewer',
            default => '',
        };
    }
}
