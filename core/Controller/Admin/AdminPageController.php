<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Shared base for Staff/Admin HTML page controllers.
 * Every action checks PermissionService::can() via authorize(), renders through AppView with the Admin layout,
 * and carries the session CSRF token and flash notices.
 */
abstract class AdminPageController
{
    public function __construct(protected readonly Services $services)
    {
    }

    /**
     * @return array{staff: StaffUser, tenant: Tenant, services: TenantServices}
     * @throws HttpException 401 when not signed in, 403 when not permitted
     */
    protected function authorize(Request $request, string $module, string $action): array
    {
        $tenant = $this->tenant($request);
        $staff = $this->staff($request);
        $this->services->permissions()->assert($staff, $tenant->organization, $module, $action);

        return [
            'staff' => $staff,
            'tenant' => $tenant,
            'services' => $this->services->forTenant($tenant),
        ];
    }

    protected function can(StaffUser $staff, Tenant $tenant, string $module, string $action): bool
    {
        return $this->services->permissions()->can($staff, $tenant->organization, $module, $action);
    }

    /** @param array<string, mixed> $data */
    protected function render(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        string $template,
        array $data = [],
        int $status = 200,
    ): Response {
        $currentPath = $request->basePath() . $request->path();
        $navigation = $this->services->adminNavigation($tenant)
            ->items($staff, $tenant->organization, $request->basePath(), $currentPath);

        $session = $request->attribute('session');
        $csrf = '';
        $flash = null;
        if ($session instanceof Session) {
            $csrf = $this->services->csrf($session)->token();
            $storedFlash = $session->get('_admin_flash');
            if (is_array($storedFlash)) {
                $flash = $storedFlash;
                $session->forget('_admin_flash');
            }
        }

        $view = $this->services->appView($tenant, 'admin', $request, [
            'navigation' => $navigation,
            'staffName' => $staff->fullName,
            'staffRole' => (string) $tenant->role(),
            'logoutUrl' => $this->adminUrl($request, $tenant, '/logout'),
            'csrf' => $csrf,
            'flash' => $flash,
        ]);

        return Response::html($view->render($template, $data), $status);
    }

    protected function flash(Request $request, string $tone, string $title, string $message = ''): void
    {
        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->put('_admin_flash', [
                'tone' => $tone,
                'title' => $title,
                'message' => $message,
            ]);
        }
    }

    protected function adminUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $request->basePath() . '/' . $tenant->organization->slug . '/admin' . $path;
    }

    protected function safeReturnUrl(Request $request, Tenant $tenant, string $candidate, string $fallbackPath): string
    {
        $fallback = $this->adminUrl($request, $tenant, $fallbackPath);
        $prefix = $this->adminUrl($request, $tenant);
        $safe = preg_match('#^/[A-Za-z0-9/_\-.?=&%]*$#', $candidate) === 1
            && !str_contains($candidate, '//')
            && ($candidate === $prefix || str_starts_with($candidate, $prefix . '/') || str_starts_with($candidate, $prefix . '?'));

        return $safe ? $candidate : $fallback;
    }

    protected function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? $value : $default;
    }

    protected function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This page needs TenantMiddleware.');
        }

        return $tenant;
    }

    protected function staff(Request $request): StaffUser
    {
        $staff = $request->attribute('staff');
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        return $staff;
    }
}
