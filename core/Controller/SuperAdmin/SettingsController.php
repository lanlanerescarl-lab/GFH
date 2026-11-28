<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** System Settings (/superadmin/settings). Placeholder shell in SA.1a; populated in SA.2 and SA.4. */
final class SettingsController extends SuperAdminPageController
{
    public function index(Request $request): Response
    {
        $staff = $this->authorize($request, 'system_settings', 'view');

        return $this->render($request, $staff, 'system_settings', 'settings');
    }
}
