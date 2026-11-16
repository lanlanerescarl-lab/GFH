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
 * Admin Purchase Orders: list, create, view, send, cancel and receive stock (with batch & rejected capture).
 * Cost, stock movement and receipt rules live in ProcurementService and InventoryService.
 */
final class PurchaseOrdersController extends AdminPageController
{
    private const STATUSES = ['draft', 'sent', 'partially_received', 'received', 'cancelled'];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'view');

        $filterErrors = [];
        try {
            $query = ListQuery::fromRequest(
                $request,
                ['created' => 'p.created_at', 'number' => 'p.po_number', 'expected' => 'p.expected_on', 'supplier' => 's.name'],
                '-created',
                ['status', 'supplier_id', 'branch_id'],
                ['supplier_id', 'branch_id'],
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            $query = ListQuery::fromRequest(
                new Request('GET', $request->path()),
                ['created' => 'p.created_at', 'number' => 'p.po_number', 'expected' => 'p.expected_on', 'supplier' => 's.name'],
                '-created',
            );
            $filterErrors = $exception->fields();
        }

        $statusFilter = (string) ($query->filter('status') ?? '');
        if ($statusFilter !== '' && !in_array($statusFilter, self::STATUSES, true)) {
            $filterErrors['status'] = 'Choose a valid purchase order status.';
        }

        $statusCode = 200;
        if ($filterErrors !== []) {
            $result = $query->result([], 0);
            $statusCode = 422;
        } else {
            $result = $services->procurement()->listPurchaseOrders($query);
        }

        return $this->render($request, $tenant, $staff, 'purchase-orders', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => [
                'status' => $statusFilter,
                'supplier_id' => (string) ($query->filter('supplier_id') ?? ''),
                'branch_id' => (string) ($query->filter('branch_id') ?? ''),
            ],
            'statuses' => self::STATUSES,
            'suppliers' => $services->catalog()->activeSuppliers(),
            'branches' => $services->inventory()->branches(false),
            'canEdit' => $this->can($staff, $tenant, 'procurement', 'edit'),
            'activeTab' => 'orders',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/procurement'),
            'suppliersUrl' => $this->adminUrl($request, $tenant, '/procurement/suppliers'),
        ], $statusCode);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');

        return $this->renderForm($request, $tenant, $staff, $services);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');

        $input = [
            'supplier_id' => $this->text($request, 'supplier_id'),
            'branch_id' => $this->text($request, 'branch_id'),
            'expected_on' => $this->text($request, 'expected_on'),
            'notes' => $this->text($request, 'notes'),
        ];

        $postedLines = $this->postedRows($request, 'lines');
        $lines = [];
        foreach ($postedLines as $row) {
            $variantId = (string) ($row['variant_id'] ?? '');
            $quantity = (string) ($row['quantity'] ?? '');
            $unitCost = (string) ($row['unit_cost'] ?? '');
            if ($variantId === '' && $quantity === '' && $unitCost === '') {
                continue;
            }
            $lines[] = [
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ];
        }

        try {
            $created = $services->procurement()->createPurchaseOrder($input + ['lines' => $lines], $staff->id);
        } catch (HttpException $exception) {
            return $this->renderForm(
                $request,
                $tenant,
                $staff,
                $services,
                $input,
                $lines !== [] ? $lines : [['variant_id' => '', 'quantity' => '', 'unit_cost' => '']],
                $this->errorsOf($exception, 'lines'),
                $exception->status(),
            );
        }

        $this->flash($request, 'ok', 'Purchase order created.', $created['po_number'] . ' saved as draft.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/' . $created['id']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'view');

        return $this->renderShow($request, $tenant, $staff, $services, $this->id($request));
    }

    public function send(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);

        try {
            $services->procurement()->sendPurchaseOrder($id, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, [], [], $this->errorsOf($exception, 'status'), $exception->status());
        }

        $this->flash($request, 'ok', 'Purchase order sent.', 'Goods can now be received against this order.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/' . $id));
    }

    public function cancel(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);

        try {
            $services->procurement()->cancelPurchaseOrder($id, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, [], [], $this->errorsOf($exception, 'status'), $exception->status());
        }

        $this->flash($request, 'ok', 'Purchase order cancelled.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/' . $id));
    }

    public function receive(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);

        $rows = $this->postedRows($request, 'lines');
        $receiveInput = [];
        $entries = [];
        $indexToLineId = [];

        foreach ($rows as $row) {
            $lineId = (string) ($row['line_id'] ?? '');
            if ($lineId !== '') {
                $receiveInput[$lineId] = $row;
            }

            $received = trim((string) ($row['qty_received'] ?? ''));
            $rejected = trim((string) ($row['qty_rejected'] ?? ''));
            $batchCode = trim((string) ($row['batch_code'] ?? ''));
            $expiresOn = trim((string) ($row['expires_on'] ?? ''));
            $bin = trim((string) ($row['bin'] ?? ''));

            $hasQty = ($received !== '' && $received !== '0') || ($rejected !== '' && $rejected !== '0');
            if (!$hasQty && $batchCode === '' && $expiresOn === '' && $bin === '') {
                continue;
            }

            $indexToLineId[count($entries)] = $lineId;
            $entries[] = [
                'line_id' => $lineId,
                'qty_received' => $received === '' ? '0' : $received,
                'qty_rejected' => $rejected === '' ? '0' : $rejected,
                'batch_code' => $batchCode,
                'expires_on' => $expiresOn === '' ? null : $expiresOn,
                'bin' => $bin === '' ? null : $bin,
            ];
        }

        try {
            $po = $services->procurement()->receive($id, $entries, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'lines');
            $lineErrors = [];
            $formErrors = [];
            foreach ($errors as $key => $message) {
                if (preg_match('/^lines\.(\d+)\.(.+)$/', $key, $matches) === 1) {
                    $mappedLineId = $indexToLineId[(int) $matches[1]] ?? '';
                    if ($mappedLineId !== '') {
                        $lineErrors[$mappedLineId][$matches[2]] = $message;
                        continue;
                    }
                }
                $formErrors[$key] = $message;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, $receiveInput, $lineErrors, $formErrors, $exception->status());
        }

        $label = $po['status'] === 'received' ? 'Purchase order fully received.' : 'Receipt recorded (partially received).';
        $this->flash($request, 'ok', $label, 'Stock and weighted-average cost have been updated.');

        return Response::redirect($this->adminUrl($request, $tenant, '/procurement/' . $id));
    }

    /**
     * @param array<string, string> $input
     * @param list<array<string, string>> $lines
     * @param array<string, string> $errors
     */
    private function renderForm(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        array $input = [],
        array $lines = [],
        array $errors = [],
        int $status = 200,
    ): Response {
        if ($lines === []) {
            $lines = [
                ['variant_id' => '', 'quantity' => '', 'unit_cost' => ''],
                ['variant_id' => '', 'quantity' => '', 'unit_cost' => ''],
                ['variant_id' => '', 'quantity' => '', 'unit_cost' => ''],
            ];
        }

        return $this->render($request, $tenant, $staff, 'purchase-order-form', [
            'input' => $input + ['supplier_id' => '', 'branch_id' => '', 'expected_on' => '', 'notes' => ''],
            'lines' => $lines,
            'errors' => $errors,
            'suppliers' => $services->catalog()->activeSuppliers(),
            'branches' => $services->inventory()->branches(true),
            'variants' => $services->inventory()->variantsForSelect(),
            'activeTab' => 'orders',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/procurement'),
            'suppliersUrl' => $this->adminUrl($request, $tenant, '/procurement/suppliers'),
        ], $status);
    }

    /**
     * @param array<string, array<string, string>> $receiveInput
     * @param array<string, array<string, string>> $lineErrors
     * @param array<string, string> $formErrors
     */
    private function renderShow(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        string $id,
        array $receiveInput = [],
        array $lineErrors = [],
        array $formErrors = [],
        int $status = 200,
    ): Response {
        $po = $services->procurement()->getPurchaseOrder($id);

        return $this->render($request, $tenant, $staff, 'purchase-order', [
            'po' => $po,
            'receiveInput' => $receiveInput,
            'lineErrors' => $lineErrors,
            'formErrors' => $formErrors,
            'canEdit' => $this->can($staff, $tenant, 'procurement', 'edit'),
            'activeTab' => 'orders',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/procurement'),
            'suppliersUrl' => $this->adminUrl($request, $tenant, '/procurement/suppliers'),
        ], $status);
    }
}
