<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\ListQuery;

/**
 * The POS endpoints. The terminal app uses pos permissions; the needs-review queue belongs to Sales.
 * A cashier works only on their own shift unless they also have edit permission on Sales (a manager or admin).
 */
final class PosApiController extends ApiController
{
    /** GET .../pos/terminals: the active terminals with their branch and whether a shift is open (the app's first screen). */
    public function terminals(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'view');

        return Response::ok(['items' => $services->pos()->activeTerminals()]);
    }

    /** GET .../pos/terminals/{id}/snapshot: everything the terminal needs to sell offline. */
    public function snapshot(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'view');

        return Response::ok($services->pos()->snapshot($this->id($request)));
    }

    public function currentShift(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'view');

        $terminalId = $this->id($request);

        return Response::ok([
            'shift' => $services->pos()->currentShift($terminalId),
            'next_receipt_number' => $services->pos()->nextReceiptNumberIfShiftOpen($terminalId),
        ]);
    }

    /** POST .../pos/shifts with {terminal_id, opening_cash}. */
    public function openShift(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'edit');
        $terminal = $this->text($request, 'terminal_id');

        return Response::ok($services->pos()->openShift($terminal, (string) $this->actorId($request), $this->body($request)['opening_cash'] ?? null), 201);
    }

    /** POST .../pos/shifts/{id}/close with {counted_cash}. */
    public function closeShift(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'edit');
        $id = $this->id($request);
        $this->assertOwnShift($request, $services, $id);

        return Response::ok($services->pos()->closeShift($id, $this->body($request)['counted_cash'] ?? null, $this->actorId($request)));
    }

    /**
     * POST .../pos/sales: uploads one sale. Safe to send again: the same sale id returns the same result.
     * A sale is never rejected for stock; it is flagged needs_review instead.
     */
    public function syncSale(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'edit');
        $payload = $this->body($request);
        $this->assertOwnShift($request, $services, (string) ($payload['shift_id'] ?? ''));

        $result = $services->pos()->syncSale($payload);

        return Response::ok($result, $result['duplicate'] ? 200 : 201);
    }

    /** GET .../pos/sales/{id}: a sale with its lines, for reprinting a receipt. */
    public function sale(Request $request): Response
    {
        $services = $this->authorize($request, 'pos', 'view');

        return Response::ok($services->pos()->getSale($this->id($request)));
    }

    /** GET .../admin/pos-sales: POS sales, including the needs-review queue (?status=needs_review). */
    public function sales(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'view');
        $query = ListQuery::fromRequest(
            $request,
            ['sold' => 's.sold_at', 'synced' => 's.synced_at', 'receipt' => 's.receipt_number', 'total' => 's.total'],
            '-sold',
            ['status', 'terminal_id', 'shift_id'],
            ['terminal_id', 'shift_id'],
        );

        return Response::ok($services->pos()->listSales($query));
    }

    /** POST .../admin/pos-sales/{id}/resolve with {note}. */
    public function resolveReview(Request $request): Response
    {
        $services = $this->authorize($request, 'sales', 'edit');

        return Response::ok($services->pos()->resolveReview($this->id($request), $this->text($request, 'note'), $this->actorId($request)));
    }

    private function assertOwnShift(Request $request, \Gfh\Core\Support\TenantServices $services, string $shiftId): void
    {
        $owner = $services->pos()->shiftOwnerId($shiftId);
        if ($owner === null) {
            throw HttpException::unprocessable(['shift_id' => 'That shift does not exist.']);
        }

        $tenant = $this->tenant($request);
        $may = $owner === $this->actorId($request) || $this->services->permissions()->can($this->staff($request), $tenant->organization, 'sales', 'edit');
        if (!$may) {
            throw HttpException::forbidden('That shift belongs to another cashier.');
        }
    }
}
