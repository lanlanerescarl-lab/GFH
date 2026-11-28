<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\SuperAdminRedirect;

/**
 * Requires a signed-in user with a group-level role (super_admin or group_viewer).
 * Guests are sent to /superadmin/sign-in with a safe next path (or 401 for JSON);
 * signed-in organization staff without a group role receive 403.
 */
final class RequireGroupMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $staff = $request->attribute('staff');

        if (!$staff instanceof StaffUser) {
            if ($request->wantsJson()) {
                throw HttpException::unauthorized();
            }

            $base = $request->basePath();
            $signIn = $base . '/superadmin/sign-in';
            $candidate = $base . $request->path();
            $safeNext = SuperAdminRedirect::safeNext($candidate, $base . '/superadmin');
            $location = $safeNext !== null ? $signIn . '?next=' . rawurlencode($safeNext) : $signIn;

            return Response::redirect($location);
        }

        if (!$staff->isActive() || !in_array($staff->orgRole, Modules::GROUP_ROLES, true)) {
            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
