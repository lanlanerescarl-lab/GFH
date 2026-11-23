<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Tenancy\Tenant;

/**
 * The signed-in customer's order pages: the list, one order with its progress, and the printable receipt.
 * Every page reads through ShopOrderService with the signed-in customer, so another customer's order, an unknown id
 * and a malformed id all give the same "not found" page. A guest is sent to sign-in and comes back afterwards.
 */
final class OrderPagesController extends ShopPageController
{
    private const SORTABLE = ['placed' => 'o.placed_at', 'total' => 'o.total', 'number' => 'o.order_number'];

    /** GET /{org}/shop/orders. */
    public function index(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->customerAccount($request);
        if ($account === null) {
            return $this->toSignIn($request, $tenant);
        }

        $notice = null;
        try {
            $query = $this->listQuery($request);
        } catch (HttpException $exception) {
            // A hand-edited address (page=abc, per_page=0): show the first page instead of an error page.
            $query = $this->listQuery(new Request('GET', $request->path()));
            $notice = 'Some options in the address were not valid, so the first page of your orders is shown.';
        }

        $orders = $this->services->forTenant($tenant)->shopOrders();
        $result = $orders->listFor($account, $query);

        $statusQuery = $request->query()['progress_key'] ?? '';

        return $this->noStore($this->render($request, $tenant, 'orders', [
            'orders' => $result['items'],
            'pagination' => $result['pagination'],
            'statuses' => $orders->statusOptions(),
            'status' => is_string($statusQuery) ? $statusQuery : '',
            'notice' => $notice,
            'ordersUrl' => $this->shopUrl($request, $tenant, '/orders'),
            'orderBase' => $this->shopUrl($request, $tenant, '/orders/'),
            'shopUrl' => $this->shopUrl($request, $tenant, '/products'),
        ]));
    }

    /** GET /{org}/shop/orders/{id}. */
    public function show(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->customerAccount($request);
        if ($account === null) {
            return $this->toSignIn($request, $tenant);
        }

        $order = $this->detail($request, $tenant, $account);
        if ($order === null) {
            return $this->noStore($this->notFound($request, $tenant, 'We could not find that order.'));
        }

        $placed = $request->query()['placed'] ?? null;

        return $this->noStore($this->render($request, $tenant, 'order', [
            'order' => $order,
            'justPlaced' => $placed === '1',
            'receiptUrl' => $this->shopUrl($request, $tenant, '/orders/' . $order['id'] . '/receipt'),
            'ordersUrl' => $this->shopUrl($request, $tenant, '/orders'),
        ]));
    }

    /** GET /{org}/shop/orders/{id}/receipt. */
    public function receipt(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->customerAccount($request);
        if ($account === null) {
            return $this->toSignIn($request, $tenant);
        }

        $order = $this->detail($request, $tenant, $account);
        if ($order === null) {
            return $this->noStore($this->notFound($request, $tenant, 'We could not find that order.'));
        }

        return $this->noStore($this->render($request, $tenant, 'order-receipt', [
            'order' => $order,
            'customerName' => $account->fullName,
            'orderUrl' => $this->shopUrl($request, $tenant, '/orders/' . $order['id']),
        ]));
    }

    /** @return array<string, mixed>|null null when the order is not this customer's (or does not exist) */
    private function detail(Request $request, Tenant $tenant, CustomerAccount $account): ?array
    {
        try {
            return $this->services->forTenant($tenant)->shopOrders()->detailFor($account, (string) $request->param('id', ''));
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                return null;
            }
            throw $exception;
        }
    }

    private function listQuery(Request $request): ListQuery
    {
        return ListQuery::fromRequest($request, self::SORTABLE, '-placed', ['progress_key', 'payment_status', 'from', 'to'], [], ['from', 'to']);
    }
}
