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
 * Admin Brand management controller: list, create, rename, upload logo, archive and restore.
 * Edit permission allows create, rename and logo upload; delete permission allows archive and restore.
 */
final class BrandsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'view');

        return $this->renderIndex($request, $auth['tenant'], $auth['staff'], $auth['services']);
    }

    public function store(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $name = $this->text($request, 'name');
        $manufacturer = trim($this->text($request, 'manufacturer'));

        try {
            $auth['services']->catalog()->createBrand($name, $manufacturer === '' ? null : $manufacturer, $auth['staff']->id);
            $this->flash($request, 'ok', 'Brand created.', $name . ' is ready for products.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/brands'));
        } catch (HttpException $exception) {
            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['createInput' => ['name' => $name, 'manufacturer' => $manufacturer], 'createErrors' => $this->fieldErrors($exception)],
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
            $auth['services']->catalog()->renameBrand($id, $name, $auth['staff']->id);
            $this->flash($request, 'ok', 'Brand renamed.', 'Updated to ' . $name . '.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/brands'));
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

    public function uploadLogo(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $catalog = $auth['services']->catalog();

        try {
            if ($this->text($request, 'clear') === '1') {
                $catalog->setBrandLogo($id, null, $auth['staff']->id);
                $this->flash($request, 'ok', 'Brand logo cleared.');
            } else {
                $uploaded = $this->uploadImages($request, $auth['services'], $auth['staff'], 'file', null);
                $catalog->setBrandLogo($id, $uploaded[0]['id'], $auth['staff']->id);
                $this->flash($request, 'ok', 'Brand logo updated.');
            }

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/brands'));
        } catch (HttpException $exception) {
            $msg = $exception->fields()['file'] ?? $exception->fields()['logo_media_id'] ?? $exception->getMessage();

            return $this->renderIndex(
                $request,
                $auth['tenant'],
                $auth['staff'],
                $auth['services'],
                ['logoErrors' => [$id => $msg]],
                $exception->status(),
            );
        }
    }

    public function archive(Request $request): Response
    {
        $auth = $this->authorize($request, 'products', 'delete');
        $id = $this->id($request);

        try {
            $auth['services']->catalog()->archiveBrand($id, $auth['staff']->id);
            $this->flash($request, 'ok', 'Brand archived.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/brands'));
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
            $auth['services']->catalog()->restoreBrand($id, $auth['staff']->id);
            $this->flash($request, 'ok', 'Brand restored.');

            return Response::redirect($this->safeReturnUrl($request, $auth['tenant'], $this->text($request, 'return_to'), '/brands'));
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
        $query = ListQuery::fromRequest($request, ['name' => 'b.name', 'products' => 'product_count', 'status' => 'b.status'], 'name');
        $includeArchived = $this->queryText($request, 'archived') === '1';
        $result = $services->catalog()->listBrands($query, $includeArchived);

        return $this->render($request, $tenant, $staff, 'brands', [
            'result' => $result,
            'query' => $query,
            'createInput' => $state['createInput'] ?? [],
            'createErrors' => $state['createErrors'] ?? [],
            'rowInputs' => $state['rowInputs'] ?? [],
            'rowErrors' => $state['rowErrors'] ?? [],
            'logoErrors' => $state['logoErrors'] ?? [],
            'canEdit' => $this->can($staff, $tenant, 'products', 'edit'),
            'canDelete' => $this->can($staff, $tenant, 'products', 'delete'),
            'activeTab' => 'brands',
            'productsUrl' => $this->adminUrl($request, $tenant, '/products'),
            'brandsUrl' => $this->adminUrl($request, $tenant, '/brands'),
            'categoriesUrl' => $this->adminUrl($request, $tenant, '/categories'),
            'mediaBase' => $request->basePath() . '/media/' . $tenant->organization->slug,
            'filters' => ['archived' => $includeArchived ? '1' : ''],
        ], $status);
    }

    /** @return array<string, string> */
    private function fieldErrors(HttpException $exception): array
    {
        return $exception->fields() !== [] ? $exception->fields() : ['name' => $exception->getMessage()];
    }
}
