<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Dashboard figures for the Admin app, read from the org_contract views. */
final class ReportController extends ApiController
{
    /** GET .../admin/reports/dashboard */
    public function dashboard(Request $request): Response
    {
        $services = $this->authorize($request, 'dashboard', 'view');

        return Response::ok($services->reports()->dashboard());
    }
}
