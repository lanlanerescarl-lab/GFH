<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;

/**
 * Requires a signed-in staff member with an active membership in the organization of the URL.
 * Pages redirect to the sign-in page; API calls get 401. A signed-in person with no membership gets 403.
 */
final class RequireStaffMiddleware
{
    public function __construct(private readonly Services $services)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $tenant = $request->attribute('tenant');
        $staff = $request->attribute('staff');

        if (!$tenant instanceof Tenant) {
            throw new \LogicException('RequireStaffMiddleware needs TenantMiddleware before it.');
        }

        if (!$staff instanceof StaffUser) {
            if ($request->wantsJson()) {
                throw HttpException::unauthorized();
            }

            $nextUrl = $request->basePath() . $request->path();

            return Response::redirect($request->basePath() . '/' . $tenant->organization->slug . '/admin/login?next=' . rawurlencode($nextUrl));
        }

        $tenant = $this->services->tenants()->authorizeStaff($tenant, $staff);

        return $next($request->withAttribute('tenant', $tenant));
    }
}
