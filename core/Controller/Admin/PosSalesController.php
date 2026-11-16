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
 * Admin POS Sales: view synced POS receipts, inspect the needs-review queue and resolve flagged sales.
 * Review rules live in PosService.
 */
final class PosSalesController extends AdminPageController
{
    private const STATUSES = ['completed', 'needs_review', 'voided'];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'view');

        $filterErrors = [];
        try {
            $query = ListQuery::fromRequest(
                $request,
                ['sold' => 's.sold_at', 'synced' => 's.synced_at', 'receipt' => 's.receipt_number', 'total' => 's.total'],
                '-sold',
                ['status', 'terminal_id', 'shift_id'],
                ['terminal_id', 'shift_id'],
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            $query = ListQuery::fromRequest(
                new Request('GET', $request->path()),
                ['sold' => 's.sold_at', 'synced' => 's.synced_at', 'receipt' => 's.receipt_number', 'total' => 's.total'],
                '-sold',
            );
            $filterErrors = $exception->fields();
        }

        $statusFilter = (string) ($query->filter('status') ?? '');
        if ($statusFilter !== '' && !in_array($statusFilter, self::STATUSES, true)) {
            $filterErrors['status'] = 'Choose completed, needs_review or voided.';
        }

        $statusCode = 200;
        if ($filterErrors !== []) {
            $result = $query->result([], 0);
            $statusCode = 422;
        } else {
            $result = $services->pos()->listSales($query);
        }

        return $this->render($request, $tenant, $staff, 'pos-sales', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => [
                'status' => $statusFilter,
                'terminal_id' => (string) ($query->filter('terminal_id') ?? ''),
            ],
            'statuses' => self::STATUSES,
            'terminals' => $services->pos()->terminals(),
            'posReviewCount' => $services->pos()->needsReviewCount(),
            'activeTab' => 'pos-sales',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/orders'),
            'posSalesUrl' => $this->adminUrl($request, $tenant, '/pos-sales'),
            'customersUrl' => $this->adminUrl($request, $tenant, '/customers'),
        ], $statusCode);
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'view');

        return $this->renderShow($request, $tenant, $staff, $services, $this->id($request));
    }

    public function resolve(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'edit');
        $id = $this->id($request);
        $input = ['note' => $this->text($request, 'note')];

        try {
            $services->pos()->resolveReview($id, $input['note'], $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, $input, $this->errorsOf($exception, 'note'), $exception->status());
        }

        $this->flash($request, 'ok', 'POS review resolved.', 'This sale is now marked completed.');

        return Response::redirect($this->adminUrl($request, $tenant, '/pos-sales/' . $id));
    }

    /**
     * @param array<string, string> $resolveInput
     * @param array<string, string> $resolveErrors
     */
    private function renderShow(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        string $id,
        array $resolveInput = [],
        array $resolveErrors = [],
        int $status = 200,
    ): Response {
        $sale = $services->pos()->getSale($id);
        $terminals = [];
        foreach ($services->pos()->terminals() as $terminal) {
            $terminals[(string) $terminal['id']] = $terminal;
        }
        $branches = [];
        foreach ($services->inventory()->branches(false) as $branch) {
            $branches[(string) $branch['id']] = (string) $branch['name'];
        }

        $customer = null;
        if (!empty($sale['customer_id'])) {
            try {
                $customer = $services->orders()->getCustomer((string) $sale['customer_id']);
            } catch (HttpException) {
                $customer = null;
            }
        }

        return $this->render($request, $tenant, $staff, 'pos-sale', [
            'sale' => $sale,
            'terminal' => $terminals[(string) $sale['terminal_id']] ?? null,
            'branchName' => $branches[(string) $sale['branch_id']] ?? (string) $sale['branch_id'],
            'customer' => $customer,
            'resolveInput' => $resolveInput + ['note' => ''],
            'resolveErrors' => $resolveErrors,
            'canEdit' => $this->can($staff, $tenant, 'sales', 'edit'),
            'posReviewCount' => $services->pos()->needsReviewCount(),
            'activeTab' => 'pos-sales',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/orders'),
            'posSalesUrl' => $this->adminUrl($request, $tenant, '/pos-sales'),
            'customersUrl' => $this->adminUrl($request, $tenant, '/customers'),
        ], $status);
    }
}
