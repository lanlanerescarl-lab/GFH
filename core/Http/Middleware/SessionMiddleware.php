<?php

declare(strict_types=1);

namespace Gfh\Core\Http\Middleware;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;

/**
 * Opens the staff or customer session and, if someone is signed in and still allowed, stores them on the
 * request as "staff" or "customer". An account that has been deactivated since sign-in is signed out here.
 */
final class SessionMiddleware
{
    /**
     * @param bool $onlyIfCookie open the session only when the browser already has one. Used for public reads
     *                           (catalog browsing), so an anonymous visitor is not given a cookie just for looking.
     */
    public function __construct(
        private readonly Services $services,
        private readonly string $scope,
        private readonly bool $onlyIfCookie = false,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->onlyIfCookie && $request->cookie('gfh_' . $this->scope) === null) {
            return $next($request);
        }

        $session = $this->services->session($this->scope, $request);
        $session->start();
        $request = $request->withAttribute('session', $session);

        $userId = $session->get('user_id');
        if (is_string($userId)) {
            if ($this->scope === 'staff') {
                $row = $this->services->users()->findById($userId);
                $user = ($row !== null && $row['status'] === 'active') ? StaffUser::fromRow($row) : null;
            } else {
                $row = $this->services->customers()->findById($userId);
                $user = ($row !== null && $row['status'] === 'active') ? CustomerAccount::fromRow($row) : null;
            }

            if ($user === null) {
                $session->forget('user_id');
            } else {
                $request = $request->withAttribute($this->scope, $user);
            }
        }

        return $next($request);
    }
}
