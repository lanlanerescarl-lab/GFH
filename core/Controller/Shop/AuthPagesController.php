<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Shop;

use Gfh\Core\Auth\CustomerSessionStarter;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Tenancy\Tenant;

/**
 * The Shop's customer sign-in, registration and sign-out pages. They call the same AuthService as the JSON API, so
 * the lockout, the single message for a wrong email or password, the audit entries and the session id change all come
 * from that code. This class only reads the form, shows the result and chooses where to go next.
 */
final class AuthPagesController extends ShopPageController
{
    /** GET /{org}/shop/sign-in. A customer who is already signed in is sent on. */
    public function signInForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        if ($this->customerAccount($request) !== null) {
            return Response::redirect($this->nextOrHome($request, $tenant));
        }

        return $this->signInPage($request, $tenant);
    }

    /** POST /{org}/shop/sign-in. */
    public function signInSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);

        try {
            $account = $this->services->auth()->authenticateCustomer(
                $this->text($request, 'email'),
                $this->text($request, 'password'),
                $request->ip(),
                new OrganizationCustomerRepository($tenant->database()),
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            // 401 (one message for an unknown email and a wrong password) and 429 (locked out, with Retry-After).
            $response = $this->signInPage($request, $tenant, $exception->getMessage(), $exception->status());
            foreach ($exception->headers() as $name => $value) {
                $response = $response->withHeader((string) $name, (string) $value);
            }

            return $response;
        }

        $session = $this->session($request);
        CustomerSessionStarter::start($session, $this->services->csrf($session), $account);

        return Response::redirect($this->nextOrHome($request, $tenant));
    }

    /** GET /{org}/shop/register. */
    public function registerForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        if ($this->customerAccount($request) !== null) {
            return Response::redirect($this->nextOrHome($request, $tenant));
        }

        return $this->registerPage($request, $tenant);
    }

    /** POST /{org}/shop/register. Registering also signs the new customer in. */
    public function registerSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);

        // The confirmation box is a typing check on this form only; every other rule is AuthService's.
        if ($this->text($request, 'password') !== $this->text($request, 'password_confirmation')) {
            return $this->registerPage($request, $tenant, ['password_confirmation' => 'The two passwords do not match.'], 422);
        }

        try {
            $account = $this->services->auth()->registerCustomer(
                $this->text($request, 'full_name'),
                $this->text($request, 'email'),
                $this->text($request, 'password'),
                $this->text($request, 'phone'),
                new OrganizationCustomerRepository($tenant->database()),
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            if ($exception->status() !== 422 && $exception->status() !== 409) {
                throw $exception;
            }

            // 422: a message per field. 409: this email already has an account (AuthService's own wording, unchanged).
            $fields = $exception->fields();
            if ($fields === []) {
                $fields = ['form' => $exception->getMessage()];
            }
            if ($exception->status() === 409) {
                $fields['form'] = $exception->getMessage();
            }

            return $this->registerPage($request, $tenant, $fields, $exception->status());
        }

        $session = $this->session($request);
        CustomerSessionStarter::start($session, $this->services->csrf($session), $account);

        return Response::redirect($this->nextOrHome($request, $tenant));
    }

    /** POST /{org}/shop/sign-out. */
    public function signOut(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $this->session($request)->destroy();

        return Response::redirect($this->shopUrl($request, $tenant));
    }

    private function signInPage(Request $request, Tenant $tenant, ?string $error = null, int $status = 200): Response
    {
        return $this->noStore($this->render($request, $tenant, 'sign-in', [
            'action' => $this->shopUrl($request, $tenant, '/sign-in'),
            'registerUrl' => $this->shopUrl($request, $tenant, '/register'),
            'next' => $this->safeNextValue($request, $tenant),
            'email' => $this->text($request, 'email'),
            'error' => $error,
        ], $status));
    }

    /** @param array<string, string> $errors */
    private function registerPage(Request $request, Tenant $tenant, array $errors = [], int $status = 200): Response
    {
        return $this->noStore($this->render($request, $tenant, 'register', [
            'action' => $this->shopUrl($request, $tenant, '/register'),
            'signInUrl' => $this->shopUrl($request, $tenant, '/sign-in'),
            'next' => $this->safeNextValue($request, $tenant),
            'errors' => $errors,
            'values' => [
                'full_name' => $this->text($request, 'full_name'),
                'email' => $this->text($request, 'email'),
                'phone' => $this->text($request, 'phone'),
            ],
        ], $status));
    }

    /** The "next" to put back in the form (only an accepted one), so it survives a failed attempt. */
    private function safeNextValue(Request $request, Tenant $tenant): string
    {
        $next = $this->nextOrHome($request, $tenant);

        return $next === $this->shopUrl($request, $tenant) ? '' : $next;
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('This page needs SessionMiddleware.');
        }

        return $session;
    }

    /** Input as a string; anything else (an array, nothing) becomes an empty string and fails validation. */
    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
