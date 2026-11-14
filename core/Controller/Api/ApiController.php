<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Tenant;

/**
 * Shared helpers for the JSON API controllers. A controller reads input, checks permission and calls a service;
 * business rules live in the services.
 *
 * Every endpoint of a controller takes its services from exactly one of these:
 *   authorize()      staff, with can(user, organization, module, action) checked
 *   customerContext()  a signed-in customer
 *   publicServices() anyone (catalog reads, the media route)
 * The ApiAuthorizationTest checks that no endpoint reaches a service any other way.
 */
abstract class ApiController
{
    public function __construct(protected readonly Services $services)
    {
    }

    /**
     * Checks that the signed-in staff member may do this, and returns the organization's services.
     *
     * @throws HttpException 401 not signed in, 403 not allowed
     */
    protected function authorize(Request $request, string $module, string $action): TenantServices
    {
        $tenant = $this->tenant($request);
        $this->services->permissions()->assert($this->staff($request), $tenant->organization, $module, $action);

        return $this->services->forTenant($tenant);
    }

    /** Services for anonymous reads. Only catalog browsing and media use this. */
    protected function publicServices(Request $request): TenantServices
    {
        return $this->services->forTenant($this->tenant($request));
    }

    /**
     * A signed-in customer and the organization's services.
     *
     * @return array{0: CustomerAccount, 1: TenantServices, 2: Tenant}
     */
    protected function customerContext(Request $request): array
    {
        $customer = $request->attribute('customer');
        if (!$customer instanceof CustomerAccount) {
            throw HttpException::unauthorized();
        }

        $tenant = $this->tenant($request);

        return [$customer, $this->services->forTenant($tenant), $tenant];
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This route needs TenantMiddleware.');
        }

        return $tenant;
    }

    protected function staff(Request $request): StaffUser
    {
        $staff = $request->attribute('staff');
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        return $staff;
    }

    protected function actorId(Request $request): ?string
    {
        return $this->staff($request)->id;
    }

    /** A route id, checked to be a UUID so a bad value is a 404 and never reaches the database. */
    protected function id(Request $request, string $name = 'id'): string
    {
        $value = (string) $request->param($name, '');
        if (!Uuid::isValid($value)) {
            throw HttpException::notFound();
        }

        return $value;
    }

    /** The decoded JSON (or form) body. */
    protected function body(Request $request): array
    {
        return $request->body();
    }

    protected function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
