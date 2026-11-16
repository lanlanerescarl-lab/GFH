<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Customers: search, filter and sort organization customers and allow an organization Admin
 * to issue one-time customer password reset links through PasswordResetService.
 */
final class CustomersController extends AdminPageController
{
    private const STATUSES = ['active', 'inactive'];
    private const TYPES = ['individual', 'business'];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'view');

        return $this->renderIndex($request, $tenant, $staff, $services);
    }

    public function issueResetLink(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'sales', 'edit');
        $id = $this->id($request);
        $customer = $services->orders()->getCustomer($id);

        try {
            $link = $this->services->resets()->issueForCustomer(
                $staff,
                $tenant->organization,
                new OrganizationCustomerRepository($tenant->database()),
                (string) ($customer['email'] ?? ''),
            );
        } catch (HttpException $exception) {
            if ($exception->status() === 403 || $exception->status() >= 500) {
                throw $exception;
            }

            $errors = $this->errorsOf($exception, 'email');

            return $this->renderIndex(
                $request,
                $tenant,
                $staff,
                $services,
                null,
                $errors['email'] ?? (string) reset($errors),
                $exception->status(),
            );
        }

        return $this->renderIndex($request, $tenant, $staff, $services, [
            'customer_name' => (string) $customer['full_name'],
            'email' => (string) ($customer['email'] ?? ''),
            'url' => $link['url'],
            'expires_at' => $link['expires_at'],
        ]);
    }

    /** @param array{customer_name: string, email: string, url: string, expires_at: string}|null $resetLink */
    private function renderIndex(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        ?array $resetLink = null,
        ?string $resetError = null,
        int $status = 200,
    ): Response {
        $query = ListQuery::fromRequest(
            $request,
            [
                'name' => 'c.full_name',
                'email' => 'c.email',
                'created' => 'c.created_at',
                'orders' => 'order_count',
                'spent' => 'lifetime_total',
            ],
            'name',
            ['status', 'tier', 'customer_type', 'has_account'],
        );

        $filterErrors = [];
        $statusFilter = (string) ($query->filter('status') ?? '');
        if ($statusFilter !== '' && !in_array($statusFilter, self::STATUSES, true)) {
            $filterErrors['status'] = 'Choose active or inactive.';
        }
        $typeFilter = (string) ($query->filter('customer_type') ?? '');
        if ($typeFilter !== '' && !in_array($typeFilter, self::TYPES, true)) {
            $filterErrors['customer_type'] = 'Choose individual or business.';
        }

        if ($filterErrors !== []) {
            $result = $query->result([], 0);
            $status = 422;
        } else {
            $result = $services->orders()->listCustomers($query);
        }

        return $this->render($request, $tenant, $staff, 'customers', [
            'result' => $result,
            'query' => $query,
            'filterErrors' => $filterErrors,
            'filters' => [
                'status' => $statusFilter,
                'tier' => (string) ($query->filter('tier') ?? ''),
                'customer_type' => $typeFilter,
                'has_account' => (string) ($query->filter('has_account') ?? ''),
            ],
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'resetLink' => $resetLink,
            'resetError' => $resetError,
            'canResetLink' => $this->services->resets()->mayIssueForCustomers($staff, $tenant->organization),
            'posReviewCount' => $services->pos()->needsReviewCount(),
            'activeTab' => 'customers',
            'ordersUrl' => $this->adminUrl($request, $tenant, '/orders'),
            'posSalesUrl' => $this->adminUrl($request, $tenant, '/pos-sales'),
            'customersUrl' => $this->adminUrl($request, $tenant, '/customers'),
        ], $status);
    }
}
