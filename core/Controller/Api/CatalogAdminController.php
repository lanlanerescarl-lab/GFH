<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\CatalogService;
use Gfh\Core\Support\ListQuery;

/**
 * Admin catalog management. Products permissions: view to read, edit to create and change,
 * delete to archive a brand or category. Archiving is refused while a brand or category is in use.
 */
final class CatalogAdminController extends ApiController
{
    private const SORTS = ['name' => 'p.name', 'price' => 'price_from', 'newest' => 'p.created_at', 'status' => 'p.status'];
    private const FILTERS = ['brand_id', 'category_id', 'min_price', 'max_price', 'status', 'attr'];

    // ---- products

    public function products(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'view');
        $query = ListQuery::fromRequest($request, self::SORTS, 'name', self::FILTERS, ['category_id']);

        return Response::ok($services->catalog()->searchProducts(CatalogService::parseFilters($query->filters), $query, false));
    }

    public function product(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'view');
        $product = $services->catalog()->getProduct($this->id($request), ['tiers' => true, 'stock' => true]);

        return $product === null ? throw HttpException::notFound('That product could not be found.') : Response::ok($product);
    }

    public function createProduct(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');

        return Response::ok($services->catalog()->createProduct($this->body($request), $this->actorId($request)), 201);
    }

    public function updateProduct(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->updateProduct($id, $this->body($request), $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    public function setProductStatus(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->setProductStatus($id, $this->text($request, 'status'), $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => $this->text($request, 'status')]);
    }

    // ---- variants and tiers

    public function createVariant(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');

        return Response::ok($services->catalog()->createVariant($this->id($request), $this->body($request), $this->actorId($request)), 201);
    }

    public function updateVariant(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->updateVariant($id, $this->body($request), $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    public function createTier(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');

        return Response::ok($services->catalog()->saveTier($this->id($request), $this->body($request), null, $this->actorId($request)), 201);
    }

    public function updateTier(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $body = $this->body($request);
        $variantId = (string) ($body['variant_id'] ?? '');
        if (!\Gfh\Core\Support\Uuid::isValid($variantId)) {
            throw HttpException::unprocessable(['variant_id' => 'Send the variant id the tier belongs to.']);
        }

        return Response::ok($services->catalog()->saveTier($variantId, $body, $this->id($request), $this->actorId($request)));
    }

    public function deleteTier(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->deleteTier($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'deleted' => true]);
    }

    // ---- photos

    public function attachImage(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $body = $this->body($request);
        $result = $services->catalog()->attachImage(
            $this->id($request), (string) ($body['media_id'] ?? ''), isset($body['variant_id']) ? (string) $body['variant_id'] : null, (bool) ($body['primary'] ?? false),
        );

        return Response::ok($result, 201);
    }

    public function setPrimaryImage(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->setPrimaryImage($id);

        return Response::ok(['id' => $id, 'primary' => true]);
    }

    public function reorderImages(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $ids = $this->body($request)['image_ids'] ?? null;
        if (!is_array($ids)) {
            throw HttpException::unprocessable(['image_ids' => 'Send the photo ids in the order you want.']);
        }
        $services->catalog()->reorderImages($this->id($request), array_map('strval', $ids));

        return Response::ok(['reordered' => true]);
    }

    public function removeImage(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->removeImage($id);

        return Response::ok(['id' => $id, 'removed' => true]);
    }

    // ---- brands

    public function brands(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'view');
        $query = ListQuery::fromRequest($request, ['name' => 'b.name', 'status' => 'b.status'], 'name', ['archived']);

        return Response::ok($services->catalog()->listBrands($query, filter_var($query->filter('archived'), FILTER_VALIDATE_BOOLEAN)));
    }

    public function createBrand(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');

        return Response::ok($services->catalog()->createBrand($this->text($request, 'name'), $this->text($request, 'manufacturer'), $this->actorId($request)), 201);
    }

    public function renameBrand(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->renameBrand($id, $this->text($request, 'name'), $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    public function setBrandLogo(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $media = $this->text($request, 'media_id');
        $services->catalog()->setBrandLogo($id, $media === '' ? null : $media, $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    /** Archiving needs delete permission on Products. */
    public function archiveBrand(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'delete');
        $id = $this->id($request);
        $services->catalog()->archiveBrand($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'archived']);
    }

    public function restoreBrand(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->restoreBrand($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'active']);
    }

    // ---- categories

    public function categories(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'view');

        return Response::ok(['categories' => $services->catalog()->categoryTree(filter_var($request->query()['archived'] ?? false, FILTER_VALIDATE_BOOLEAN))]);
    }

    public function createCategory(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $parent = $this->text($request, 'parent_id');

        return Response::ok($services->catalog()->createCategory($parent === '' ? null : $parent, $this->text($request, 'name'), $this->actorId($request)), 201);
    }

    public function renameCategory(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->renameCategory($id, $this->text($request, 'name'), $this->actorId($request));

        return Response::ok(['id' => $id]);
    }

    /** Archiving needs delete permission on Products. */
    public function archiveCategory(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'delete');
        $id = $this->id($request);
        $services->catalog()->archiveCategory($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'archived']);
    }

    public function restoreCategory(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $id = $this->id($request);
        $services->catalog()->restoreCategory($id, $this->actorId($request));

        return Response::ok(['id' => $id, 'status' => 'active']);
    }
}
