<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\CustomerAccountService;
use Gfh\Core\Support\ShopRedirect;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Tenant;

/**
 * The signed-in customer's own account pages: the account home, profile, saved addresses and password.
 *
 * Every page and every form calls CustomerAccountService, the same service the JSON API uses, so the rules (the ten
 * address limit, the single default, the password policy, the sign-in lockout, the audit entries) live in one place.
 * This class reads the form, shows the result and chooses where to go next. Whose data is touched always comes from
 * the session; an id in the address only ever picks one of the signed-in customer's own addresses, and an unknown,
 * malformed or somebody else's id gives the same "not found" page.
 *
 * A form posts to a page route and, when it works, redirects to a page that shows a short confirmation (post, redirect,
 * get), so a reload never repeats the change. The confirmation is a key kept in the session and printed from a fixed
 * list of sentences, never text taken from the request. Every page sends Cache-Control: no-store.
 */
final class AccountPagesController extends ShopPageController
{
    private const FLASH_KEY = 'shop_account_flash';

    /** @var array<string, string> confirmation key => sentence */
    private const FLASH = [
        'profile_saved' => 'Your profile was saved.',
        'address_added' => 'The address was added.',
        'address_saved' => 'The address was saved.',
        'address_default' => 'This is now your default address.',
        'address_deleted' => 'The address was deleted.',
        'password_changed' => 'Your password was changed.',
    ];

    private const ADDRESS_FIELDS = ['label', 'line1', 'barangay', 'city', 'province', 'postal_code'];

    // ------------------------------------------------------------------ home

    /** GET /{org}/shop/account */
    public function home(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $profile = $this->services->customerAccounts()->profile($account)['customer'];

        return $this->page($request, $tenant, 'account', ['customer' => $profile]);
    }

    // ------------------------------------------------------------------ profile

    /** GET /{org}/shop/account/profile */
    public function profileForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $profile = $this->services->customerAccounts()->profile($account)['customer'];

        return $this->profilePage($request, $tenant, $profile, ['full_name' => $profile['full_name'], 'phone' => (string) ($profile['phone'] ?? '')]);
    }

    /** POST /{org}/shop/account/profile. Only the name and phone are read; a posted email or status is never looked at. */
    public function profileSave(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $values = ['full_name' => $this->text($request, 'full_name'), 'phone' => $this->text($request, 'phone')];

        try {
            $this->services->customerAccounts()->updateProfile($account, $values);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }
            $profile = $this->services->customerAccounts()->profile($account)['customer'];

            return $this->profilePage($request, $tenant, $profile, $values, $exception->fields(), 422);
        }

        return $this->done($request, $tenant, 'profile_saved', '/account/profile');
    }

    // ------------------------------------------------------------------ addresses

    /** GET /{org}/shop/account/addresses */
    public function addresses(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        return $this->addressesPage($request, $tenant, $account, $this->blankAddress());
    }

    /** POST /{org}/shop/account/addresses (add one). */
    public function addressCreate(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $values = $this->addressInput($request);

        try {
            $this->services->customerAccounts()->createAddress($account, $values);
        } catch (HttpException $exception) {
            if ($exception->status() === 422) {
                return $this->addressesPage($request, $tenant, $account, $values, $exception->fields(), 422);
            }
            if ($exception->status() === 409) {
                // The limit of ten (the service's own sentence), reached by another tab or device since this page was drawn.
                return $this->addressesPage($request, $tenant, $account, $values, ['form' => $exception->getMessage()], 409);
            }
            throw $exception;
        }

        return $this->done($request, $tenant, 'address_added', '/account/addresses');
    }

    /** GET /{org}/shop/account/addresses/{id}/edit */
    public function addressEdit(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $address = $this->ownAddress($account, $request);
        if ($address === null) {
            return $this->addressNotFound($request, $tenant);
        }

        return $this->addressEditPage($request, $tenant, $address, $this->addressValues($address));
    }

    /** POST /{org}/shop/account/addresses/{id} (save changes). */
    public function addressUpdate(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $address = $this->ownAddress($account, $request);
        if ($address === null) {
            return $this->addressNotFound($request, $tenant);
        }

        $values = $this->addressInput($request);

        try {
            $this->services->customerAccounts()->updateAddress($account, $address['id'], $values);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                return $this->addressNotFound($request, $tenant);
            }
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->addressEditPage($request, $tenant, $address, $values, $exception->fields(), 422);
        }

        return $this->done($request, $tenant, 'address_saved', '/account/addresses');
    }

    /** POST /{org}/shop/account/addresses/{id}/default */
    public function addressDefault(Request $request): Response
    {
        return $this->addressAction($request, 'address_default', static fn (CustomerAccountService $service, CustomerAccount $account, string $id) => $service->setDefaultAddress($account, $id));
    }

    /** POST /{org}/shop/account/addresses/{id}/delete */
    public function addressDelete(Request $request): Response
    {
        return $this->addressAction($request, 'address_deleted', static fn (CustomerAccountService $service, CustomerAccount $account, string $id) => $service->deleteAddress($account, $id));
    }

    // ------------------------------------------------------------------ password

    /** GET /{org}/shop/account/password */
    public function passwordForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        return $this->passwordPage($request, $tenant);
    }

    /** POST /{org}/shop/account/password */
    public function passwordSave(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        try {
            $this->services->customerAccounts()->changePassword(
                $account,
                $this->text($request, 'current_password'),
                $this->text($request, 'new_password'),
                $this->text($request, 'new_password_confirmation'),
                $request->ip(),
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            if ($exception->status() === 422) {
                // Nothing typed in a password box is ever sent back to the browser (see passwordPage).
                return $this->passwordPage($request, $tenant, $exception->fields(), 422);
            }
            if ($exception->status() === 429) {
                // Locked out by the sign-in limit: one message, with the Retry-After the service gave.
                $response = $this->passwordPage($request, $tenant, ['form' => $exception->getMessage()], 429);
                foreach ($exception->headers() as $name => $value) {
                    $response = $response->withHeader((string) $name, (string) $value);
                }

                return $response;
            }
            throw $exception;
        }

        // A new session id after a credential change, as the JSON API does and as sign-in does.
        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->regenerate();
        }

        return $this->done($request, $tenant, 'password_changed', '/account/password');
    }

    // ------------------------------------------------------------------ pages

    /**
     * @param array<string, mixed> $profile
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function profilePage(Request $request, Tenant $tenant, array $profile, array $values, array $errors = [], int $status = 200): Response
    {
        return $this->page($request, $tenant, 'account-profile', [
            'action' => $this->shopUrl($request, $tenant, '/account/profile'),
            'email' => (string) $profile['email'],
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param array<string, string> $values the add form's fields
     * @param array<string, string> $errors
     */
    private function addressesPage(Request $request, Tenant $tenant, CustomerAccount $account, array $values, array $errors = [], int $status = 200): Response
    {
        $list = $this->services->customerAccounts()->listAddresses($account);
        $base = $this->shopUrl($request, $tenant, '/account/addresses');

        return $this->page($request, $tenant, 'account-addresses', [
            'addresses' => $list['items'],
            'maxAddresses' => $list['max_addresses'],
            'addressBase' => $base,
            'action' => $base,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param array<string, mixed> $address
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function addressEditPage(Request $request, Tenant $tenant, array $address, array $values, array $errors = [], int $status = 200): Response
    {
        $base = $this->shopUrl($request, $tenant, '/account/addresses');

        return $this->page($request, $tenant, 'account-address-edit', [
            'address' => $address,
            'listUrl' => $base,
            'action' => $base . '/' . $address['id'],
            'deleteAction' => $base . '/' . $address['id'] . '/delete',
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function passwordPage(Request $request, Tenant $tenant, array $errors = [], int $status = 200): Response
    {
        return $this->page($request, $tenant, 'account-password', [
            'action' => $this->shopUrl($request, $tenant, '/account/password'),
            // The rule's one copy is PasswordPolicy; the server's own message names anything else that is wrong.
            'passwordHelp' => sprintf('At least %d characters.', PasswordPolicy::MIN_BYTES),
            'errors' => $errors,
        ], $status);
    }

    /**
     * A page of the account area: no-store, the account menu, and the confirmation left by the last change (shown once).
     *
     * @param array<string, mixed> $data
     */
    private function page(Request $request, Tenant $tenant, string $template, array $data, int $status = 200): Response
    {
        $basePath = $request->basePath();

        return $this->noStore($this->render($request, $tenant, $template, $data + [
            'accountMenu' => $this->services->shopAccountMenu($tenant)->items($basePath, $tenant->organization->slug, $basePath . $request->path()),
            'confirmation' => $this->takeConfirmation($request),
        ], $status));
    }

    private function addressNotFound(Request $request, Tenant $tenant): Response
    {
        return $this->noStore($this->notFound($request, $tenant, 'We could not find that address.'));
    }

    // ------------------------------------------------------------------ helpers

    /** The signed-in customer, or the redirect that sends a visitor to sign-in (staff sessions have no customer, so they are sent there too). */
    private function accountOrRedirect(Request $request, Tenant $tenant): CustomerAccount|Response
    {
        $account = $this->customerAccount($request);
        if ($account !== null) {
            return $account;
        }
        if ($request->method() === 'GET') {
            return $this->toSignIn($request, $tenant);
        }

        // A form posted after the session ended: back to the account home once signed in again (a POST address
        // is not a page that can be opened).
        $next = ShopRedirect::safeNext($this->shopUrl($request, $tenant, '/account'), $this->shopUrl($request, $tenant));

        return Response::redirect($this->shopUrl($request, $tenant, '/sign-in') . ($next === null ? '' : '?next=' . rawurlencode($next)));
    }

    /** The same plain 404 for an unknown, malformed or another customer's address id. */
    private function ownAddress(CustomerAccount $account, Request $request): ?array
    {
        $id = (string) $request->param('id', '');
        if (!Uuid::isValid($id)) {
            return null;
        }
        foreach ($this->services->customerAccounts()->listAddresses($account)['items'] as $address) {
            if ($address['id'] === $id) {
                return $address;
            }
        }

        return null;
    }

    /** @param callable(CustomerAccountService, CustomerAccount, string): mixed $action */
    private function addressAction(Request $request, string $confirmation, callable $action): Response
    {
        $tenant = $this->tenant($request);
        $account = $this->accountOrRedirect($request, $tenant);
        if ($account instanceof Response) {
            return $account;
        }

        $id = (string) $request->param('id', '');
        if (!Uuid::isValid($id)) {
            return $this->addressNotFound($request, $tenant);
        }

        try {
            $action($this->services->customerAccounts(), $account, $id);
        } catch (HttpException $exception) {
            if ($exception->status() === 404) {
                return $this->addressNotFound($request, $tenant);
            }
            throw $exception;
        }

        return $this->done($request, $tenant, $confirmation, '/account/addresses');
    }

    /** Remember the confirmation for the next page and go there (post, redirect, get). */
    private function done(Request $request, Tenant $tenant, string $confirmation, string $path): Response
    {
        $session = $request->attribute('session');
        if ($session instanceof Session && isset(self::FLASH[$confirmation])) {
            $session->put(self::FLASH_KEY, $confirmation);
        }

        return $this->noStore(Response::redirect($this->shopUrl($request, $tenant, $path)));
    }

    private function takeConfirmation(Request $request): ?string
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            return null;
        }

        $key = $session->get(self::FLASH_KEY);
        $session->forget(self::FLASH_KEY);

        return is_string($key) ? (self::FLASH[$key] ?? null) : null;
    }

    /** @return array<string, string> */
    private function addressInput(Request $request): array
    {
        $values = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $values[$field] = $this->text($request, $field);
        }

        return $values;
    }

    /** @return array<string, string> */
    private function blankAddress(): array
    {
        return array_fill_keys(self::ADDRESS_FIELDS, '');
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    private function addressValues(array $address): array
    {
        $values = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $values[$field] = (string) ($address[$field] ?? '');
        }

        return $values;
    }

    /** Input as a string; anything else (an array, nothing) becomes an empty string and fails validation. */
    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
