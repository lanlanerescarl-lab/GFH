<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Support\Decimal;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;

/**
 * Seed 0003 (batch 8.1b): about four months of back-dated history for the demo organization.
 *
 * Everything goes through the real services (purchase orders and receipts, online orders, POS sales), day by day in
 * time order, with a fixed random seed, so prices, costs, stock picks and totals follow the shop's own rules. Because
 * the services stamp "now", the dates are put right afterwards: mutable tables are updated in place, and the
 * append-only stock_movements get their dates from DemoSeedClock's temporary trigger.
 *
 * A running picture of stock per branch and variant ($avail) decides what can be sold, so stock never goes negative.
 * Opening receipts on the first day come before the first sale. The pair SLY-HUB / KN-ESP-250 is left alone: seed
 * 0001 records an oversell there on purpose.
 */
final class DemoHistorySeeder
{
    public const DAYS = 119;

    public const SEED = 811020;

    public const ANCHOR_SKU = 'IC-RCK-18W';

    private const NEW_PREFIXES = ['SP-', 'IC-', 'TH-', 'HL-', 'BD-', 'KN-HON', 'SB-CAL'];

    private const DAY_FACTOR = [1 => 0.9, 2 => 0.9, 3 => 0.95, 4 => 1.0, 5 => 1.15, 6 => 1.35, 7 => 1.25];

    private TenantServices $services;

    private DemoSeedClock $clock;

    private SeedRandom $rng;

    private ?string $actorId = null;

    private string $cashierId;

    private \DateTimeImmutable $today;

    private \DateTimeImmutable $clampNow;

    /** @var array<string, string> branch code => id */
    private array $branch = [];

    /** @var array<string, array{id: string, branch: string}> terminal code => terminal */
    private array $terminal = [];

    /** @var array<string, array<string, mixed>> */
    private array $variants = [];

    /** @var array<string, list<string>> branch code => variant ids sold or stocked there */
    private array $assort = [];

    /** @var array<string, array<string, int>> branch code => variant id => thousandths on hand or reserved-free */
    private array $avail = [];

    /** @var array<string, list<array{int, int}>> variant id => [min thousandths, price in centavos] */
    private array $tiers = [];

    /** @var array<string, float> */
    private array $cost = [];

    /** @var array<string, array<string, mixed>> */
    private array $rules = [];

    /** @var list<array<string, mixed>> */
    private array $customers = [];

    /** @var array<string, int> */
    private array $orderSeq = [];

    /** @var array<string, int> */
    private array $receiptSeq = [];

    private int $refSeq = 0;

    private int $recent = 0;

    private int $orders = 0;

    private int $sales = 0;

    private int $shifts = 0;

    private int $purchaseOrders = 0;

    public function __construct(private readonly \PDO $organization, private readonly \PDO $core, private readonly string $uploadRoot, private readonly ?\DateTimeImmutable $at = null)
    {
    }

    /** @return array{orders: int, pos_sales: int, shifts: int, purchase_orders: int} empty when there was nothing to do */
    public function run(?string $slug): array
    {
        if ($slug !== 'demo') {
            throw new \RuntimeException('The demo history seed only runs for the organization "demo".');
        }
        if (!DemoSeedClock::connectedAsOwner($this->organization)) {
            throw new \RuntimeException('The demo history seed only runs as the migrator role (the owner of stock_movements).');
        }
        if ($this->value('SELECT 1 FROM customers WHERE email = ?', [DemoCustomerData::anchorEmail()]) !== false) {
            return [];
        }
        if ($this->value('SELECT 1 FROM product_variants WHERE sku = ?', [self::ANCHOR_SKU]) === false) {
            throw new \RuntimeException('Run seeds 0001 and 0002 first: the demo catalog is not there yet.');
        }
        if (DemoSeedClock::exists($this->organization)) {
            throw new \RuntimeException('A seed clock trigger already exists. Remove it before running the history seed.');
        }

        $now = $this->at ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $this->today = $now->setTime(0, 0);
        $this->clampNow = $now->modify('-10 minutes');
        $this->rng = new SeedRandom(self::SEED);

        $found = $this->core->query("SELECT m.user_id FROM memberships m JOIN organizations o ON o.id = m.organization_id WHERE o.slug = 'demo' AND m.status = 'active' ORDER BY m.created_at ASC LIMIT 1")->fetchColumn();
        $this->actorId = is_string($found) && Uuid::isValid($found) ? $found : null;
        $this->cashierId = $this->actorId ?? '00000000-0000-4000-8000-000000000001';
        $this->services = new TenantServices($this->organization, new AuditService($this->core), new Hooks(), $this->uploadRoot, 'demo');
        $this->clock = new DemoSeedClock($this->organization);

        $this->load();
        $this->clock->install();
        try {
            $this->simulate();
        } catch (\Throwable $e) {
            try {
                $this->clock->remove();
            } catch (\Throwable) {
                // The surrounding transaction is rolled back, which removes the trigger as well.
            }
            throw $e;
        }
        $this->clock->remove();
        $this->finish();

        return ['orders' => $this->orders, 'pos_sales' => $this->sales, 'shifts' => $this->shifts, 'purchase_orders' => $this->purchaseOrders];
    }

    // ------------------------------------------------------------------ set-up

    private function load(): void
    {
        $this->branch = $this->organization->query('SELECT code, id FROM branches')->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach (['BCD-MAIN', 'TLS-WH', 'SLY-HUB'] as $code) {
            if (!isset($this->branch[$code])) {
                throw new \RuntimeException('Run seed 0001 first: branch ' . $code . ' is missing.');
            }
        }
        foreach ($this->organization->query("SELECT t.code, t.id, b.code AS branch FROM terminals t JOIN branches b ON b.id = t.branch_id WHERE t.code IN ('T1', 'T2')")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->terminal[$row['code']] = ['id' => $row['id'], 'branch' => $row['branch']];
        }
        if (count($this->terminal) !== 2) {
            throw new \RuntimeException('Run seed 0001 first: terminals T1 and T2 are missing.');
        }

        $fallbackSupplier = (string) $this->value('SELECT id FROM suppliers ORDER BY created_at LIMIT 1', []);
        $costs = $this->organization->query('SELECT DISTINCT ON (variant_id) variant_id, unit_cost FROM purchase_order_lines ORDER BY variant_id, created_at DESC')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $rows = $this->organization->query("SELECT v.id, v.sku, v.retail_price, v.allow_decimal_qty, v.qty_step, v.min_qty, v.track_batches, p.supplier_id FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.status = 'active' ORDER BY v.sku")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sku = (string) $row['sku'];
            $line = str_starts_with($sku, 'IC-') || str_starts_with($sku, 'TH-') ? 'ha' : (str_starts_with($sku, 'HL-') || str_starts_with($sku, 'BD-') ? 'fd' : 'fb');
            $isNew = false;
            foreach (self::NEW_PREFIXES as $prefix) {
                $isNew = $isNew || str_starts_with($sku, $prefix);
            }
            $this->variants[$row['id']] = $row + ['line' => $line, 'decimal' => (bool) $row['allow_decimal_qty'], 'batch' => (bool) $row['track_batches'], 'is_new' => $isNew, 'supplier' => $row['supplier_id'] ?? $fallbackSupplier];
            $this->cost[$row['id']] = isset($costs[$row['id']]) ? (float) $costs[$row['id']] : round((float) $row['retail_price'] * 0.6, 2);
        }
        foreach ($this->organization->query("SELECT variant_id, min_qty, price FROM price_tiers WHERE basis = 'quantity'")->fetchAll(\PDO::FETCH_ASSOC) as $tier) {
            $this->tiers[$tier['variant_id']][] = [(int) round((float) $tier['min_qty'] * 1000), (int) Decimal::parse((string) $tier['price'], 2)];
        }
        foreach ($this->organization->query("SELECT id, key, label, percent, vat_exempt, active FROM discount_rules WHERE key IN ('senior', 'pwd', 'promo5')")->fetchAll(\PDO::FETCH_ASSOC) as $rule) {
            $this->rules[$rule['key']] = $rule;
        }

        foreach (array_keys($this->branch) as $code) {
            $this->assort[$code] = [];
            foreach ($this->variants as $id => $v) {
                // The Silay hub only sells food and drink, and keeps the oversold espresso out of the history.
                $stocked = $code !== 'SLY-HUB' || ($v['line'] === 'fb' && $v['sku'] !== 'KN-ESP-250');
                if ($stocked) {
                    $this->assort[$code][] = $id;
                    $this->avail[$code][$id] = 0;
                }
            }
        }

        // Seed 0001's own sales get their final receipt numbers after the history, which then starts at 000001.
        $this->customers = [];
    }

    // ------------------------------------------------------------------ the days

    private function simulate(): void
    {
        $existing = $this->organization->query('SELECT id FROM pos_sales')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($existing as $id) {
            $this->write('UPDATE pos_sales SET receipt_number = ? WHERE id = ?', ['TMP-' . substr((string) $id, 0, 8), $id]);
        }

        $insert = $this->organization->prepare('INSERT INTO customers (full_name, email, phone, customer_type, tier) VALUES (:full_name, :email, :phone, :customer_type, :tier) RETURNING *');
        foreach (DemoCustomerData::customers() as $i => $customer) {
            $insert->execute($customer);
            $row = $insert->fetch(\PDO::FETCH_ASSOC);
            $this->write('UPDATE customers SET created_at = ? WHERE id = ?', [$this->fmt($this->today->modify('-' . self::DAYS . ' days')->setTime(6, $i)), $row['id']]);
            $this->customers[] = $row;
        }

        for ($d = 0; $d <= self::DAYS; $d++) {
            $this->runDay($this->today->modify('-' . (self::DAYS - $d) . ' days'), $d);
        }
    }

    private function runDay(\DateTimeImmutable $day, int $d): void
    {
        $factor = self::DAY_FACTOR[(int) $day->format('N')] * (in_array((int) $day->format('j'), [14, 15, 16, 29, 30, 31], true) ? 1.2 : 1.0) * (0.8 + 0.4 * ($d / self::DAYS));
        $events = [];
        if ($d === 0 || in_array((int) $day->format('N'), [1, 4], true)) {
            $events[] = [$day->setTime(7, 30), 'restock', null];
        }

        $shifts = [];
        if ($d < self::DAYS) {
            foreach (['T1' => [5.5, 0.04], 'T2' => [3.2, 0.10]] as $code => [$mean, $closedChance]) {
                if ($this->rng->chance($closedChance)) {
                    continue;
                }
                $shifts[$code] = $this->openShift($code, $day);
                $sales = $this->count($mean * $factor);
                for ($i = 0; $i < $sales; $i++) {
                    $seconds = (int) (9 * 3600 + (($this->rng->float() + $this->rng->float()) / 2) * 10 * 3600);
                    $events[] = [$day->setTime(0, 0)->modify('+' . $seconds . ' seconds'), 'pos', $code];
                }
            }
        }
        for ($i = $this->count(2.0 * $factor); $i > 0; $i--) {
            $events[] = [$day->setTime(0, 0)->modify('+' . $this->rng->int(7 * 3600, 21 * 3600 + 1800) . ' seconds'), 'order', null];
        }

        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($events as [$at, $kind, $code]) {
            match ($kind) {
                'restock' => $this->restock($at, $d === 0),
                'pos' => $this->posSale($at, $code, $shifts[$code], $d),
                default => $at <= $this->clampNow ? $this->onlineOrder($at) : null,
            };
        }

        foreach ($shifts as $shift) {
            $variance = $this->rng->chance(0.7) ? 0 : $this->rng->pick([-50, -20, -10, 10, 20, 40]);
            $expected = $shift['opening'] + $shift['cash'];
            $this->write('UPDATE pos_shifts SET expected_cash = ?, counted_cash = ?, variance = ? WHERE id = ?', [Decimal::format($expected, 2), Decimal::format($expected + $variance * 100, 2), Decimal::format($variance * 100, 2), $shift['id']]);
        }
    }

    private function count(float $mean): int
    {
        $x = $mean * (0.5 + $this->rng->float());
        $n = (int) floor($x);

        return $n + ($this->rng->chance($x - $n) ? 1 : 0);
    }

    /** @return array{id: string, opening: int, cash: int} */
    private function openShift(string $code, \DateTimeImmutable $day): array
    {
        $id = Uuid::v4();
        $opening = $this->rng->pick([100000, 150000, 200000]);
        $this->write(
            "INSERT INTO pos_shifts (id, terminal_id, cashier_user_id, opened_at, opening_cash, closed_at, counted_cash, expected_cash, variance, status) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 'closed')",
            [$id, $this->terminal[$code]['id'], $this->cashierId, $this->fmt($day->setTime(8, $this->rng->int(0, 25))), Decimal::format($opening, 2), $this->fmt($day->setTime(19, 30)->modify('+' . $this->rng->int(0, 60) . ' minutes'))],
        );
        $this->shifts++;

        return ['id' => $id, 'opening' => $opening, 'cash' => 0];
    }

    // ------------------------------------------------------------------ restocking

    private function restock(\DateTimeImmutable $at, bool $opening): void
    {
        $procurement = $this->services->procurement();
        foreach (array_keys($this->branch) as $code) {
            $bySupplier = [];
            foreach ($this->assort[$code] as $id) {
                $v = $this->variants[$id];
                $target = $this->target($v, $code);
                $have = $this->avail[$code][$id];
                if (!$opening && $have >= $target * 0.4) {
                    continue;
                }
                $need = (int) (ceil(($target - $have) / 1000) * 1000);
                $bySupplier[$v['supplier']][] = [$id, $need];
            }
            foreach ($bySupplier as $supplierId => $items) {
                $lines = [];
                foreach ($items as [$id, $need]) {
                    $v = $this->variants[$id];
                    $this->cost[$id] = round($this->cost[$id] * (1 + ($this->rng->float() - 0.45) * 0.03), 2);
                    $lines[] = ['variant_id' => $id, 'quantity' => $v['decimal'] ? number_format($need / 1000, 3, '.', '') : (string) intdiv($need, 1000), 'unit_cost' => number_format($this->cost[$id], 4, '.', '')];
                }
                $po = $procurement->createPurchaseOrder(['supplier_id' => $supplierId, 'branch_id' => $this->branch[$code], 'expected_on' => $this->today->format('Y-m-d'), 'notes' => $opening ? 'Opening stock.' : 'Scheduled restock.', 'lines' => $lines], $this->actorId);
                $procurement->sendPurchaseOrder($po['id'], $this->actorId);
                $poLines = array_column($procurement->getPurchaseOrder($po['id'])['lines'], null, 'variant_id');
                $receive = [];
                foreach ($items as $index => [$id, $need]) {
                    $v = $this->variants[$id];
                    $entry = ['line_id' => $poLines[$id]['id'], 'qty_received' => $lines[$index]['quantity'], 'qty_rejected' => '0'];
                    if ($v['batch']) {
                        $expires = $at->modify('+' . $this->shelfDays((string) $v['sku']) . ' days');
                        if ($expires < $this->today->modify('+14 days')) {
                            $expires = $this->today->modify('+' . $this->rng->int(14, 45) . ' days');
                        }
                        $entry += ['batch_code' => 'L' . $at->format('ymd') . '-' . substr($code, 0, 3) . '-' . ($index + 1), 'expires_on' => $expires->format('Y-m-d'), 'bin' => 'F-' . sprintf('%02d', $index + 1)];
                    }
                    $receive[] = $entry;
                }
                $received = $at->modify('+1 hour');
                $this->clock->set($received);
                $procurement->receive($po['id'], $receive, $this->actorId);
                foreach ($items as [$id, $need]) {
                    $this->avail[$code][$id] += $need;
                }
                $this->write('UPDATE purchase_orders SET created_at = ?, sent_at = ?, expected_on = ? WHERE id = ?', [$this->fmt($at->modify('-1 day')), $this->fmt($at->modify('-1 day')->modify('+20 minutes')), $at->format('Y-m-d'), $po['id']]);
                $this->write('UPDATE stock_batches SET received_on = ?, created_at = ? WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = ?)', [$at->format('Y-m-d'), $this->fmt($received), $po['id']]);
                $this->purchaseOrders++;
            }
        }
    }

    /** What a branch tries to keep, in thousandths. */
    private function target(array $v, string $code): int
    {
        $retail = (float) $v['retail_price'];
        $units = $v['decimal'] ? 30 : ($retail < 500 ? 60 : ($retail < 2000 ? 24 : ($retail < 6000 ? 10 : 6)));
        $factor = match ($code) {
            'BCD-MAIN' => $v['line'] === 'fb' ? 1.0 : 0.5,
            'SLY-HUB' => 0.6,
            default => $v['line'] === 'fb' ? 1.2 : 1.0,
        };

        return max(3, (int) round($units * $factor)) * 1000;
    }

    private function shelfDays(string $sku): int
    {
        return match (true) {
            str_starts_with($sku, 'SP-MNG') => 180,
            str_starts_with($sku, 'SP-PIL') => 150,
            str_starts_with($sku, 'KN-HON') => 540,
            str_starts_with($sku, 'SB-CAL') => 300,
            default => 270,
        };
    }

    // ------------------------------------------------------------------ baskets

    /** @return list<array{id: string, milli: int, qty: string}> */
    private function basket(string $code, string $mode): array
    {
        $weights = [];
        foreach ($this->assort[$code] as $id) {
            $line = $this->variants[$id]['line'];
            $weights[$id] = ['fb' => 8, 'ha' => 3, 'fd' => 1][$line] * ($mode === 'pos' ? 1 : ['fb' => 1, 'ha' => 2, 'fd' => 3][$line]);
        }
        $wanted = (int) $this->rng->weighted([1 => 50, 2 => 30, 3 => 15, 4 => 5]);
        $chosen = [];
        for ($try = 0; $try < 14 && count($chosen) < $wanted; $try++) {
            $id = (string) $this->rng->weighted($weights);
            if (isset($chosen[$id])) {
                continue;
            }
            [$milli, $qty] = $this->quantity($this->variants[$id]);
            if ($this->avail[$code][$id] - $milli < ($this->variants[$id]['is_new'] ? 0 : 6000)) {
                continue;
            }
            $chosen[$id] = ['id' => $id, 'milli' => $milli, 'qty' => $qty];
        }

        return array_values($chosen);
    }

    /** @return array{int, string} thousandths and the quantity text */
    private function quantity(array $v): array
    {
        if ($v['decimal']) {
            $min = (int) round((float) $v['min_qty'] * 1000) ?: 100;
            $step = (int) round((float) $v['qty_step'] * 1000) ?: 100;
            $milli = $min + $step * $this->rng->int(0, 6);

            return [$milli, number_format($milli / 1000, 3, '.', '')];
        }
        $units = match ($v['line']) {
            'fb' => (int) $this->rng->weighted([1 => 55, 2 => 30, 3 => 15]),
            'ha' => $this->rng->chance(0.9) ? 1 : 2,
            default => $this->rng->chance(0.93) ? 1 : 2,
        };

        return [$units * 1000, (string) $units];
    }

    private function unitPrice(string $variantId, int $milli): int
    {
        $price = (int) Decimal::parse((string) $this->variants[$variantId]['retail_price'], 2);
        foreach ($this->tiers[$variantId] ?? [] as [$min, $tierPrice]) {
            if ($milli >= $min && $tierPrice < $price) {
                $price = $tierPrice;
            }
        }

        return $price;
    }

    // ------------------------------------------------------------------ POS sales

    /** @param array{id: string, opening: int, cash: int} $shift */
    private function posSale(\DateTimeImmutable $at, string $code, array &$shift, int $d): void
    {
        $branchCode = $this->terminal[$code]['branch'];
        $basket = $this->basket($branchCode, 'pos');
        if ($basket === []) {
            return;
        }

        $lines = [];
        $priced = [];
        foreach ($basket as $line) {
            $cents = $this->unitPrice($line['id'], $line['milli']);
            $lines[] = ['variant_id' => $line['id'], 'quantity' => $line['qty'], 'unit_price' => Decimal::format($cents, 2)];
            $priced[] = ['quantity_milli' => $line['milli'], 'unit_price' => $cents, 'discount' => 0];
        }

        $roll = $this->rng->float();
        $key = $d < 14 && $this->rng->chance(0.15) ? 'promo5' : ($roll < 0.06 ? 'senior' : ($roll < 0.085 ? 'pwd' : null));
        $totals = $this->services->pricing()->totalsForSale($priced, $key === null ? null : $this->rules[$key]);

        $payload = [
            'id' => Uuid::v4(),
            'receipt_number' => sprintf('%s-%06d', $code, $this->receiptSeq[$code] = ($this->receiptSeq[$code] ?? 0) + 1),
            'terminal_id' => $this->terminal[$code]['id'],
            'shift_id' => $shift['id'],
            'sold_at' => $at->format('Y-m-d\TH:i:sP'),
            'lines' => $lines,
            'payments' => $this->payments($totals['total'], $at, $shift),
        ];
        if ($this->rng->chance(0.12)) {
            $payload['customer_id'] = $this->rng->pick($this->customers)['id'];
        }
        if ($key !== null) {
            $payload['discount'] = ['key' => $key] + ($key === 'promo5' ? [] : ['id_ref_last4' => sprintf('%04d', $this->rng->int(1000, 9999))]);
        }

        $this->clock->set($at);
        $this->services->pos()->syncSale($payload);
        foreach ($basket as $line) {
            $this->avail[$branchCode][$line['id']] -= $line['milli'];
        }
        $this->sales++;
    }

    /** @return list<array{method: string, amount: string, reference?: string}> */
    private function payments(int $total, \DateTimeImmutable $at, array &$shift): array
    {
        $methods = ['cash' => 55, 'gcash' => 20, 'maya' => 8, 'card' => 12];
        $parts = [[$this->rng->weighted($methods), $total]];
        if ($total >= 40000 && $this->rng->chance(0.09)) {
            $first = (int) (round($total * $this->rng->int(30, 70) / 10000) * 100);
            if ($first >= 100 && $total - $first >= 100) {
                $one = (string) $this->rng->weighted($methods);
                $other = (string) $this->rng->pick(array_values(array_diff(array_keys($methods), [$one])));
                $parts = [[$one, $first], [$other, $total - $first]];
            }
        }

        $payments = [];
        foreach ($parts as [$method, $cents]) {
            $payment = ['method' => (string) $method, 'amount' => Decimal::format($cents, 2)];
            if ($method === 'cash') {
                $shift['cash'] += $cents;
            } else {
                $payment['reference'] = strtoupper(substr((string) $method, 0, 2)) . '-' . $at->format('ymd') . '-' . sprintf('%05d', ++$this->refSeq);
            }
            $payments[] = $payment;
        }

        return $payments;
    }

    // ------------------------------------------------------------------ online orders

    private function onlineOrder(\DateTimeImmutable $t): void
    {
        $customer = $this->customers[(int) floor(($this->rng->float() ** 1.6) * count($this->customers))];
        $pickup = $this->rng->chance(0.55);
        if ($pickup) {
            $stock = $this->rng->chance(0.6) ? 'BCD-MAIN' : 'SLY-HUB';
            $fulfillment = ['method' => 'pickup', 'branch_id' => $this->branch[$stock]];
        } else {
            $stock = 'TLS-WH';
            $fulfillment = [
                'method' => 'delivery',
                'line1' => $this->rng->int(1, 180) . ' ' . $this->rng->pick(DemoCustomerData::STREETS) . ', Brgy. ' . $this->rng->pick(DemoCustomerData::BARANGAYS),
                'city' => $this->rng->pick(DemoCustomerData::CITIES),
                'province' => 'Negros Occidental',
                'recipient' => $customer['full_name'],
                'phone' => $customer['phone'],
            ];
        }
        $basket = $this->basket($stock, $pickup ? 'pickup' : 'delivery');
        if ($basket === []) {
            return;
        }

        $orders = $this->services->orders();
        $order = $orders->createOrder($customer, array_map(static fn (array $l): array => ['variant_id' => $l['id'], 'quantity' => $l['qty']], $basket), $fulfillment, [], $this->actorId, $this->rng->chance(0.65) ? 'online' : 'staff');
        $id = (string) $order['id'];

        $steps = $pickup ? ['confirmed', 'ready', 'completed'] : ['confirmed', 'out_for_delivery', 'completed'];
        $spans = $pickup ? [[10, 180], [60, 600], [30, 2880]] : [[10, 240], [120, 1200], [1440, 5760]];
        // Orders from the last day and a half move slowly, so the Orders page shows every stage.
        $slow = $t > $this->clampNow->modify('-36 hours') ? 4 : 1;
        $limit = $slow > 1 ? $this->recent++ % 4 : null;
        $times = ['placed' => $t];
        $cursor = $t;
        foreach ($steps as $i => $step) {
            $cursor = $cursor->modify('+' . $this->rng->int($spans[$i][0], $spans[$i][1]) * $slow . ' minutes');
            $times[$step] = $cursor;
        }
        $method = (string) $this->rng->weighted($pickup ? ['cash' => 35, 'gcash' => 35, 'maya' => 15, 'card' => 15] : ['cod' => 35, 'gcash' => 30, 'maya' => 15, 'bank_transfer' => 10, 'card' => 10]);
        $prepaid = !in_array($method, ['cash', 'cod'], true);
        $reference = $prepaid ? strtoupper(substr($method, 0, 2)) . '-' . $t->format('ymd') . '-' . sprintf('%05d', ++$this->refSeq) : null;
        $cancelAt = $this->rng->chance(0.07) ? $t->modify('+' . $this->rng->int(20, 1500) . ' minutes') : null;
        $paidAt = null;
        $cancelledAt = null;

        if ($cancelAt !== null && $cancelAt <= $this->clampNow) {
            $orders->cancel($id, $this->rng->pick(['Customer changed their mind', 'Found a better price elsewhere', 'Ordered by mistake', 'Could not be reached to confirm']), $this->actorId);
            $cancelledAt = $cancelAt;
            $times = ['placed' => $t, 'cancelled' => $cancelAt];
        } else {
            $payAt = $prepaid ? $t->modify('+' . $this->rng->int(3, 45) . ' minutes') : null;
            if ($payAt !== null && $payAt <= $this->clampNow) {
                $orders->recordPayment($id, ['method' => $method, 'reference' => $reference, 'amount' => (string) $order['total']], $this->actorId);
                $paidAt = $payAt;
            }
            foreach ($steps as $i => $step) {
                // Of the orders from the last day and a half, every fourth stops at each stage, so all stages are on show.
                if ($times[$step] > $this->clampNow || ($limit !== null && $i >= $limit)) {
                    unset($times[$step]);
                    continue;
                }
                $this->clock->set($times[$step]);
                $orders->advance($id, $step, $this->actorId);
                if ($step === 'completed' && !$prepaid) {
                    $orders->recordPayment($id, ['method' => $method, 'amount' => (string) $order['total']] + ($reference === null ? [] : ['reference' => $reference]), $this->actorId);
                    $paidAt = $times[$step];
                }
            }
            $times = array_filter($times, static fn (mixed $x): bool => $x instanceof \DateTimeImmutable);
        }
        if ($cancelledAt === null) {
            foreach ($basket as $line) {
                $this->avail[$stock][$line['id']] -= $line['milli'];
            }
        }

        $this->write('UPDATE orders SET placed_at = ?, order_number = ?, cancelled_at = ? WHERE id = ?', [$this->fmt($t), $this->orderNumber($t), $cancelledAt === null ? null : $this->fmt($cancelledAt), $id]);
        foreach ($times as $key => $time) {
            $this->write('UPDATE order_status_history SET changed_at = ? WHERE order_id = ? AND progress_key = ?', [$this->fmt($time), $id, $key]);
        }
        if ($paidAt !== null) {
            $this->write('UPDATE payments SET paid_at = ? WHERE order_id = ?', [$this->fmt($paidAt), $id]);
        }
        $this->orders++;
    }

    private function orderNumber(\DateTimeImmutable $t): string
    {
        $date = $t->format('Ymd');
        if (!isset($this->orderSeq[$date])) {
            $max = $this->value('SELECT max(substring(order_number from 13)::int) FROM orders WHERE order_number LIKE ?', ['SO-' . $date . '-%']);
            $this->orderSeq[$date] = (int) $max;
        }

        return sprintf('SO-%s-%04d', $date, ++$this->orderSeq[$date]);
    }

    // ------------------------------------------------------------------ the end

    private function finish(): void
    {
        $todayStart = $this->fmt($this->today);
        $this->write("UPDATE pos_sales SET synced_at = sold_at + interval '20 seconds' WHERE sold_at < ?", [$todayStart]);
        $this->write('UPDATE payments p SET paid_at = s.sold_at FROM pos_sales s WHERE p.pos_sale_id = s.id AND s.sold_at < ?', [$todayStart]);
        $this->write('UPDATE order_status_history h SET changed_at = o.placed_at FROM orders o WHERE h.order_id = o.id AND o.placed_at < ? AND h.changed_at = now()', [$todayStart]);

        // Seed 0001's three sales happen today: give them the receipt numbers that follow the history.
        foreach ($this->terminal as $code => $terminal) {
            $rows = $this->organization->prepare("SELECT id FROM pos_sales WHERE terminal_id = ? AND receipt_number LIKE 'TMP-%' ORDER BY sold_at, id");
            $rows->execute([$terminal['id']]);
            foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $this->write('UPDATE pos_sales SET receipt_number = ? WHERE id = ?', [sprintf('%s-%06d', $code, $this->receiptSeq[$code] = ($this->receiptSeq[$code] ?? 0) + 1), $id]);
            }
        }
    }

    // ------------------------------------------------------------------ small helpers

    private function fmt(\DateTimeInterface $t): string
    {
        return $t->format('Y-m-d H:i:sP');
    }

    /** @param list<mixed> $params */
    private function write(string $sql, array $params): void
    {
        $this->organization->prepare($sql)->execute($params);
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params): mixed
    {
        $statement = $this->organization->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
