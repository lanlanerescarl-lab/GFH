<?php

declare(strict_types=1);

namespace Gfh\Core\Controller;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\OrganizationCustomerRepository;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;

/**
 * Plain server-rendered pages for staff sign-in, the signed-in landing page, the reset link screen and the
 * public "choose a new password" page. They use the core page styles for now; the Admin template (Stages 4 and 5)
 * replaces their look but keeps the same routes and services.
 */
final class StaffPagesController
{
    public function __construct(private readonly Services $services)
    {
    }

    public function loginForm(Request $request): Response
    {
        $staff = $request->attribute('staff');
        $tenant = $this->tenant($request);

        // Already signed in with access: go straight on.
        if ($staff instanceof StaffUser && $this->services->permissions()->activeMembership($staff, $tenant->organization) !== null) {
            return Response::redirect($this->next($request, $tenant, $staff));
        }

        return $this->loginPage($request, $tenant);
    }

    public function loginSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);

        try {
            $user = $this->services->auth()->authenticateStaff($this->text($request, 'email'), $this->text($request, 'password'), $request->ip());
            $this->services->tenants()->authorizeStaff($tenant, $user);
        } catch (HttpException $exception) {
            return $this->loginPage($request, $tenant, $exception->getMessage(), $exception->status());
        }

        $session = $this->session($request);
        $session->regenerate();
        $session->put('user_id', $user->id);
        $this->services->csrf($session)->rotate();

        return Response::redirect($this->next($request, $tenant, $user));
    }

    public function logout(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $this->session($request)->destroy();

        return Response::redirect($request->basePath() . '/' . $tenant->organization->slug . '/admin/login');
    }

    public function account(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $staff = $this->staff($request);

        return $this->page('account', $request, [
            'organization' => $tenant->organization->name,
            'name' => $staff->fullName,
            'email' => $staff->email,
            'role' => (string) $tenant->role(),
            'canIssueLinks' => $this->services->permissions()->can($staff, $tenant->organization, 'reports_admin', 'edit'),
            'logoutUrl' => $this->url($request, $tenant, '/admin/logout'),
            'resetLinksUrl' => $this->url($request, $tenant, '/admin/reset-links'),
            'csrf' => $this->csrfToken($request),
        ]);
    }

    /** Signed-in staff page showing every Admin component and form control in every state. */
    public function uiKit(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $staff = $this->staff($request);
        $this->services->permissions()->assert($staff, $tenant->organization, 'dashboard', 'view');

        $currentPath = $request->basePath() . $request->path();
        $navigation = $this->services->adminNavigation($tenant)->items($staff, $tenant->organization, $request->basePath(), $currentPath);
        $view = $this->services->appView($tenant, 'admin', $request, [
            'navigation' => $navigation,
            'staffName' => $staff->fullName,
            'staffRole' => (string) $tenant->role(),
            'logoutUrl' => $this->url($request, $tenant, '/admin/logout'),
            'csrf' => $this->csrfToken($request),
        ]);

        return Response::html($view->render('ui-kit'));
    }

    public function resetLinksForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $this->services->permissions()->assert($this->staff($request), $tenant->organization, 'reports_admin', 'edit');

        return $this->resetLinksPage($request, $tenant);
    }

    public function resetLinksSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $staff = $this->staff($request);
        $this->services->permissions()->assert($staff, $tenant->organization, 'reports_admin', 'edit');

        $type = $this->text($request, 'type');
        $email = $this->text($request, 'email');

        try {
            $link = match ($type) {
                'staff' => $this->services->resets()->issueForStaff($staff, $tenant->organization, $email),
                'customer' => $this->services->resets()->issueForCustomer($staff, $tenant->organization, new OrganizationCustomerRepository($tenant->database()), $email),
                default => throw HttpException::unprocessable(['type' => 'Choose staff or customer.']),
            };
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            return $this->resetLinksPage($request, $tenant, null, $exception->fields()['email'] ?? $exception->getMessage(), $type, $email, $exception->status());
        }

        return $this->resetLinksPage($request, $tenant, $link, null, $type, $email);
    }

    /** Public page reached from a reset link. The token in the link is the proof; there is nothing to forge. */
    public function resetPasswordForm(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $token = $request->query()['token'] ?? '';

        return $this->resetPasswordPage($request, $tenant, is_string($token) ? $token : '');
    }

    public function resetPasswordSubmit(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $token = $this->text($request, 'token');
        $password = $this->text($request, 'password');

        if ($password !== $this->text($request, 'password_confirm')) {
            return $this->resetPasswordPage($request, $tenant, $token, 'The two passwords do not match.', 422);
        }

        try {
            $result = $this->services->resets()->consume($token, $password);
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }
            $message = $exception->fields()['password'] ?? $exception->fields()['token'] ?? $exception->getMessage();

            return $this->resetPasswordPage($request, $tenant, $token, $message, $exception->status());
        }

        return $this->page('reset_password_done', $request, [
            'loginUrl' => $this->url($request, $tenant, '/admin/login'),
            'shopSignInUrl' => $this->url($request, $tenant, '/shop/sign-in'),
            'isStaff' => $result['subject_type'] === 'user',
        ]);
    }

    private function loginPage(Request $request, Tenant $tenant, ?string $error = null, int $status = 200): Response
    {
        return $this->page('login', $request, [
            'organization' => $tenant->organization->name,
            'action' => $this->url($request, $tenant, '/admin/login'),
            'next' => $this->explicitNext($request, $tenant),
            'error' => $error,
            'email' => $this->text($request, 'email'),
            'csrf' => $this->csrfToken($request),
        ], $status);
    }

    /** @param array{url: string, expires_at: string}|null $link */
    private function resetLinksPage(Request $request, Tenant $tenant, ?array $link = null, ?string $error = null, string $type = 'staff', string $email = '', int $status = 200): Response
    {
        return $this->page('reset_links', $request, [
            'organization' => $tenant->organization->name,
            'action' => $this->url($request, $tenant, '/admin/reset-links'),
            'accountUrl' => $this->url($request, $tenant, '/admin/account'),
            'link' => $link,
            'error' => $error,
            'type' => $type,
            'email' => $email,
            'csrf' => $this->csrfToken($request),
        ], $status);
    }

    private function resetPasswordPage(Request $request, Tenant $tenant, string $token, ?string $error = null, int $status = 200): Response
    {
        return $this->page('reset_password', $request, [
            'organization' => $tenant->organization->name,
            'action' => $this->url($request, $tenant, '/reset-password'),
            'token' => $token,
            'error' => $error,
        ], $status);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, Request $request, array $data, int $status = 200): Response
    {
        $data['cssUrl'] = $request->basePath() . '/assets/css/error.css';

        return Response::html($this->services->app()->view()->render($view, $data), $status);
    }

    private function url(Request $request, Tenant $tenant, string $path): string
    {
        return $request->basePath() . '/' . $tenant->organization->slug . $path;
    }

    /**
     * Where to go after sign-in. Only a path inside this organization's admin area is accepted,
     * so a crafted link cannot send someone to another site.
     */
    private function next(Request $request, Tenant $tenant, ?StaffUser $user = null): string
    {
        $explicit = $this->explicitNext($request, $tenant);
        if ($explicit !== '') {
            return $explicit;
        }

        if ($user !== null && $this->services->permissions()->can($user, $tenant->organization, 'dashboard', 'view')) {
            return $this->url($request, $tenant, '/admin');
        }

        return $this->url($request, $tenant, '/admin/account');
    }

    private function explicitNext(Request $request, Tenant $tenant): string
    {
        $candidate = $request->query()['next'] ?? $request->body()['next'] ?? '';
        if (!is_string($candidate) || $candidate === '') {
            return '';
        }

        $prefix = $this->url($request, $tenant, '/admin');
        $safe = preg_match('#^/[A-Za-z0-9/_\-.?=&%]*$#', $candidate) === 1
            && !str_contains($candidate, '//')
            && ($candidate === $prefix || str_starts_with($candidate, $prefix . '/') || str_starts_with($candidate, $prefix . '?'));

        return $safe ? $candidate : '';
    }

    private function csrfToken(Request $request): string
    {
        return $this->services->csrf($this->session($request))->token();
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('This page needs SessionMiddleware.');
        }

        return $session;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This page needs TenantMiddleware.');
        }

        return $tenant;
    }

    private function staff(Request $request): StaffUser
    {
        $staff = $request->attribute('staff');
        if (!$staff instanceof StaffUser) {
            throw HttpException::unauthorized();
        }

        return $staff;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
