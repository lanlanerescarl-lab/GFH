<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Inventory screens: stock by branch and variant, movement ledger, adjustments, write-offs and reorder suggestions.
 */
final class InventoryController extends AdminPageController
{
    public function levels(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'inventory', 'view');

        return $this->renderLevels($request, $tenant, $staff, $services);
    }

    public function adjust(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'inventory', 'edit');

        $input = [
            'branch_id' => $this->text($request, 'branch_id'),
            'variant_id' => $this->text($request, 'variant_id'),
            'delta' => $this->text($request, 'delta'),
            'reason' => $this->text($request, 'reason'),
        ];

        try {
            $services->inventory()->adjust($input['branch_id'], $input['variant_id'], $input['delta'], $input['reason'], $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            return $this->renderLevels(
                $request,
                $tenant,
                $staff,
                $services,
                adjustInput: $input,
                adjustErrors: $exception->fields() ?: ['delta' => $exception->getMessage()],
                status: $exception->status(),
            );
        }

        $this->flash($request, 'ok', 'Stock adjustment recorded', 'The movement was appended to the stock ledger.');

        return Response::redirect($this->safeReturnUrl($request, $tenant, $this->text($request, 'return_to'), '/inventory'));
    }

    public function writeOff(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'inventory', 'edit');

        $input = [
            'branch_id' => $this->text($request, 'branch_id'),
            'variant_id' => $this->text($request, 'variant_id'),
            'quantity' => $this->text($request, 'quantity'),
            'reason' => $this->text($request, 'reason'),
        ];

        try {
            $services->inventory()->writeOff($input['branch_id'], $input['variant_id'], $input['quantity'], $input['reason'], $staff->id);
        } catch (HttpException $exception) {
            if ($exception->status() >= 500) {
                throw $exception;
            }

            return $this->renderLevels(
                $request,
                $tenant,
                $staff,
                $services,
                writeOffInput: $input,
                writeOffErrors: $exception->fields() ?: ['quantity' => $exception->getMessage()],
                status: $exception->status(),
            );
        }

        $this->flash($request, 'ok', 'Stock write-off recorded', 'The write-off was appended to the stock ledger.');

        return Response::redirect($this->safeReturnUrl($request, $tenant, $this->text($request, 'return_to'), '/inventory'));
    }

    public function ledger(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'inventory', 'view');

        $query = ListQuery::fromRequest(
            $request,
            ['time' => 'm.occurred_at', 'sku' => 'v.sku', 'product' => 'p.name', 'type' => 'm.movement_type'],
            '-time',
            ['branch_id', 'variant_id', 'type', 'batch_id', 'from', 'to'],
            ['branch_id', 'variant_id', 'batch_id'],
            ['from', 'to'],
        );

        $inventory = $services->inventory();

        return $this->render($request, $tenant, $staff, 'inventory-ledger', [
            'result' => $inventory->ledger($query),
            'query' => $query,
            'branches' => $inventory->branches(),
            'variants' => $inventory->variantsForSelect(),
            'baseUrl' => $this->adminUrl($request, $tenant, '/inventory'),
            'ledgerUrl' => $this->adminUrl($request, $tenant, '/inventory/ledger'),
            'reorderUrl' => $this->adminUrl($request, $tenant, '/inventory/reorder'),
            'canEdit' => $this->can($staff, $tenant, 'inventory', 'edit'),
            'activeTab' => 'ledger',
            'filters' => [
                'q' => $query->search ?? '',
                'branch_id' => (string) ($query->filter('branch_id') ?? ''),
                'variant_id' => (string) ($query->filter('variant_id') ?? ''),
                'type' => (string) ($query->filter('type') ?? ''),
                'from' => (string) ($query->filter('from') ?? ''),
                'to' => (string) ($query->filter('to') ?? ''),
                'per_page' => (string) $query->perPage,
            ],
        ]);
    }

    public function reorder(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'inventory', 'view');

        $query = ListQuery::fromRequest(
            $request,
            [
                'suggested_qty' => 'suggested_qty',
                'product' => 'product_name',
                'sku' => 'sku',
                'on_hand' => 'on_hand',
                'available' => 'available',
                'reorder_level' => 'reorder_level',
                'supplier' => "COALESCE(supplier_name, '')",
            ],
            '-suggested_qty',
            ['supplier_id', 'status'],
            ['supplier_id'],
        );

        $inventory = $services->inventory();

        return $this->render($request, $tenant, $staff, 'inventory-reorder', [
            'result' => $inventory->reorderSuggestions($query),
            'query' => $query,
            'suppliers' => $inventory->reorderSuppliers(),
            'baseUrl' => $this->adminUrl($request, $tenant, '/inventory'),
            'ledgerUrl' => $this->adminUrl($request, $tenant, '/inventory/ledger'),
            'reorderUrl' => $this->adminUrl($request, $tenant, '/inventory/reorder'),
            'procurementUrl' => $this->can($staff, $tenant, 'procurement', 'edit') ? $this->adminUrl($request, $tenant, '/procurement') : null,
            'activeTab' => 'reorder',
            'filters' => [
                'q' => $query->search ?? '',
                'supplier_id' => (string) ($query->filter('supplier_id') ?? ''),
                'status' => (string) ($query->filter('status') ?? ''),
                'per_page' => (string) $query->perPage,
            ],
        ]);
    }

    /**
     * @param array<string, string> $adjustInput
     * @param array<string, string> $adjustErrors
     * @param array<string, string> $writeOffInput
     * @param array<string, string> $writeOffErrors
     */
    private function renderLevels(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        array $adjustInput = [],
        array $adjustErrors = [],
        array $writeOffInput = [],
        array $writeOffErrors = [],
        int $status = 200,
    ): Response {
        $query = ListQuery::fromRequest(
            $request,
            ['product' => 'p.name', 'sku' => 'v.sku', 'branch' => 'b.name', 'on_hand' => 'l.on_hand', 'available' => 'available'],
            'product',
            ['branch_id', 'variant_id', 'low_stock'],
            ['branch_id', 'variant_id'],
        );

        $inventory = $services->inventory();
        $activeBranches = $inventory->branches(true);
        $allBranches = $inventory->branches(false);
        $variants = $inventory->variantsForSelect();

        $prefillBranch = $this->queryText($request, 'prefill_branch');
        $prefillVariant = $this->queryText($request, 'prefill_variant');
        if (!Uuid::isValid($prefillBranch)) {
            $prefillBranch = (string) ($activeBranches[0]['id'] ?? '');
        }
        if (!Uuid::isValid($prefillVariant)) {
            $prefillVariant = (string) ($variants[0]['id'] ?? '');
        }

        $adjustInput += [
            'branch_id' => $prefillBranch,
            'variant_id' => $prefillVariant,
            'delta' => '',
            'reason' => '',
        ];
        $writeOffInput += [
            'branch_id' => $prefillBranch,
            'variant_id' => $prefillVariant,
            'quantity' => '',
            'reason' => '',
        ];

        $levelsUrl = $this->adminUrl($request, $tenant, '/inventory');

        return $this->render($request, $tenant, $staff, 'inventory', [
            'result' => $inventory->levels($query),
            'query' => $query,
            'branches' => $allBranches,
            'activeBranches' => $activeBranches,
            'variants' => $variants,
            'baseUrl' => $levelsUrl,
            'ledgerUrl' => $this->adminUrl($request, $tenant, '/inventory/ledger'),
            'reorderUrl' => $this->adminUrl($request, $tenant, '/inventory/reorder'),
            'adjustAction' => $this->adminUrl($request, $tenant, '/inventory/adjustments'),
            'writeOffAction' => $this->adminUrl($request, $tenant, '/inventory/write-offs'),
            'returnTo' => $levelsUrl,
            'canEdit' => $this->can($staff, $tenant, 'inventory', 'edit'),
            'activeTab' => 'levels',
            'adjustInput' => $adjustInput,
            'adjustErrors' => $adjustErrors,
            'writeOffInput' => $writeOffInput,
            'writeOffErrors' => $writeOffErrors,
            'filters' => [
                'q' => $query->search ?? '',
                'branch_id' => (string) ($query->filter('branch_id') ?? ''),
                'variant_id' => (string) ($query->filter('variant_id') ?? ''),
                'low_stock' => filter_var($query->filter('low_stock'), FILTER_VALIDATE_BOOLEAN) ? '1' : '',
                'per_page' => (string) $query->perPage,
            ],
        ], $status);
    }
}
