<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Settings, Discount rules (/{org}/admin/settings/discounts). Every action asks for reports_admin edit, so only
 * an Admin opens it. The controller reads the form, asks can(), calls DiscountRuleService and returns a response; the
 * rules (key format, percent range, deactivate instead of delete) live in DiscountRuleService.
 *
 * An edit form posts label, percent and the VAT-exempt box. A key that arrives anyway is handed to the service, which
 * refuses the save unless it equals the stored one; an "active" value is never read, because the flag changes only
 * through deactivate and reactivate.
 */
final class DiscountRulesController extends AdminPageController
{
    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->render($request, $tenant, $staff, 'settings-discounts', [
            'rules' => $services->discountRules()->list($staff, $tenant->organization),
            'rulesUrl' => $this->rulesUrl($request, $tenant),
            'newUrl' => $this->rulesUrl($request, $tenant, '/new'),
            'settingsUrl' => $this->adminUrl($request, $tenant, '/settings'),
        ]);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderNew($request, $tenant, $staff, ['key' => '', 'label' => '', 'percent' => '', 'vat_exempt' => '']);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $input = [
            'key' => $this->text($request, 'key'),
            'label' => $this->text($request, 'label'),
            'percent' => $this->text($request, 'percent'),
            'vat_exempt' => $this->text($request, 'vat_exempt'),
        ];

        try {
            $result = $services->discountRules()->create($staff, $tenant->organization, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderNew($request, $tenant, $staff, $input, $this->errorsOf($exception, 'key'), 422);
        }

        $this->flash($request, 'ok', 'Discount rule added.', 'It applies to sales from now on.');

        return Response::redirect($this->rulesUrl($request, $tenant, '/' . $result['id']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderRule($request, $tenant, $staff, $services, $this->id($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);
        $input = [
            'label' => $this->text($request, 'label'),
            'percent' => $this->text($request, 'percent'),
            'vat_exempt' => $this->text($request, 'vat_exempt'),
        ];
        if ($request->input('key') !== null) {
            $input['key'] = $this->text($request, 'key');
        }

        try {
            $services->discountRules()->update($staff, $tenant->organization, $id, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderRule($request, $tenant, $staff, $services, $id, $input, $this->errorsOf($exception, 'label'), 422);
        }

        $this->flash($request, 'ok', 'Discount rule saved.', 'Sales already recorded keep the amounts they were rung up with.');

        return Response::redirect($this->rulesUrl($request, $tenant, '/' . $id));
    }

    public function deactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->discountRules()->deactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Discount rule deactivated.', 'The POS no longer offers it. Nothing was deleted, and you can reactivate it any time.');

        return Response::redirect($this->rulesUrl($request, $tenant, '/' . $id));
    }

    public function reactivate(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $id = $this->id($request);

        try {
            $services->discountRules()->reactivate($staff, $tenant->organization, $id);
        } catch (HttpException $exception) {
            return $this->failedAction($request, $tenant, $staff, $services, $id, $exception);
        }

        $this->flash($request, 'ok', 'Discount rule reactivated.');

        return Response::redirect($this->rulesUrl($request, $tenant, '/' . $id));
    }

    /** A rule refused the action: 403 and 404 stay errors, a 422 comes back as a message on the rule's own page. */
    private function failedAction(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, HttpException $exception): Response
    {
        if ($exception->status() !== 422) {
            throw $exception;
        }

        $errors = $this->errorsOf($exception, 'action');

        return $this->renderRule($request, $tenant, $staff, $services, $id, [], ['action' => (string) reset($errors)], 422);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNew(Request $request, Tenant $tenant, StaffUser $staff, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $tenant, $staff, 'settings-discount-new', [
            'input' => $input,
            'errors' => $errors,
            'rulesUrl' => $this->rulesUrl($request, $tenant),
        ], $status);
    }

    /**
     * @param array<string, string> $input values from a refused save, which win over the stored ones
     * @param array<string, string> $errors
     */
    private function renderRule(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $id, array $input = [], array $errors = [], int $status = 200): Response
    {
        $rule = $services->discountRules()->get($staff, $tenant->organization, $id);

        return $this->render($request, $tenant, $staff, 'settings-discount', [
            'rule' => $rule,
            'input' => $input + [
                'label' => (string) $rule['label'],
                'percent' => (string) $rule['percent'],
                'vat_exempt' => (bool) $rule['vat_exempt'] ? '1' : '',
            ],
            'errors' => $errors,
            'ruleUrl' => $this->rulesUrl($request, $tenant, '/' . $id),
            'rulesUrl' => $this->rulesUrl($request, $tenant),
        ], $status);
    }

    private function rulesUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $this->adminUrl($request, $tenant, '/settings/discounts' . $path);
    }
}
