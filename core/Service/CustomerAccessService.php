<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\OrganizationCustomerRepository;

/**
 * Decides what a signed-in customer may see. A customer reads only their own orders.
 * Anything else answers "not found", so an order id cannot be used to learn that another customer's order exists.
 */
final class CustomerAccessService
{
    public function __construct(private readonly OrganizationCustomerRepository $organizationCustomers)
    {
    }

    /** @throws HttpException 404 when the order is not this customer's */
    public function assertCanViewOrder(CustomerAccount $customer, string $orderId): void
    {
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $orderId) === 1;

        if (!$customer->isActive() || !$isUuid || !$this->organizationCustomers->ownsOrder($customer->id, $orderId)) {
            throw HttpException::notFound('That order could not be found.');
        }
    }
}
