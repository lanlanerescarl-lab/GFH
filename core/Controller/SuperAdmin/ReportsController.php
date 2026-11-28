<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Consolidated Reports (/superadmin/reports). Placeholder shell in SA.1a; populated in SA.3. */
final class ReportsController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'consolidated_reports', 'view');

        return $this->render($request, $staff, 'consolidated_reports', 'reports');
    }
}
