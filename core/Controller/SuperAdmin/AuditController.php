<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Audit Log (/superadmin/audit). Append-only core audit log with filters, sorting, pagination and Inspect drawer (SA.1b). */
final class AuditController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'audit_log', 'view');
        $audit = $this->services->superAdminAuditQueries()->list($staff, $request->query());

        return $this->render($request, $staff, 'audit_log', 'audit', [
            'items' => $audit['items'],
            'pagination' => $audit['pagination'],
            'sort' => $audit['sort'],
            'filters' => $audit['filters'],
            'options' => $audit['options'],
            'errors' => $audit['errors'],
            'inspected' => $audit['inspected'],
            'hasActiveFilters' => $audit['has_active_filters'],
        ]);
    }
}
