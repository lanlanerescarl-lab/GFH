<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Service\CustomerAccessService;
use Gfh\Core\Support\ListQuery;

/**
 * The Shop's cart, checkout and the signed-in customer's own orders.
 * The browser may keep its cart wherever it likes; every price, stock level and total is recomputed here.
 */
final class CartController extends ApiController
{
    /** GET /{org}/api/v1/checkout/options: pickup branches, delivery availability, payment methods and extension fields. */
    public function options(Request $request): Response
    {
        return Response::ok($this->publicServices($request)->orders()->checkoutOptions());
    }

    /**
     * POST /{org}/api/v1/cart/price: prices a cart on the server. A signed-in customer gets the tiers they qualify for.
     * Body: items [{variant_id, quantity}], fulfillment (optional), checkout (optional).
     */
    public function price(Request $request): Response
    {
        $services = $this->publicServices($request);
        $body = $this->body($request);

        $customer = $request->attribute('customer');
        $record = $customer instanceof CustomerAccount ? $services->orders()->customerForAccount($customer->id) : null;

        $cart = $services->pricing()->priceCart(is_array($body['items'] ?? null) ? $body['items'] : [], [
            'customer' => $record === null ? null : ['tier' => $record['tier'], 'type' => $record['customer_type']],
            'channel' => 'online',
            'fulfillment' => is_array($body['fulfillment'] ?? null) ? $body['fulfillment'] : [],
            'checkout' => is_array($body['checkout'] ?? null) ? $body['checkout'] : [],
        ]);

        return Response::ok($cart->toArray());
    }

    /**
     * POST /{org}/api/v1/checkout: places an order for the signed-in customer and reserves the stock.
     * Body: items, fulfillment {method: pickup|delivery, branch_id | address fields}, checkout {...}.
     */
    public function checkout(Request $request): Response
    {
        [$customer, $services, $tenant] = $this->customerContext($request);
        $body = $this->body($request);

        $record = $services->orders()->customerForAccount($customer->id);
        if ($record === null) {
            // A customer who has never visited this shop gets their record here.
            (new OrganizationCustomerRepository($tenant->database()))->ensureLinked($customer->id, $customer->fullName, $customer->email, $customer->phone);
            $record = $services->orders()->customerForAccount($customer->id) ?? throw HttpException::unauthorized();
        }

        $order = $services->orders()->createOrder(
            $record,
            is_array($body['items'] ?? null) ? $body['items'] : [],
            is_array($body['fulfillment'] ?? null) ? $body['fulfillment'] : [],
            is_array($body['checkout'] ?? null) ? $body['checkout'] : [],
        );

        return Response::ok($order, 201);
    }

    /** GET /{org}/api/v1/orders: the signed-in customer's own orders. */
    public function myOrders(Request $request): Response
    {
        [$customer, $services] = $this->customerContext($request);
        $record = $services->orders()->customerForAccount($customer->id);
        $query = ListQuery::fromRequest($request, ['placed' => 'o.placed_at', 'total' => 'o.total', 'number' => 'o.order_number'], '-placed', ['progress_key', 'payment_status', 'from', 'to'], [], ['from', 'to']);

        if ($record === null) {
            return Response::ok($query->result([], 0));
        }

        return Response::ok($services->orders()->listOrders($query, (string) $record['id']));
    }

    /** GET /{org}/api/v1/orders/{id}: one of the customer's own orders, with tracking. Another customer's order is "not found". */
    public function myOrder(Request $request): Response
    {
        [$customer, $services, $tenant] = $this->customerContext($request);
        $id = $this->id($request);

        (new CustomerAccessService(new OrganizationCustomerRepository($tenant->database())))->assertCanViewOrder($customer, $id);

        return Response::ok($services->orders()->getOrder($id));
    }
}
