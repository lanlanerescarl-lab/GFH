<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;

/**
 * Authenticates a group-level user for the Super Admin console using the shared staff auth rules.
 * Accounts without a group role (organization staff only) receive the exact same invalid-credentials
 * error as an unknown email or wrong password, so group membership is never disclosed.
 */
final class SuperAdminAuthService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
    ) {
    }

    /** @throws HttpException 401 invalid_credentials, 429 too_many_requests */
    public function authenticate(string $email, string $password, string $ip): StaffUser
    {
        $user = $this->auth->authenticateStaff($email, $password, $ip);

        if (!in_array($user->orgRole, Modules::GROUP_ROLES, true) || !$this->permissions->can($user, null, 'overview', 'view')) {
            throw new HttpException(401, 'invalid_credentials', AuthService::INVALID_MESSAGE);
        }

        return $user;
    }
}
