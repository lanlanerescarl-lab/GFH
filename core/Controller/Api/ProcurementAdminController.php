<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;

/** Admin suppliers and purchase orders. Procurement permissions: view to read, edit to change and receive. */
final class ProcurementAdminController extends ApiController
{
    public function suppliers(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'view');
        $query = ListQuery::fromRequest($request, ['name' => 's.name', 'lead_time' => 's.lead_time_days', 'status' => 's.status'], 'name', ['status']);

        return Response::ok($services->procurement()->listSuppliers($query));
    }

    public function supplier(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'view');

        return Response::ok($services->procurement()->getSupplier($this->id($request)));
    }

    public function createSupplier(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');

        return Response::ok($services->procurement()->createSupplier($this->body($request), $this->actorId($request)), 201);
    }

    public function updateSupplier(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $services->procurement()->updateSupplier($id, $this->body($request), $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    public function setSupplierStatus(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $services->procurement()->setSupplierStatus($id, $this->text($request, 'status'), $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => $this->text($request, 'status')]);
    }

    public function purchaseOrders(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'view');
        $query = ListQuery::fromRequest(
            $request,
            ['created' => 'p.created_at', 'number' => 'p.po_number', 'expected' => 'p.expected_on', 'supplier' => 's.name'],
            '-created',
            ['status', 'supplier_id', 'branch_id'],
            ['supplier_id', 'branch_id'],
        );

        return Response::ok($services->procurement()->listPurchaseOrders($query));
    }

    public function purchaseOrder(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'view');

        return Response::ok($services->procurement()->getPurchaseOrder($this->id($request)));
    }

    public function createPurchaseOrder(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');

        return Response::ok($services->procurement()->createPurchaseOrder($this->body($request), $this->actorId($request)), 201);
    }

    public function sendPurchaseOrder(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $services->procurement()->sendPurchaseOrder($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'sent']);
    }

    public function cancelPurchaseOrder(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');
        $id = $this->id($request);
        $services->procurement()->cancelPurchaseOrder($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'cancelled']);
    }

    /** POST .../purchase-orders/{id}/receive with {lines: [{line_id, qty_received, qty_rejected, batch_code, expires_on, bin}]}. */
    public function receive(Request $request): Response
    {
        $services = $this->authorize($request, 'procurement', 'edit');
        $lines = $this->body($request)['lines'] ?? [];

        return Response::ok($services->procurement()->receive($this->id($request), is_array($lines) ? array_values($lines) : [], $this->actorId($request)));
    }
}
