<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Users and Access (/superadmin/users). Placeholder shell in SA.1a; populated in SA.2. */
final class UsersController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'users_access', 'view');

        return $this->render($request, $staff, 'users_access', 'users');
    }
}
