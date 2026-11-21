<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/**
 * The signed-in customer's own profile, saved addresses and password (/{org}/api/v1/account/...).
 *
 * The customer always comes from the session (customerContext), never from the body or the URL. The rules live in
 * CustomerAccountService; this class reads input, calls it and returns the JSON envelope.
 */
final class AccountController extends ApiController
{
    /** GET /{org}/api/v1/account/profile */
    public function profile(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->profile($customer));
    }

    /** PUT /{org}/api/v1/account/profile. Body: full_name, phone. Email and status cannot be changed here. */
    public function updateProfile(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->updateProfile($customer, $this->body($request)));
    }

    /** POST /{org}/api/v1/account/password. Body: current_password, new_password, new_password_confirmation. */
    public function changePassword(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        $this->services->customerAccounts()->changePassword(
            $customer,
            $this->text($request, 'current_password'),
            $this->text($request, 'new_password'),
            $this->text($request, 'new_password_confirmation'),
            $request->ip(),
        );

        // A new id after a credential change, as at sign-in, so an id someone else may have seen stops working.
        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->regenerate();
        }

        return Response::ok(['password_changed' => true]);
    }

    /** GET /{org}/api/v1/account/addresses: the default first, then the newest. */
    public function addresses(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->listAddresses($customer));
    }

    /** POST /{org}/api/v1/account/addresses. Body: label?, line1, barangay?, city, province, postal_code?. 201 with the address. */
    public function createAddress(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->createAddress($customer, $this->body($request)), 201);
    }

    /** PUT /{org}/api/v1/account/addresses/{id}. Same body as create. Another customer's address is a plain 404. */
    public function updateAddress(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->updateAddress($customer, $this->id($request), $this->body($request)));
    }

    /** POST /{org}/api/v1/account/addresses/{id}/default */
    public function setDefaultAddress(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->setDefaultAddress($customer, $this->id($request)));
    }

    /** DELETE /{org}/api/v1/account/addresses/{id} */
    public function deleteAddress(Request $request): Response
    {
        [$customer] = $this->customerContext($request);

        return Response::ok($this->services->customerAccounts()->deleteAddress($customer, $this->id($request)));
    }
}
