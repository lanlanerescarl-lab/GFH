<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\SuperAdmin;

use Gfh\Core\Auth\Modules;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\SuperAdminRedirect;

/**
 * Super Admin sign-in and sign-out controller (/superadmin/sign-in, /superadmin/sign-out).
 * Uses the shared staff session scope (gfh_staff) and AuthService::authenticateStaff through
 * SuperAdminAuthService so lockout and error wording stay identical for every failure case.
 */
final class AuthController
{
    public function __construct(private readonly Services $services)
    {
    }

    /** GET /superadmin/sign-in */
    public function signInForm(Request $request): Response
    {
        $staff = $request->attribute('staff');
        if (
            $staff instanceof StaffUser
            && $staff->isActive()
            && in_array($staff->orgRole, Modules::GROUP_ROLES, true)
            && $this->services->permissions()->can($staff, null, 'overview', 'view')
        ) {
            return Response::redirect($this->next($request));
        }

        return $this->signInPage($request);
    }

    /** POST /superadmin/sign-in */
    public function signInSubmit(Request $request): Response
    {
        try {
            $user = $this->services->superAdminAuth()->authenticate(
                $this->text($request, 'email'),
                $this->text($request, 'password'),
                $request->ip(),
            );
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            $response = $this->signInPage($request, $exception->getMessage(), $exception->status());
            foreach ($exception->headers() as $name => $value) {
                $response = $response->withHeader((string) $name, (string) $value);
            }

            return $response;
        }

        $session = $this->session($request);
        $session->regenerate();
        $session->put('user_id', $user->id);
        $this->services->csrf($session)->rotate();

        return Response::redirect($this->next($request));
    }

    /** POST /superadmin/sign-out */
    public function signOut(Request $request): Response
    {
        $this->session($request)->destroy();

        return Response::redirect($request->basePath() . '/superadmin/sign-in');
    }

    private function signInPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        $view = $this->services->superAdminView($request);

        return Response::html($view->render('sign-in', [
            'action' => $request->basePath() . '/superadmin/sign-in',
            'next' => $this->explicitNext($request),
            'email' => $this->text($request, 'email'),
            'error' => $error,
            'csrf' => $this->services->csrf($this->session($request))->token(),
        ]), $status, ['Cache-Control' => 'no-store']);
    }

    private function next(Request $request): string
    {
        $explicit = $this->explicitNext($request);

        return $explicit !== '' ? $explicit : $request->basePath() . '/superadmin';
    }

    private function explicitNext(Request $request): string
    {
        $candidate = $request->query()['next'] ?? $request->body()['next'] ?? '';
        $safe = SuperAdminRedirect::safeNext($candidate, $request->basePath() . '/superadmin');

        return $safe ?? '';
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('This page needs SessionMiddleware.');
        }

        return $session;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
