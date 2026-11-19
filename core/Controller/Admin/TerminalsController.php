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
 * Admin Settings, Terminals (/{org}/admin/settings/terminals). Every action asks for reports_admin edit, so only an
 * Admin opens it. The controller reads the form, asks can(), calls TerminalService and returns a response; the rules
 * (fixed code and branch, open shifts, inactive branches) live in TerminalService.
 *
 * An edit form posts only the name. A code or branch that arrives anyway is handed to the service, which refuses the
 * save unless it equals the stored value.
 */
final class TerminalsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->render($request, $tenant, $staff, 'settings-terminals', [
            'terminals' => $services->terminals()->list($staff, $tenant->organization),
            'terminalsUrl' => $this->terminalsUrl($request, $tenant),
            'newUrl' => $this->terminalsUrl($request, $tenant, '/new'),
            'settingsUrl' => $this->adminUrl($request, $tenant, '/settings'),
        ]);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderNew($request, $tenant, $staff, $services, ['branch_id' => '', 'code' => '', 'name' => '']);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $input = [
            'branch_id' => $this->text($request, 'branch_id'),
            'code' => $this->text($request, 'code'),
            'name' => $this->text($request, 'name'),
        ];

        try {
            $result = $services->terminals()->create($staff, $tenant->organization, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderNew($request, $tenant, $staff, $services, $input, $this->errorsOf($exception, 'name'), 422);
        }

        $this->flash($request, 'ok', 'Terminal added.');

        return Response::redirect($this->terminalsUrl($request, $tenant, '/' . $result['id']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderTerminal($request, $tenant, $staff, $services, $this->id($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);
        $input = ['name' => $this->text($request, 'name')];
        foreach (['code', 'branch_id'] as $tamperable) {
            if ($request->input($tamperable) !== null) {
                $input[$tamperable] = $this->text($request, $tamperable);
            }
        }

        try {
            $services->terminals()->update($staff, $tenant->organization, $id, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderTerminal($request, $tenant, $staff, $services, $id, ['name' => $input['name']], $this->errorsOf($exception, 'name'), 422);
        }

        $this->flash($request, 'ok', 'Terminal saved.');

        return Response::redirect($this->terminalsUrl($request, $tenant, '/' . $id));
    }

    public function deactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->terminals()->deactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Terminal deactivated.', 'Nothing was deleted, and you can reactivate it any time.');

        return Response::redirect($this->terminalsUrl($request, $tenant, '/' . $id));
    }

    public function reactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->terminals()->reactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Terminal reactivated.');

        return Response::redirect($this->terminalsUrl($request, $tenant, '/' . $id));
    }

    /** A rule refused the action: 403 and 404 stay errors, a 422 comes back as a message on the terminal's own page. */
    private function failedAction(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, HttpException $exception): Response
    {
        if ($exception->status() !== 422) {
            throw $exception;
        }

        $errors = $this->errorsOf($exception, 'action');

        return $this->renderTerminal($request, $tenant, $staff, $services, $id, [], ['action' => (string) reset($errors)], 422);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNew(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, array $input, array $errors = [], int $status = 200): Response
    {
        $options = [];
        foreach ($services->terminals()->branchOptions($staff, $tenant->organization) as $branch) {
            $options[(string) $branch['id']] = (string) $branch['name'] . ' (' . (string) $branch['code'] . ')';
        }

        return $this->render($request, $tenant, $staff, 'settings-terminal-new', [
            'input' => $input,
            'errors' => $errors,
            'branchOptions' => $options,
            'terminalsUrl' => $this->terminalsUrl($request, $tenant),
            'branchesUrl' => $this->adminUrl($request, $tenant, '/settings/branches'),
        ], $status);
    }

    /**
     * @param array<string, string> $input values from a refused save, which win over the stored ones
     * @param array<string, string> $errors
     */
    private function renderTerminal(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, array $input = [], array $errors = [], int $status = 200): Response
    {
        $terminal = $services->terminals()->get($staff, $tenant->organization, $id);

        return $this->render($request, $tenant, $staff, 'settings-terminal', [
            'terminal' => $terminal,
            'input' => $input + ['name' => (string) $terminal['name']],
            'errors' => $errors,
            'terminalUrl' => $this->terminalsUrl($request, $tenant, '/' . $id),
            'terminalsUrl' => $this->terminalsUrl($request, $tenant),
            'branchUrl' => $this->adminUrl($request, $tenant, '/settings/branches/' . (string) $terminal['branch_id']),
        ], $status);
    }

    private function terminalsUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $this->adminUrl($request, $tenant, '/settings/terminals' . $path);
    }
}
