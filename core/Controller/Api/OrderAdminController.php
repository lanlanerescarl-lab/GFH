<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;

/** Admin orders. Sales permissions: view to read, edit to move an order, record a payment or cancel it. */
final class OrderAdminController extends ApiController
{
    public function index(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'view');
        $query = ListQuery::fromRequest(
            $request,
            ['placed' => 'o.placed_at', 'total' => 'o.total', 'number' => 'o.order_number', 'customer' => 'c.full_name'],
            '-placed',
            ['progress_key', 'payment_status', 'customer_id', 'channel', 'refund_review', 'cancelled', 'from', 'to'],
            ['customer_id'],
            ['from', 'to'],
        );

        return Response::ok($services->orders()->listOrders($query));
    }

    public function show(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'view');

        return Response::ok($services->orders()->getOrder($this->id($request)));
    }

    /** POST .../orders/{id}/progress with {step}. Progress and payment are separate: this never touches payment status. */
    public function advance(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'edit');

        return Response::ok($services->orders()->advance($this->id($request), $this->text($request, 'step'), $this->actorId($request)));
    }

    /** POST .../orders/{id}/payments with {method, amount, reference}. */
    public function recordPayment(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'edit');

        return Response::ok($services->orders()->recordPayment($this->id($request), $this->body($request), $this->actorId($request)), 201);
    }

    /** POST .../orders/{id}/cancel with {reason}. */
    public function cancel(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'edit');

        return Response::ok($services->orders()->cancel($this->id($request), $this->text($request, 'reason'), $this->actorId($request)));
    }
}
