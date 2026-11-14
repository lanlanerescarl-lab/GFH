<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;

/** Admin inventory. Inventory permissions: view to read levels and the ledger, edit to adjust or write off. */
final class InventoryAdminController extends ApiController
{
    public function levels(Request $request): Response
    {
        $services = $this->authorize($request, 'inventory', 'view');
        $query = ListQuery::fromRequest(
            $request,
            ['product' => 'p.name', 'sku' => 'v.sku', 'branch' => 'b.name', 'on_hand' => 'l.on_hand', 'available' => 'available'],
            'product',
            ['branch_id', 'variant_id', 'low_stock'],
            ['branch_id', 'variant_id'],
        );

        return Response::ok($services->inventory()->levels($query));
    }

    public function ledger(Request $request): Response
    {
        $services = $this->authorize($request, 'inventory', 'view');
        $query = ListQuery::fromRequest(
            $request,
            ['time' => 'm.occurred_at', 'sku' => 'v.sku', 'product' => 'p.name', 'type' => 'm.movement_type'],
            '-time',
            ['branch_id', 'variant_id', 'type', 'batch_id', 'from', 'to'],
            ['branch_id', 'variant_id', 'batch_id'],
            ['from', 'to'],
        );

        return Response::ok($services->inventory()->ledger($query));
    }

    /** POST .../inventory/adjustments with {branch_id, variant_id, delta (+ or -), reason}. A reason is required. */
    public function adjust(Request $request): Response
    {
        $services = $this->authorize($request, 'inventory', 'edit');
        $body = $this->body($request);

        return Response::ok($services->inventory()->adjust(
            (string) ($body['branch_id'] ?? ''), (string) ($body['variant_id'] ?? ''), $body['delta'] ?? null, $this->text($request, 'reason'), $this->actorId($request),
        ), 201);
    }

    /** POST .../inventory/write-offs with {branch_id, variant_id, quantity, reason}. A reason is required. */
    public function writeOff(Request $request): Response
    {
        $services = $this->authorize($request, 'inventory', 'edit');
        $body = $this->body($request);

        return Response::ok($services->inventory()->writeOff(
            (string) ($body['branch_id'] ?? ''), (string) ($body['variant_id'] ?? ''), $body['quantity'] ?? null, $this->text($request, 'reason'), $this->actorId($request),
        ), 201);
    }
}
