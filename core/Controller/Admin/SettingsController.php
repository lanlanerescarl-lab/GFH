<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\SettingsSections;

/**
 * Admin Settings hub (/{org}/admin/settings): one card per registered section. The sections live in
 * SettingsSections, so adding one never touches this class. Only an Admin may open it (reports_admin edit); each
 * section's own page asks for the same permission again.
 */
final class SettingsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        $sections = [];
        foreach (SettingsSections::ALL as $section) {
            $sections[] = $section + ['url' => $this->adminUrl($request, $tenant, $section['path'])];
        }

        return $this->render($request, $tenant, $staff, 'settings', ['sections' => $sections]);
    }
}
