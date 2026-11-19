<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\BranchService;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Settings, Branches (/{org}/admin/settings/branches). Every action asks for reports_admin edit, so only an
 * Admin opens it. The controller reads the form, asks can(), calls BranchService and returns a response; every rule
 * (codes, the single delivery branch, what blocks a deactivation) lives in BranchService.
 *
 * Status and the code are never read from an edit form: status changes only through deactivate and reactivate, and a
 * code that arrives in an edit POST is handed to the service, which refuses it unless it is unchanged.
 */
final class BranchesController extends AdminPageController
{
    private const FIELDS = ['name', 'address', 'city', 'province', 'region', 'kind', 'is_pickup_point', 'is_delivery_origin'];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->render($request, $tenant, $staff, 'settings-branches', [
            'branches' => $services->branches()->list($staff, $tenant->organization),
            'branchesUrl' => $this->branchesUrl($request, $tenant),
            'newUrl' => $this->branchesUrl($request, $tenant, '/new'),
            'settingsUrl' => $this->adminUrl($request, $tenant, '/settings'),
        ]);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderNew($request, $tenant, $staff, ['code' => '', 'name' => '', 'address' => '', 'city' => '', 'province' => '', 'region' => '', 'kind' => 'store', 'is_pickup_point' => '', 'is_delivery_origin' => '']);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $input = ['code' => $this->text($request, 'code')] + $this->formInput($request);

        try {
            $result = $services->branches()->create($staff, $tenant->organization, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderNew($request, $tenant, $staff, $input, $this->errorsOf($exception, 'name'), 422);
        }

        $this->flash($request, 'ok', 'Branch added.');

        return Response::redirect($this->branchesUrl($request, $tenant, '/' . $result['id']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderBranch($request, $tenant, $staff, $services, $this->id($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);
        $input = $this->formInput($request);
        if ($request->input('code') !== null) {
            $input['code'] = $this->text($request, 'code'); // only a tampered form posts one: the service refuses it unless unchanged
        }

        try {
            $services->branches()->update($staff, $tenant->organization, $id, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderBranch($request, $tenant, $staff, $services, $id, $input, $this->errorsOf($exception, 'name'), 422);
        }

        $this->flash($request, 'ok', 'Branch saved.');

        return Response::redirect($this->branchesUrl($request, $tenant, '/' . $id));
    }

    public function deactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->branches()->deactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Branch deactivated.', 'It no longer appears where branches are chosen. Nothing was deleted, and you can reactivate it any time.');

        return Response::redirect($this->branchesUrl($request, $tenant, '/' . $id));
    }

    public function reactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->branches()->reactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Branch reactivated.');

        return Response::redirect($this->branchesUrl($request, $tenant, '/' . $id));
    }

    /** A rule refused the action: 403 and 404 stay errors, a 422 comes back as a message on the branch's own page. */
    private function failedAction(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, HttpException $exception): Response
    {
        if ($exception->status() !== 422) {
            throw $exception;
        }

        $errors = $this->errorsOf($exception, 'action');

        return $this->renderBranch($request, $tenant, $staff, $services, $id, [], ['action' => (string) reset($errors)], 422);
    }

    /** @return array<string, string> the editable fields exactly as posted (text only), with absent boxes as an empty string */
    private function formInput(Request $request): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $this->text($request, $field);
        }

        return $input;
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNew(Request $request, Tenant $tenant, StaffUser $staff, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $tenant, $staff, 'settings-branch-new', [
            'input' => $input,
            'errors' => $errors,
            'kinds' => BranchService::KINDS,
            'branchesUrl' => $this->branchesUrl($request, $tenant),
        ], $status);
    }

    /**
     * @param array<string, string> $input values from a refused save, which win over the stored ones
     * @param array<string, string> $errors
     */
    private function renderBranch(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, array $input = [], array $errors = [], int $status = 200): Response
    {
        $branch = $services->branches()->get($staff, $tenant->organization, $id);
        $stored = [];
        foreach (['name', 'address', 'city', 'province', 'region', 'kind'] as $field) {
            $stored[$field] = (string) ($branch[$field] ?? '');
        }
        $stored['is_pickup_point'] = (bool) $branch['is_pickup_point'] ? '1' : '';
        $stored['is_delivery_origin'] = (bool) $branch['is_delivery_origin'] ? '1' : '';

        return $this->render($request, $tenant, $staff, 'settings-branch', [
            'branch' => $branch,
            'input' => $input + $stored,
            'errors' => $errors,
            'kinds' => BranchService::KINDS,
            'branchUrl' => $this->branchesUrl($request, $tenant, '/' . $id),
            'branchesUrl' => $this->branchesUrl($request, $tenant),
        ], $status);
    }

    private function branchesUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $this->adminUrl($request, $tenant, '/settings/branches' . $path);
    }
}
