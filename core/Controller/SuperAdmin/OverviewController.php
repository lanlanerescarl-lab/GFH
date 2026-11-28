<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Group Overview (/superadmin). Core-database registry summary and recent audit activity (SA.1b). */
final class OverviewController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'overview', 'view');
        $overview = $this->services->superAdminOverview()->overview($staff);

        return $this->render($request, $staff, 'overview', 'overview', [
            'counts' => $overview['counts'],
            'organizations' => $overview['organizations'],
            'recentActivity' => $overview['recent_activity'],
        ]);
    }
}
