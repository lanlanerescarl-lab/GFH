<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Organizations list (/superadmin/organizations). Placeholder shell in SA.1a; populated in SA.1b and SA.3. */
final class OrganizationsController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'organizations', 'view');

        return $this->render($request, $staff, 'organizations', 'organizations');
    }
}
