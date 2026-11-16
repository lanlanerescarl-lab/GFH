<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Orders: list, inspect, advance progress steps, record manual payments, cancel orders and render
 * extension detail panels. Progress state and payment status remain independent.
 */
final class OrdersController extends AdminPageController
{
    private const PAYMENT_STATUSES = ['unpaid', 'deposit_paid', 'paid', 'refunded'];
    private const CHANNELS = ['online', 'pos'];
    private const METHOD_LABELS = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'cod' => 'Cash on delivery',
    ];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'view');

        $filterErrors = [];
        try {
            $query = ListQuery::fromRequest(
                $request,
                ['placed' => 'o.placed_at', 'total' => 'o.total', 'number' => 'o.order_number', 'customer' => 'c.full_name'],
                '-placed',
                ['progress_key', 'payment_status', 'customer_id', 'channel', 'refund_review', 'cancelled', 'from', 'to'],
                ['customer_id'],
                ['from', 'to'],
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            $query = ListQuery::fromRequest(
                new Request('GET', $request->path()),
                ['placed' => 'o.placed_at', 'total' => 'o.total', 'number' => 'o.order_number', 'customer' => 'c.full_name'],
                '-placed',
            );
            $filterErrors = $exception->fields();
        }

        $paymentStatus = (string) ($query->filter('payment_status') ?? '');
        if ($paymentStatus !== '' && !in_array($paymentStatus, self::PAYMENT_STATUSES, true)) {
            $filterErrors['payment_status'] = 'Choose a valid payment status.';
        }
        $channel = (string) ($query->filter('channel') ?? '');
        if ($channel !== '' && !in_array($channel, self::CHANNELS, true)) {
            $filterErrors['channel'] = 'Choose online or pos.';
        }

        $statusCode = 200;
        if ($filterErrors !== []) {
            $result = $query->result([], 0);
            $statusCode = 422;
        } else {
            $result = $services->orders()->listOrders($query);
        }

        return $this->render($request, $tenant, $staff, 'orders', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => [
                'progress_key' => (string) ($query->filter('progress_key') ?? ''),
                'payment_status' => $paymentStatus,
                'channel' => $channel,
                'cancelled' => (string) ($query->filter('cancelled') ?? ''),
                'refund_review' => (string) ($query->filter('refund_review') ?? ''),
                'from' => (string) ($query->filter('from') ?? ''),
                'to' => (string) ($query->filter('to') ?? ''),
            ],
            'steps' => $services->orders()->allSteps(),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'posReviewCount' => $services->pos()->needsReviewCount(),
            'activeTab' => 'orders',
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

    public function advance(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'edit');
        $id = $this->id($request);
        $stepKey = $this->text($request, 'progress_key');

        try {
            $services->orders()->advance($id, $stepKey, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, $this->errorsOf($exception, 'step'), [], [], [], [], $exception->status());
        }

        $this->flash($request, 'ok', 'Order progress updated.');

        return Response::redirect($this->adminUrl($request, $tenant, '/orders/' . $id));
    }

    public function recordPayment(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'edit');
        $id = $this->id($request);
        $input = [
            'method' => $this->text($request, 'method'),
            'amount' => $this->text($request, 'amount'),
            'reference' => $this->text($request, 'reference'),
        ];

        try {
            $services->orders()->recordPayment($id, $input, $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, [], $input, $this->errorsOf($exception, 'amount'), [], [], $exception->status());
        }

        $this->flash($request, 'ok', 'Payment recorded.', 'Payment summary and status have been updated.');

        return Response::redirect($this->adminUrl($request, $tenant, '/orders/' . $id));
    }

    public function cancel(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'edit');
        $id = $this->id($request);
        $input = ['reason' => $this->text($request, 'reason')];

        try {
            $services->orders()->cancel($id, $input['reason'], $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                throw $exception;
            }

            return $this->renderShow($request, $tenant, $staff, $services, $id, [], [], [], $input, $this->errorsOf($exception, 'reason'), $exception->status());
        }

        $this->flash($request, 'ok', 'Order cancelled.', 'Reserved stock has been released.');

        return Response::redirect($this->adminUrl($request, $tenant, '/orders/' . $id));
    }

    /**
     * @param array<string, string> $advanceErrors
     * @param array<string, string> $paymentInput
     * @param array<string, string> $paymentErrors
     * @param array<string, string> $cancelInput
     * @param array<string, string> $cancelErrors
     */
    private function renderShow(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        string $id,
        array $advanceErrors = [],
        array $paymentInput = [],
        array $paymentErrors = [],
        array $cancelInput = [],
        array $cancelErrors = [],
        int $status = 200,
    ): Response {
        $order = $services->orders()->getOrder($id);
        $customer = $services->orders()->getCustomer((string) $order['customer_id']);
        $canEdit = $this->can($staff, $tenant, 'sales', 'edit');

        $rawPanels = $services->hooks()->filter(Hooks::ORDER_DETAIL_PANELS, [], [
            'order' => $order,
            'customer' => $customer,
            'can_edit' => $canEdit,
        ]);
        $panels = [];
        foreach (is_array($rawPanels) ? $rawPanels : [] as $panel) {
            if (!is_array($panel) || !isset($panel['title'])) {
                continue;
            }
            $panels[] = [
                'id' => (string) ($panel['id'] ?? ''),
                'title' => (string) $panel['title'],
                'subtitle' => isset($panel['subtitle']) ? (string) $panel['subtitle'] : '',
                'bodyHtml' => (string) ($panel['bodyHtml'] ?? $panel['contentHtml'] ?? ''),
            ];
        }

        $methods = [];
        foreach ($services->payments()->methods() as $key) {
            $methods[$key] = self::METHOD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
        }

        return $this->render($request, $tenant, $staff, 'order', [
            'order' => $order,
            'customer' => $customer,
            'panels' => $panels,
            'paymentMethods' => $methods,
            'branches' => $this->branchMap($services),
            'advanceErrors' => $advanceErrors,
            'paymentInput' => $paymentInput + ['method' => '', 'amount' => '', 'reference' => ''],
            'paymentErrors' => $paymentErrors,
            'cancelInput' => $cancelInput + ['reason' => ''],
            'cancelErrors' => $cancelErrors,
            'canEdit' => $canEdit,
            'posReviewCount' => $services->pos()->needsReviewCount(),
            'activeTab' => 'orders',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/orders'),
            'posSalesUrl' => $this->adminUrl($request, $tenant, '/pos-sales'),
            'customersUrl' => $this->adminUrl($request, $tenant, '/customers'),
        ], $status);
    }

    /** @return array<string, string> */
    private function branchMap(TenantServices $services): array
    {
        $map = [];
        foreach ($services->inventory()->branches(false) as $branch) {
            $map[(string) $branch['id']] = (string) $branch['name'];
        }

        return $map;
    }
}
