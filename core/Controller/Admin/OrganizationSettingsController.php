<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Settings, Organization settings (/{org}/admin/settings/general). Both actions ask for reports_admin edit, so
 * only an Admin opens it. The controller reads the posted fields, asks can(), calls OrganizationSettingsService and
 * returns a response; typing, validation and the audit entry live in the services.
 */
final class OrganizationSettingsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderPage($request, $tenant, $staff, $services);
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        $posted = [];
        $raw = $request->input('settings');
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($value)) {
                    $posted[(string) $key] = $value;
                }
            }
        }

        try {
            $services->organizationSettings()->save($staff, $tenant->organization, $posted);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderPage($request, $tenant, $staff, $services, $posted, $this->errorsOf($exception, 'settings'), 422);
        }

        $this->flash($request, 'ok', 'Settings saved.');

        return Response::redirect($this->adminUrl($request, $tenant, '/settings/general'));
    }

    /**
     * @param array<string, string> $posted values from a refused save, which win over the stored ones
     * @param array<string, string> $errors one message per refused setting key
     */
    private function renderPage(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, array $posted = [], array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $tenant, $staff, 'settings-general', [
            'settings' => $services->organizationSettings()->list($staff, $tenant->organization),
            'posted' => $posted,
            'errors' => $errors,
            'saveUrl' => $this->adminUrl($request, $tenant, '/settings/general'),
            'settingsUrl' => $this->adminUrl($request, $tenant, '/settings'),
        ], $status);
    }
}
