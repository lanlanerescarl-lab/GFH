<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Audit Log (/superadmin/audit). Placeholder shell in SA.1a; populated in SA.1b. */
final class AuditController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'audit_log', 'view');

        return $this->render($request, $staff, 'audit_log', 'audit');
    }
}
