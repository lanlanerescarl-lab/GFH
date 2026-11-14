<?php

declare(strict_types=1);

namespace Gfh\Core\Controller;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;

/**
 * JSON endpoints for signing in and out, registering customers and resetting passwords.
 * Controllers read input, check permission and call services; the rules live in the services.
 */
final class AuthController
{
    public function __construct(private readonly Services $services)
    {
    }

    /** GET .../auth/{scope}/csrf: starts an anonymous session and returns its CSRF token. */
    public function csrf(Request $request): Response
    {
        return Response::ok(['csrf_token' => $this->services->csrf($this->session($request))->token()]);
    }

    public function staffLogin(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $user = $this->services->auth()->authenticateStaff($this->text($request, 'email'), $this->text($request, 'password'), $request->ip());

        // Correct credentials but no membership here: refused with a clear reason, and no session is created.
        $tenant = $this->services->tenants()->authorizeStaff($tenant, $user);

        $session = $this->session($request);
        $session->regenerate();
        $session->put('user_id', $user->id);

        return Response::ok([
            'user' => $this->staffData($user),
            'role' => $tenant->role(),
            'organization' => ['slug' => $tenant->organization->slug, 'name' => $tenant->organization->name],
            'csrf_token' => $this->services->csrf($session)->rotate(),
        ]);
    }

    public function staffLogout(Request $request): Response
    {
        $this->session($request)->destroy();

        return Response::ok(['signed_out' => true]);
    }

    public function staffMe(Request $request): Response
    {
        $staff = $request->attribute('staff');
        $tenant = $this->tenant($request);
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        return Response::ok([
            'user' => $this->staffData($staff),
            'role' => $tenant->role(),
            'organization' => ['slug' => $tenant->organization->slug, 'name' => $tenant->organization->name],
        ]);
    }

    public function customerRegister(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->services->auth()->registerCustomer(
            $this->text($request, 'full_name'),
            $this->text($request, 'email'),
            $this->text($request, 'password'),
            $this->text($request, 'phone'),
            new OrganizationCustomerRepository($tenant->database()),
        );

        $this->startCustomerSession($request, $account);

        return Response::ok(['customer' => $this->customerData($account)], 201);
    }

    public function customerLogin(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->services->auth()->authenticateCustomer(
            $this->text($request, 'email'),
            $this->text($request, 'password'),
            $request->ip(),
            new OrganizationCustomerRepository($tenant->database()),
        );

        $csrf = $this->startCustomerSession($request, $account);

        return Response::ok(['customer' => $this->customerData($account), 'csrf_token' => $csrf]);
    }

    public function customerLogout(Request $request): Response
    {
        $this->session($request)->destroy();

        return Response::ok(['signed_out' => true]);
    }

    public function customerMe(Request $request): Response
    {
        $customer = $request->attribute('customer');
        if (!$customer instanceof CustomerAccount) {
            throw HttpException::unauthorized();
        }

        return Response::ok(['customer' => $this->customerData($customer)]);
    }

    /** POST .../auth/reset-password: sets a new password from a one-time link. The link itself is the proof of permission. */
    public function resetPassword(Request $request): Response
    {
        $result = $this->services->resets()->consume($this->text($request, 'token'), $this->text($request, 'password'));

        return Response::ok(['password_changed' => true, 'account_type' => $result['subject_type'] === 'user' ? 'staff' : 'customer']);
    }

    /** POST .../admin/reset-links: an authorised admin generates a one-time link to hand to a staff member or customer. */
    public function issueResetLink(Request $request): Response
    {
        $staff = $request->attribute('staff');
        $tenant = $this->tenant($request);
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        $type = $this->text($request, 'type');
        $email = $this->text($request, 'email');

        $link = match ($type) {
            'staff' => $this->services->resets()->issueForStaff($staff, $tenant->organization, $email),
            'customer' => $this->services->resets()->issueForCustomer($staff, $tenant->organization, new OrganizationCustomerRepository($tenant->database()), $email),
            default => throw HttpException::unprocessable(['type' => 'Choose staff or customer.']),
        };

        return Response::ok($link, 201);
    }

    private function startCustomerSession(Request $request, CustomerAccount $account): string
    {
        $session = $this->session($request);
        $session->regenerate();
        $session->put('user_id', $account->id);

        return $this->services->csrf($session)->rotate();
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('This route needs SessionMiddleware.');
        }

        return $session;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This route needs TenantMiddleware.');
        }

        return $tenant;
    }

    /** Input as a string; anything else (an array, a number, nothing) becomes an empty string and fails validation. */
    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }

    /** @return array<string, mixed> */
    private function staffData(StaffUser $user): array
    {
        return ['id' => $user->id, 'full_name' => $user->fullName, 'email' => $user->email, 'org_role' => $user->orgRole];
    }

    /** @return array<string, mixed> */
    private function customerData(CustomerAccount $customer): array
    {
        return ['id' => $customer->id, 'full_name' => $customer->fullName, 'email' => $customer->email, 'phone' => $customer->phone];
    }
}
