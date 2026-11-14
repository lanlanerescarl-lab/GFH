<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Support\Uuid;
use Gfh\Tests\Support\DatabaseTestCase;

final class InventoryTest extends DatabaseTestCase
{
    private function averageCost(string $variantId): string
    {
        return (string) $this->shop->scalar('SELECT avg_cost FROM product_variants WHERE id = ?', [$variantId]);
    }

    public function testWeightedAverageCostAcrossSeveralReceipts(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $inventory = $this->shop->services->inventory();

        $this->shop->stock($branch, $variant, '10', '100.00');
        $this->assertSame('100.0000', $this->averageCost($variant));

        $this->shop->stock($branch, $variant, '10', '120.00');
        $this->assertSame('110.0000', $this->averageCost($variant), '(10 x 100 + 10 x 120) / 20');

        // Selling does not change the average.
        $inventory->sell($branch, $variant, '15', 'online', 'order', Uuid::v4(), null);
        $this->assertSame('110.0000', $this->averageCost($variant));

        $this->shop->stock($branch, $variant, '5', '130.00');
        $this->assertSame('120.0000', $this->averageCost($variant), '(5 x 110 + 5 x 130) / 10');
    }

    public function testWithNothingOnHandTheNewCostBecomesTheAverage(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '4', '100.00');
        $this->shop->services->inventory()->sell($branch, $variant, '4', 'online', 'order', Uuid::v4(), null);
        $this->assertSame('0.000', $this->shop->services->inventory()->onHand($branch, $variant));

        $this->shop->stock($branch, $variant, '10', '90.00');

        $this->assertSame('90.0000', $this->averageCost($variant));
    }

    public function testStockAddedWithoutACostDoesNotDragTheFirstCostDown(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->services->inventory()->adjust($branch, $variant, '5', 'Opening count', null);

        $this->shop->stock($branch, $variant, '5', '100.00');

        $this->assertSame('100.0000', $this->averageCost($variant), 'five units of unknown cost must not make the average 50');
    }

    public function testTheAverageIsRoundedToFourDecimalsHalfUp(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '1', '1.0000');
        $this->shop->stock($branch, $variant, '2', '1.0001');

        $this->assertSame('1.0001', $this->averageCost($variant), '3.0002 / 3 = 1.000066...');
    }

    public function testAConversionMovesStockAndCostAndIsNotAPurchase(): void
    {
        $branch = $this->shop->branch();
        $bulk = $this->shop->variant(['sku' => 'RICE-25KG']);
        $pack = $this->shop->variant(['sku' => 'RICE-1KG']);
        $inventory = $this->shop->services->inventory();
        $this->shop->stock($branch, $bulk, '4', '1000.00');

        $result = $inventory->convert($branch, $bulk, '1', $pack, '25', '42.0000');

        $this->assertSame('1000.0000', $result['out_cost']);
        $this->assertSame('3.000', $inventory->onHand($branch, $bulk));
        $this->assertSame('25.000', $inventory->onHand($branch, $pack));
        $this->assertSame('42.0000', $this->averageCost($pack));
        $this->assertSame('1000.0000', $this->averageCost($bulk), 'the source average is unchanged');

        $types = $this->org->query('SELECT movement_type, COUNT(*) AS n FROM stock_movements GROUP BY movement_type')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int) $types['receipt'], 'only the real purchase is a receipt');
        $this->assertSame(1, (int) $types['convert_out']);
        $this->assertSame(1, (int) $types['convert_in']);
        $this->assertSame(2, (int) $this->shop->scalar("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'conversion' AND reference_id = ?", [$result['conversion_id']]));

        // A second conversion at another cost moves the target's average.
        $inventory->convert($branch, $bulk, '1', $pack, '25', '50.0000');
        $this->assertSame('46.0000', $this->averageCost($pack), '(25 x 42 + 25 x 50) / 50');
    }

    public function testConvertingMoreThanIsOnHandIsRefused(): void
    {
        $branch = $this->shop->branch();
        $bulk = $this->shop->variant();
        $pack = $this->shop->variant();
        $this->shop->stock($branch, $bulk, '1', '100.00');

        $this->assertHttpStatus(422, fn () => $this->shop->services->inventory()->convert($branch, $bulk, '2', $pack, '50', '10.00'));
        $this->assertSame('1.000', $this->shop->services->inventory()->onHand($branch, $bulk));
        $this->assertSame('0.000', $this->shop->services->inventory()->onHand($branch, $pack));
    }

    public function testReservationsHoldStockAndReleaseWhenTheyExpire(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '10', '50.00');
        $inventory = $this->shop->services->inventory();
        $first = $this->shop->rawOrder($branch);
        $second = $this->shop->rawOrder($branch);
        $later = new \DateTimeImmutable('+1 hour');

        $inventory->reserve($first, [['variant_id' => $variant, 'branch_id' => $branch, 'quantity' => '4']], $later);
        $this->assertSame('6.000', $inventory->available($branch, $variant));

        $error = $this->assertHttpStatus(422, fn () => $inventory->reserve($second, [['variant_id' => $variant, 'branch_id' => $branch, 'quantity' => '7']], $later));
        $this->assertStringContainsString('only 6 available', $error->fields()['items']);

        // The hold runs out: stock is available again at once, and the sweep marks the reservation released.
        $this->org->exec("UPDATE stock_reservations SET expires_at = now() - interval '1 minute'");
        $this->assertSame('10.000', $inventory->available($branch, $variant));
        $this->assertSame(1, $inventory->releaseExpired());
        $this->assertSame('released', $this->shop->scalar('SELECT status FROM stock_reservations LIMIT 1'));

        $inventory->reserve($second, [['variant_id' => $variant, 'branch_id' => $branch, 'quantity' => '7']], $later);
        $this->assertSame('3.000', $inventory->available($branch, $variant));
    }

    public function testReleasingAnOrderGivesTheStockBack(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '10', '50.00');
        $inventory = $this->shop->services->inventory();
        $order = $this->shop->rawOrder($branch);

        $inventory->reserve($order, [['variant_id' => $variant, 'branch_id' => $branch, 'quantity' => '3']], new \DateTimeImmutable('+1 hour'));
        $this->assertSame(1, $inventory->releaseOrder($order));

        $this->assertSame('10.000', $inventory->available($branch, $variant));
    }

    public function testAdjustmentsAndWriteOffsNeedAReasonAndCannotGoBelowZero(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '5', '50.00');
        $inventory = $this->shop->services->inventory();

        $noReason = $this->assertHttpStatus(422, fn () => $inventory->adjust($branch, $variant, '-1', '  ', null));
        $this->assertArrayHasKey('reason', $noReason->fields());
        $this->assertHttpStatus(422, fn () => $inventory->writeOff($branch, $variant, '1', 'x', null));

        $tooMany = $this->assertHttpStatus(422, fn () => $inventory->writeOff($branch, $variant, '6', 'Spoiled in storage', null));
        $this->assertArrayHasKey('quantity', $tooMany->fields());

        $inventory->writeOff($branch, $variant, '2', 'Damaged in the warehouse', null);
        $inventory->adjust($branch, $variant, '-1', 'Cycle count correction', null);
        $this->assertSame('2.000', $inventory->onHand($branch, $variant));
        $this->assertSame(2, (int) $this->org->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('stock.adjusted', 'stock.written_off')")->fetchColumn());
    }

    public function testTheLedgerCannotBeEditedOrDeleted(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '5', '50.00');

        $this->expectException(\PDOException::class);
        $this->org->exec('DELETE FROM stock_movements');
    }

    public function testDefaultPickingTakesTheOldestBatchAndAHookCanChooseEarliestExpiry(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant(['track_batches' => true]);
        $this->shop->stock($branch, $variant, '5', '10.00', ['code' => 'OLD-RECEIVED', 'expires_on' => '2027-06-01']);
        $this->shop->stock($branch, $variant, '5', '10.00', ['code' => 'SOONER-EXPIRY', 'expires_on' => '2026-12-01']);
        $this->org->exec("UPDATE stock_batches SET received_on = received_on - 10 WHERE batch_code = 'OLD-RECEIVED'");
        $oldId = (string) $this->shop->scalar("SELECT id FROM stock_batches WHERE batch_code = 'OLD-RECEIVED'");
        $soonId = (string) $this->shop->scalar("SELECT id FROM stock_batches WHERE batch_code = 'SOONER-EXPIRY'");
        $inventory = $this->shop->services->inventory();

        $default = $inventory->sell($branch, $variant, '2', 'pos', 'pos_sale', Uuid::v4(), null, true);
        $this->assertSame($oldId, $default['picks'][0]['batch_id'], 'oldest received first by default');

        $this->shop->hooks->add(Hooks::STOCK_PICK_STRATEGY, static function (array $picks, array $context): array {
            $batches = $context['batches'];
            usort($batches, static fn (array $a, array $b): int => strcmp((string) $a['expires_on'], (string) $b['expires_on']));
            $remaining = $context['quantity_milli'];
            $result = [];
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, $batch['quantity_milli']);
                $result[] = ['batch_id' => $batch['batch_id'], 'quantity_milli' => $take];
                $remaining -= $take;
            }

            return $result;
        });

        // 6 units: the whole sooner-expiring batch (5) plus 1 from the other, split across batches.
        $sold = $inventory->sell($branch, $variant, '6', 'online', 'order', Uuid::v4(), null, true);
        $this->assertSame([$soonId, $oldId], array_column($sold['picks'], 'batch_id'));
        $this->assertSame(['5.000', '1.000'], array_column($sold['picks'], 'quantity'));
    }

    public function testAHookCanRefuseASaleAndAPosSaleCanKeepItAnyway(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant(['track_batches' => true]);
        $this->shop->stock($branch, $variant, '5', '10.00', ['code' => 'EXPIRED-1', 'expires_on' => '2020-01-01']);
        $this->shop->hooks->add(Hooks::STOCK_PICK_STRATEGY, static function (array $picks, array $context): array {
            foreach ($context['batches'] as $batch) {
                if ($batch['expires_on'] < '2026-01-01') {
                    throw HttpException::unprocessable(['quantity' => 'Batch ' . $batch['batch_code'] . ' has expired.']);
                }
            }

            return $picks;
        });
        $inventory = $this->shop->services->inventory();

        $this->assertHttpStatus(422, fn () => $inventory->sell($branch, $variant, '1', 'online', 'order', Uuid::v4(), null));
        $this->assertSame('5.000', $inventory->onHand($branch, $variant), 'a refused sale moves nothing');

        $kept = $inventory->sell($branch, $variant, '1', 'pos', 'pos_sale', Uuid::v4(), null, true, null, null, true);
        $this->assertNotNull($kept['refused']);
        $this->assertSame('4.000', $inventory->onHand($branch, $variant));
    }

    public function testASaleThatTheStockCannotCoverIsRefusedUnlessOverSellingIsAllowed(): void
    {
        $branch = $this->shop->branch();
        $variant = $this->shop->variant();
        $this->shop->stock($branch, $variant, '2', '10.00');
        $inventory = $this->shop->services->inventory();

        $this->assertHttpStatus(422, fn () => $inventory->sell($branch, $variant, '5', 'online', 'order', Uuid::v4(), null));

        $sold = $inventory->sell($branch, $variant, '5', 'pos', 'pos_sale', Uuid::v4(), null, true);
        $this->assertTrue($sold['oversold']);
        $this->assertSame('3.000', $sold['short_by']);
        $this->assertSame('-3.000', $inventory->onHand($branch, $variant));
    }

    public function testDecimalQuantitiesOnlyWhereTheVariantAllowsThem(): void
    {
        $branch = $this->shop->branch();
        $whole = $this->shop->variant();
        $weighed = $this->shop->variant(['decimals' => true, 'step' => '0.250', 'min' => '0.250', 'unit' => 'kg']);
        $inventory = $this->shop->services->inventory();

        $error = $this->assertHttpStatus(422, fn () => $inventory->receive($branch, $whole, '1.5', '10.00'));
        $this->assertArrayHasKey('quantity', $error->fields());

        $inventory->receive($branch, $weighed, '2.125', '80.00');
        $this->assertSame('2.125', $inventory->onHand($branch, $weighed));
    }
}
