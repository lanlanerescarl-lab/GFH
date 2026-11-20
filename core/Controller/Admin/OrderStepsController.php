<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\OrderStepService;
use Gfh\Core\Support\SettingsSections;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Settings, Order steps (/{org}/admin/settings/order-steps). Every action asks for reports_admin edit, so only
 * an Admin opens it. The controller reads the form, asks can(), calls OrderStepService and returns a response; the
 * rules (open-order guard, referenced steps, the invariants of a working flow) live in OrderStepService.
 *
 * The labels below are only wording for the choices the service accepts.
 */
final class OrderStepsController extends AdminPageController
{
    private const MAPS_TO_LABELS = [
        'open' => 'Open (new, not started)',
        'in_progress' => 'In progress',
        'complete' => 'Complete',
    ];

    private const APPLIES_WHEN_LABELS = [
        'always' => 'Always: every order',
        'has_made_to_order' => 'Only orders with a made-to-order item',
        'is_delivery' => 'Only delivery orders',
        'is_pickup' => 'Only pickup orders',
        'has_assembly' => 'Only orders with an assembly fee',
        'has_installation' => 'Only orders with an installation fee',
    ];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->render($request, $tenant, $staff, 'settings-order-steps', [
            'steps' => $services->orderSteps()->list($staff, $tenant->organization),
            'openOrders' => $services->orderSteps()->openOrderCount($staff, $tenant->organization),
            'mapsToLabels' => self::MAPS_TO_LABELS,
            'appliesWhenLabels' => self::APPLIES_WHEN_LABELS,
            'stepsUrl' => $this->stepsUrl($request, $tenant),
            'newUrl' => $this->stepsUrl($request, $tenant, '/new'),
            'settingsUrl' => $this->adminUrl($request, $tenant, '/settings'),
        ]);
    }

    public function createForm(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderNew($request, $tenant, $staff, $services, [
            'key' => '', 'label' => '', 'sort_order' => '', 'maps_to' => 'in_progress', 'applies_when' => 'always', 'is_terminal' => '',
        ]);
    }

    public function store(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $input = [
            'key' => $this->text($request, 'key'),
            'label' => $this->text($request, 'label'),
            'sort_order' => $this->text($request, 'sort_order'),
            'maps_to' => $this->text($request, 'maps_to'),
            'applies_when' => $this->text($request, 'applies_when'),
            'is_terminal' => $this->text($request, 'is_terminal'),
        ];

        try {
            $result = $services->orderSteps()->create($staff, $tenant->organization, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderNew($request, $tenant, $staff, $services, $input, $this->errorsOf($exception, 'action'), 422);
        }

        $this->flash($request, 'ok', 'Order step added.');

        return Response::redirect($this->stepsUrl($request, $tenant, '/' . $result['key']));
    }

    public function show(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);

        return $this->renderStep($request, $tenant, $staff, $services, $this->key($request));
    }

    public function update(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $key = $this->key($request);
        $input = [
            'label' => $this->text($request, 'label'),
            'sort_order' => $this->text($request, 'sort_order'),
            'maps_to' => $this->text($request, 'maps_to'),
            'applies_when' => $this->text($request, 'applies_when'),
        ];
        // A switch always posts 0 or 1; a request without it keeps the stored value, so only a posted value changes it.
        if ($request->input('is_terminal') !== null) {
            $input['is_terminal'] = $this->text($request, 'is_terminal');
        }
        if ($request->input('key') !== null) {
            $input['key'] = $this->text($request, 'key');
        }

        try {
            $services->orderSteps()->update($staff, $tenant->organization, $key, $input);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }

            return $this->renderStep($request, $tenant, $staff, $services, $key, $input, $this->errorsOf($exception, 'action'), 422);
        }

        $this->flash($request, 'ok', 'Order step saved.');

        return Response::redirect($this->stepsUrl($request, $tenant, '/' . $key));
    }

    public function delete(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, SettingsSections::MODULE, SettingsSections::ACTION);
        $key = $this->key($request);

        try {
            $services->orderSteps()->delete($staff, $tenant->organization, $key);
        } catch (HttpException $exception) {
            if ($exception->status() !== 422) {
                throw $exception;
            }
            $errors = $this->errorsOf($exception, 'action');

            return $this->renderStep($request, $tenant, $staff, $services, $key, [], ['action' => (string) reset($errors)], 422);
        }

        $this->flash($request, 'ok', 'Order step deleted.');

        return Response::redirect($this->stepsUrl($request, $tenant));
    }

    /** The key from the URL. The router already limits its shape; this refuses anything else with a 404. */
    private function key(Request $request): string
    {
        $key = $request->param('key', '');
        if (preg_match(OrderStepService::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('That step could not be found.');
        }

        return $key;
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderNew(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, array $input, array $errors = [], int $status = 200): Response
    {
        return $this->render($request, $tenant, $staff, 'settings-order-step-new', [
            'input' => $input,
            'errors' => $errors,
            'openOrders' => $services->orderSteps()->openOrderCount($staff, $tenant->organization),
            'mapsToLabels' => self::MAPS_TO_LABELS,
            'appliesWhenLabels' => self::APPLIES_WHEN_LABELS,
            'stepsUrl' => $this->stepsUrl($request, $tenant),
        ], $status);
    }

    /**
     * @param array<string, string> $input values from a refused save, which win over the stored ones
     * @param array<string, string> $errors
     */
    private function renderStep(Request $request, Tenant $tenant, StaffUser $staff, TenantServices $services, string $key, array $input = [], array $errors = [], int $status = 200): Response
    {
        $step = $services->orderSteps()->get($staff, $tenant->organization, $key);

        return $this->render($request, $tenant, $staff, 'settings-order-step', [
            'step' => $step,
            'input' => $input + [
                'label' => (string) $step['label'],
                'sort_order' => (string) $step['sort_order'],
                'maps_to' => (string) $step['maps_to'],
                'applies_when' => (string) $step['applies_when'],
                'is_terminal' => (bool) $step['is_terminal'] ? '1' : '0',
            ],
            'errors' => $errors,
            'openOrders' => $services->orderSteps()->openOrderCount($staff, $tenant->organization),
            'mapsToLabels' => self::MAPS_TO_LABELS,
            'appliesWhenLabels' => self::APPLIES_WHEN_LABELS,
            'stepUrl' => $this->stepsUrl($request, $tenant, '/' . $key),
            'stepsUrl' => $this->stepsUrl($request, $tenant),
        ], $status);
    }

    private function stepsUrl(Request $request, Tenant $tenant, string $path = ''): string
    {
        return $this->adminUrl($request, $tenant, '/settings/order-steps' . $path);
    }
}
