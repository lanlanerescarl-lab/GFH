<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Group Overview (/superadmin). Placeholder shell in SA.1a; populated in SA.1b and SA.3. */
final class OverviewController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'overview', 'view');

        return $this->render($request, $staff, 'overview', 'overview');
    }
}
