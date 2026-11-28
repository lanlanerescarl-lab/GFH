<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Organizations list (/superadmin/organizations) and detail (/superadmin/organizations/{slug}). Read-only (SA.1b). */
final class OrganizationsController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'organizations', 'view');
        $query = $request->query();
        $search = is_string($query['q'] ?? null) ? $query['q'] : null;
        $status = is_string($query['status'] ?? null) ? $query['status'] : null;

        $listing = $this->services->superAdminOrganizationQueries()->list($staff, $search, $status);

        return $this->render($request, $staff, 'organizations', 'organizations', [
            'items' => $listing['items'],
            'counts' => $listing['counts'],
            'hasAny' => $listing['has_any'],
            'filters' => $listing['filters'],
        ]);
    }

    public function show(Request $request): Response
    {
        $staff = $this->authorize($request, 'organizations', 'view');
        $slug = (string) $request->param('slug', '');
        $organization = $this->services->superAdminOrganizationQueries()->detail($staff, $slug);

        return $this->render($request, $staff, 'organizations', 'organization-detail', [
            'organization' => $organization,
        ]);
    }
}
