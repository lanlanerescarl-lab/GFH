<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Tenant;

/**
 * Shared base for Staff/Admin HTML page controllers.
 * Every action checks PermissionService::can() via authorize(), renders through AppView with the Admin layout,
 * and carries the session CSRF token and flash notices.
 */
abstract class AdminPageController
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form allows.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no place to keep the upload.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload.',
    ];

    /** @param (\Closure(string): bool)|null $uploadVerifier test-only verifier; web requests always use is_uploaded_file(). */
    public function __construct(
        protected readonly Services $services,
        private readonly ?\Closure $uploadVerifier = null,
    ) {
    }

    /**
     * @return array{staff: StaffUser, tenant: Tenant, services: TenantServices}
     * @throws HttpException 401 when not signed in, 403 when not permitted
     */
    protected function authorize(Request $request, string $module, string $action): array
    {
        $tenant = $this->tenant($request);
        $staff = $this->staff($request);
        $this->services->permissions()->assert($staff, $tenant->organization, $module, $action);

        return [
            'staff' => $staff,
            'tenant' => $tenant,
            'services' => $this->services->forTenant($tenant),
        ];
    }

    protected function can(StaffUser $staff, Tenant $tenant, string $module, string $action): bool
    {
        return $this->services->permissions()->can($staff, $tenant->organization, $module, $action);
    }

    /** @param array<string, mixed> $data */
    protected function render(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        string $template,
        array $data = [],
        int $status = 200,
    ): Response {
        $currentPath = $request->basePath() . $request->path();
        $navigation = $this->services->adminNavigation($tenant)
            ->items($staff, $tenant->organization, $request->basePath(), $currentPath);

        $session = $request->attribute('session');
        $csrf = '';
        $flash = null;
        if ($session instanceof Session) {
            $csrf = $this->services->csrf($session)->token();
            $storedFlash = $session->get('_admin_flash');
            if (is_array($storedFlash)) {
                $flash = $storedFlash;
                $session->forget('_admin_flash');
            }
        }

        $view = $this->services->appView($tenant, 'admin', $request, [
            'navigation' => $navigation,
            'staffName' => $staff->fullName,
            'staffRole' => (string) $tenant->role(),
            'logoutUrl' => $this->adminUrl($request, $tenant, '/logout'),
            'csrf' => $csrf,
            'flash' => $flash,
        ]);

        return Response::html($view->render($template, $data), $status);
    }

    protected function flash(Request $request, string $tone, string $title, string $message = ''): void
    {
        $session = $request->attribute('session');
        if ($session instanceof Session) {
            $session->put('_admin_flash', [
                'tone' => $tone,
                'title' => $title,
                'message' => $message,
            ]);
        }
    }

    protected function adminUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $request->basePath() . '/' . $tenant->organization->slug . '/admin' . $path;
    }

    protected function safeReturnUrl(Request $request, Tenant $tenant, string $candidate, string $fallbackPath): string
    {
        $fallback = $this->adminUrl($request, $tenant, $fallbackPath);
        $prefix = $this->adminUrl($request, $tenant);
        $safe = preg_match('#^/[A-Za-z0-9/_\-.?=&%]*$#', $candidate) === 1
            && !str_contains($candidate, '//')
            && ($candidate === $prefix || str_starts_with($candidate, $prefix . '/') || str_starts_with($candidate, $prefix . '?'));

        return $safe ? $candidate : $fallback;
    }

    protected function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? $value : $default;
    }

    protected function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    protected function id(Request $request, string $param = 'id'): string
    {
        $id = $request->param($param, '');
        if (!Uuid::isValid($id)) {
            throw HttpException::notFound('That record could not be found.');
        }

        return $id;
    }

    /**
     * The field errors of a 422, or the message on a fallback field when the exception has none.
     * Anything that is not a client error (5xx) is not turned into a form message: it is thrown again.
     *
     * @return array<string, string>
     */
    protected function errorsOf(HttpException $exception, string $fallbackField): array
    {
        if ($exception->status() >= 500) {
            throw $exception;
        }

        return $exception->fields() !== [] ? $exception->fields() : [$fallbackField => $exception->getMessage()];
    }

    /**
     * Reads one posted list of rows, for example lines[0][variant_id]. Only arrays of arrays of text are kept.
     *
     * @return list<array<string, string>>
     */
    protected function postedRows(Request $request, string $key): array
    {
        $raw = $request->body()[$key] ?? [];
        $rows = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $field => $value) {
                if (is_string($value)) {
                    $clean[(string) $field] = trim($value);
                }
            }
            $rows[] = $clean;
        }

        return array_slice($rows, 0, 100);
    }

    /**
     * Validates and stores one or more multipart image uploads via MediaService.
     *
     * @return list<array{id: string, mime: string, width: int, height: int, bytes: int}>
     * @throws HttpException 422 with a field error on $field
     */
    protected function uploadImages(
        Request $request,
        TenantServices $services,
        StaffUser $staff,
        string $field = 'file',
        ?string $altText = null,
    ): array {
        $entry = $request->files()[$field] ?? null;
        if (!is_array($entry) || !isset($entry['tmp_name'], $entry['error'])) {
            throw HttpException::unprocessable([$field => 'Choose an image to upload.']);
        }

        $items = [];
        if (is_array($entry['tmp_name'])) {
            $errors = is_array($entry['error']) ? $entry['error'] : [];
            $names = is_array($entry['name'] ?? null) ? $entry['name'] : [];
            foreach ($entry['tmp_name'] as $index => $tmp) {
                $err = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE && (string) $tmp === '') {
                    continue;
                }
                $items[] = ['tmp_name' => (string) $tmp, 'error' => $err, 'name' => (string) ($names[$index] ?? 'upload')];
            }
        } else {
            if ((int) $entry['error'] !== UPLOAD_ERR_NO_FILE || (string) $entry['tmp_name'] !== '') {
                $items[] = [
                    'tmp_name' => (string) $entry['tmp_name'],
                    'error' => (int) $entry['error'],
                    'name' => (string) ($entry['name'] ?? 'upload'),
                ];
            }
        }

        if ($items === []) {
            throw HttpException::unprocessable([$field => 'Choose an image to upload.']);
        }

        $verify = $this->uploadVerifier ?? static fn (string $path): bool => is_uploaded_file($path);
        $uploaded = [];
        foreach ($items as $item) {
            if ($item['error'] !== UPLOAD_ERR_OK) {
                throw HttpException::unprocessable([$field => self::UPLOAD_ERRORS[$item['error']] ?? 'The upload failed.']);
            }
            if (!$verify($item['tmp_name'])) {
                throw HttpException::unprocessable([$field => 'The upload could not be verified.']);
            }
            try {
                $uploaded[] = $services->media()->upload($item['tmp_name'], $item['name'], $altText, $staff->id);
            } catch (HttpException $exception) {
                $message = $exception->fields()['file'] ?? $exception->getMessage();
                throw HttpException::unprocessable([$field => $message]);
            }
        }

        return $uploaded;
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \LogicException('This page needs TenantMiddleware.');
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
}
