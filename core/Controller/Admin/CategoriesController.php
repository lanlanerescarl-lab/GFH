<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Tenancy\Tenant;

/**
 * Admin Category management controller: two-level categories with create, rename, archive and restore.
 * Edit permission allows create and rename; delete permission allows archive and restore.
 */
final class CategoriesController extends AdminPageController
{
    public function index(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'view');

        return $this->renderIndex($request, $auth['tenant'], $auth['staff'], $auth['services']);
    }

    public function store(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $parentId = trim($this->text($request, 'parent_id'));
        $name = $this->text($request, 'name');

        try {
            $auth['services']->catalog()->createCategory($parentId === '' ? null : $parentId, $name, $auth['staff']->id);
            $this->flash($request, 'ok', 'Category created.', $name . ' is ready for products.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/categories'));
        } catch (HttpException $exception) {
            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['createInput' => ['parent_id' => $parentId, 'name' => $name], 'createErrors' => $this->fieldErrors($exception)],
                $exception->status(),
            );
        }
    }

    public function rename(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $name = $this->text($request, 'name');

        try {
            $auth['services']->catalog()->renameCategory($id, $name, $auth['staff']->id);
            $this->flash($request, 'ok', 'Category renamed.', 'Updated to ' . $name . '.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/categories'));
        } catch (HttpException $exception) {
            $msg = $exception->fields()['name'] ?? $exception->getMessage();

            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['rowInputs' => [$id => ['name' => $name]], 'rowErrors' => [$id => $msg]],
                $exception->status(),
            );
        }
    }

    public function archive(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'delete');
        $id = $this->id($request);

        try {
            $auth['services']->catalog()->archiveCategory($id, $auth['staff']->id);
            $this->flash($request, 'ok', 'Category archived.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/categories'));
        } catch (HttpException $exception) {
            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['rowErrors' => [$id => $exception->getMessage()]],
                $exception->status(),
            );
        }
    }

    public function restore(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'delete');
        $id = $this->id($request);

        try {
            $auth['services']->catalog()->restoreCategory($id, $auth['staff']->id);
            $this->flash($request, 'ok', 'Category restored.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/categories'));
        } catch (HttpException $exception) {
            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['rowErrors' => [$id => $exception->getMessage()]],
                $exception->status(),
            );
        }
    }

    /** @param array<string, mixed> $state */
    private function renderIndex(
        Request $request,
        Tenant $tenant,
        StaffUser $staff,
        TenantServices $services,
        array $state = [],
        int $status = 200,
    ): Response {
        $query = ListQuery::fromRequest($request, ['name' => 'name', 'status' => 'status'], 'name');
        $includeArchived = $this->queryText($request, 'archived') === '1';
        $catalog = $services->catalog();

        $topCategories = $catalog->categoryTree(false);
        $tree = $catalog->categoryTree($includeArchived);
        $flat = [];
        foreach ($tree as $top) {
            $flat[] = $top + ['depth' => 0, 'parent_name' => ''];
            foreach ($top['children'] ?? [] as $child) {
                $flat[] = $child + ['depth' => 1, 'parent_name' => (string) $top['name']];
            }
        }

        if ($query->search !== null) {
            $needle = mb_strtolower($query->search);
            $flat = array_values(array_filter(
                $flat,
                static fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), $needle)
                    || str_contains(mb_strtolower((string) $row['slug']), $needle)
                    || str_contains(mb_strtolower((string) $row['parent_name']), $needle),
            ));
        }

        $desc = str_starts_with($query->sortLabel, '-');
        $sortKey = ltrim($query->sortLabel, '-');
        if ($query->sortLabel !== 'name') {
            usort($flat, static function (array $a, array $b) use ($sortKey, $desc): int {
                $cmp = strcasecmp((string) ($a[$sortKey] ?? ''), (string) ($b[$sortKey] ?? ''));
                if ($cmp === 0) {
                    $cmp = strcasecmp((string) $a['name'], (string) $b['name']);
                }

                return $desc ? -$cmp : $cmp;
            });
        }

        $total = count($flat);
        $items = array_slice($flat, $query->offset(), $query->perPage);
        $result = $query->result($items, $total);

        return $this->render($request, $tenant, $staff, 'categories', [
            'result' => $result,
            'query' => $query,
            'topCategories' => $topCategories,
            'createInput' => $state['createInput'] ?? [],
            'createErrors' => $state['createErrors'] ?? [],
            'rowInputs' => $state['rowInputs'] ?? [],
            'rowErrors' => $state['rowErrors'] ?? [],
            'canEdit' => $this->can($staff, $tenant, 'products', 'edit'),
            'canDelete' => $this->can($staff, $tenant, 'products', 'delete'),
            'activeTab' => 'categories',
            'productsUrl' => $this->adminUrl($request, $tenant, '/products'),
            'brandsUrl' => $this->adminUrl($request, $tenant, '/brands'),
            'categoriesUrl' => $this->adminUrl($request, $tenant, '/categories'),
            'filters' => ['archived' => $includeArchived ? '1' : ''],
        ], $status);
    }

    /** @return array<string, string> */
    private function fieldErrors(HttpException $exception): array
    {
        return $exception->fields() !== [] ? $exception->fields() : ['name' => $exception->getMessage()];
    }
}
