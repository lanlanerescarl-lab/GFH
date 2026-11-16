<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Suppliers: list, create, edit and activate or deactivate. Procurement permissions: view to read, edit to change.
 * Every rule (names, email, phone, lead time) lives in ProcurementService.
 */
final class SuppliersController extends AdminPageController
{
    private const FIELDS = ['name', 'contact_name', 'contact_email', 'contact_phone', 'address', 'payment_terms', 'lead_time_days'];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'view');

        return $this->renderIndex($request, $tenant, $staff, $services);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $input = $this->input($request);

        try {
            $services->procurement()->createSupplier($input, $staff->id);
        } catch (HttpException $exception) {
            return $this->renderIndex($request, $tenant, $staff, $services, $input, $this->errorsOf($exception, 'name'), $exception->status());
        }

        $this->flash($request, 'ok', 'Supplier added.', $input['name'] . ' can now be used on purchase orders.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/suppliers'));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'view');

        return $this->renderShow($request, $tenant, $staff, $services, $this->id($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $input = $this->input($request);

        try {
            $services->procurement()->updateSupplier($id, $input, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, $input, $this->errorsOf($exception, 'name'), $exception->status());
        }

        $this->flash($request, 'ok', 'Supplier saved.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/suppliers/' . $id));
    }

    public function setStatus(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $status = $this->text($request, 'status');

        try {
            $services->procurement()->setSupplierStatus($id, $status, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, [], $this->errorsOf($exception, 'status'), $exception->status());
        }

        $this->flash($request, 'ok', $status === 'active' ? 'Supplier reactivated.' : 'Supplier deactivated.', 'Existing purchase orders are not changed.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/suppliers/' . $id));
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $this->text($request, $field);
        }

        return $input;
    }

    /**
     * @param array<string, string> $createInput
     * @param array<string, string> $createErrors
     */
    private function renderIndex(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        array $createInput = [],
        array $createErrors = [],
        int $status = 200,
    ): Response {
        $query = ListQuery::fromRequest(
            $request,
            ['name' => 's.name', 'lead_time' => 's.lead_time_days', 'status' => 's.status'],
            'name',
            ['status'],
        );
        $filterErrors = [];
        $statusFilter = (string) ($query->filter('status') ?? '');
        if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
            $filterErrors['status'] = 'Choose active or inactive.';
        }

        if ($filterErrors !== []) {
            $result = $query->result([], 0);
            $status = 422;
        } else {
            $result = $services->procurement()->listSuppliers($query);
        }

        return $this->render($request, $tenant, $staff, 'suppliers', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => ['status' => $statusFilter],
            'createInput' => $createInput,
            'createErrors' => $createErrors,
            'canEdit' => $this->can($staff, $tenant, 'procurement', 'edit'),
            'activeTab' => 'suppliers',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/procurement'),
            'suppliersUrl' => $this->adminUrl($request, $tenant, '/procurement/suppliers'),
        ], $status);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderShow(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        string $id,
        array $input = [],
        array $errors = [],
        int $status = 200,
    ): Response {
        $supplier = $services->procurement()->getSupplier($id);
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = (string) ($supplier[$field] ?? '');
        }

        return $this->render($request, $tenant, $staff, 'supplier', [
            'supplier' => $supplier,
            'input' => $input + $values,
            'errors' => $errors,
            'canEdit' => $this->can($staff, $tenant, 'procurement', 'edit'),
            'activeTab' => 'suppliers',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/procurement'),
            'suppliersUrl' => $this->adminUrl($request, $tenant, '/procurement/suppliers'),
        ], $status);
    }
}
