<?php

declare(strict_types=1);

use Gfh\Core\App;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;

/**
 * Development seed for Stage 5 (repeatable).
 *
 * Populates 3 branches, order progress steps, discount rules, 2 terminals, 2 suppliers,
 * 3 brands, a two-level category tree, 8 products with variants, purchase orders + receipts
 * (including a near-expiry batch and an open sent PO for reorder-suggestion math), manual
 * adjustments and write-offs, 3 customers, online orders across stages, and POS shifts/sales
 * (including one needs_review oversell). Stage 8 will extend this into the full demo dataset.
 */
return static function (\PDO $organization, \PDO $core, App $app): void {
    // Keep reruns safe: if our anchor branch already exists, leave existing data untouched.
    $already = $organization->query("SELECT 1 FROM branches WHERE code = 'BCD-MAIN' LIMIT 1")->fetchColumn();
    if ($already !== false) {
        return;
    }

    $actorStmt = $core->query(
        "SELECT m.user_id
           FROM memberships m
           JOIN organizations o ON o.id = m.organization_id
          WHERE o.slug = 'demo' AND m.status = 'active'
          ORDER BY m.created_at ASC
          LIMIT 1",
    );
    $foundActor = $actorStmt !== false ? $actorStmt->fetchColumn() : false;
    $actorId = is_string($foundActor) && Uuid::isValid($foundActor) ? $foundActor : null;
    $cashierId = $actorId ?? '00000000-0000-4000-8000-000000000001';

    $audit = new AuditService($core);
    $hooks = new Hooks();
    $services = new TenantServices($organization, $audit, $hooks, $app->path('storage/uploads'), 'demo');

    $catalog = $services->catalog();
    $procurement = $services->procurement();
    $inventory = $services->inventory();
    $orders = $services->orders();
    $pos = $services->pos();

    // 1. Branches (3 branches: flagship store, delivery-origin warehouse, secondary pickup hub).
    $insertBranch = $organization->prepare(
        'INSERT INTO branches (code, name, address, city, province, region, kind, is_pickup_point, is_delivery_origin, status)
         VALUES (:code, :name, :address, :city, :province, :region, :kind, :pickup, :origin, \'active\')
         RETURNING id',
    );
    $branchIds = [];
    foreach ([
        ['BCD-MAIN', 'Bacolod Main Branch', '18 Lacson Street, Brgy. 5', 'Bacolod City', 'Negros Occidental', 'NIR', 'store', true, false],
        ['TLS-WH', 'Talisay Central Warehouse', '88 Circumferential Road, Zone 12', 'Talisay City', 'Negros Occidental', 'NIR', 'warehouse', false, true],
        ['SLY-HUB', 'Silay Pickup Hub', '12 Rizal Street, Brgy. II', 'Silay City', 'Negros Occidental', 'NIR', 'store', true, false],
    ] as [$code, $name, $address, $city, $province, $region, $kind, $pickup, $origin]) {
        $insertBranch->bindValue('code', $code);
        $insertBranch->bindValue('name', $name);
        $insertBranch->bindValue('address', $address);
        $insertBranch->bindValue('city', $city);
        $insertBranch->bindValue('province', $province);
        $insertBranch->bindValue('region', $region);
        $insertBranch->bindValue('kind', $kind);
        $insertBranch->bindValue('pickup', $pickup, \PDO::PARAM_BOOL);
        $insertBranch->bindValue('origin', $origin, \PDO::PARAM_BOOL);
        $insertBranch->execute();
        $branchIds[$code] = (string) $insertBranch->fetchColumn();
    }

    // 2. Order progress steps, terminals, and discount rules.
    $insertStep = $organization->prepare(
        'INSERT INTO order_progress_steps (key, label, sort_order, is_terminal, maps_to, applies_when)
         VALUES (:key, :label, :sort_order, :is_terminal, :maps_to, :applies_when)
         ON CONFLICT (key) DO NOTHING',
    );
    foreach ([
        ['placed', 'Placed', 10, false, 'open', 'always'],
        ['confirmed', 'Confirmed', 20, false, 'in_progress', 'always'],
        ['in_production', 'In production', 30, false, 'in_progress', 'has_made_to_order'],
        ['ready', 'Ready for pickup', 40, false, 'in_progress', 'is_pickup'],
        ['out_for_delivery', 'Out for delivery', 50, false, 'in_progress', 'is_delivery'],
        ['completed', 'Completed', 60, true, 'complete', 'always'],
    ] as [$key, $label, $sortOrder, $isTerminal, $mapsTo, $appliesWhen]) {
        $insertStep->bindValue('key', $key);
        $insertStep->bindValue('label', $label);
        $insertStep->bindValue('sort_order', $sortOrder, \PDO::PARAM_INT);
        $insertStep->bindValue('is_terminal', $isTerminal, \PDO::PARAM_BOOL);
        $insertStep->bindValue('maps_to', $mapsTo);
        $insertStep->bindValue('applies_when', $appliesWhen);
        $insertStep->execute();
    }

    $insertTerminal = $organization->prepare(
        "INSERT INTO terminals (branch_id, code, name, status)
         VALUES (:branch_id, :code, :name, 'active')
         ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name
         RETURNING id",
    );
    $insertTerminal->execute(['branch_id' => $branchIds['BCD-MAIN'], 'code' => 'T1', 'name' => 'Bacolod Counter 1']);
    $terminal1Id = (string) $insertTerminal->fetchColumn();
    $insertTerminal->execute(['branch_id' => $branchIds['SLY-HUB'], 'code' => 'T2', 'name' => 'Silay Counter 1']);
    $terminal2Id = (string) $insertTerminal->fetchColumn();

    $insertDiscount = $organization->prepare(
        'INSERT INTO discount_rules (key, label, percent, vat_exempt, active)
         VALUES (:key, :label, :percent, :vat_exempt, true)
         ON CONFLICT (key) DO NOTHING',
    );
    foreach ([
        ['senior', 'Senior Citizen (20% + VAT exempt)', '20.00', true],
        ['pwd', 'PWD (20% + VAT exempt)', '20.00', true],
        ['promo5', 'Opening Week 5% Promo', '5.00', false],
    ] as [$key, $label, $percent, $vatExempt]) {
        $insertDiscount->bindValue('key', $key);
        $insertDiscount->bindValue('label', $label);
        $insertDiscount->bindValue('percent', $percent);
        $insertDiscount->bindValue('vat_exempt', $vatExempt, \PDO::PARAM_BOOL);
        $insertDiscount->execute();
    }

    // 3. Suppliers (2).
    $roaster = $procurement->createSupplier([
        'name' => 'Negros Roasters Supply',
        'contact_name' => 'Mateo Alvarez',
        'contact_email' => 'orders@negrosroasters.example.test',
        'contact_phone' => '+63 34 433 1200',
        'address' => 'Brgy. Mandalagan, Bacolod City',
        'payment_terms' => 'Net 15',
        'lead_time_days' => 5,
    ], $actorId);
    $packaging = $procurement->createSupplier([
        'name' => 'Visayas Packaging Co.',
        'contact_name' => 'Clarissa Lim',
        'contact_email' => 'sales@visayaspack.example.test',
        'contact_phone' => '+63 34 709 4410',
        'address' => 'Banago Port Road, Bacolod City',
        'payment_terms' => 'Net 30',
        'lead_time_days' => 7,
    ], $actorId);

    // 4. Brands (3) & two-level categories.
    $brandKape = $catalog->createBrand('Kape Negros', 'Negros Highlands Cooperative', $actorId)['id'];
    $brandSaboris = $catalog->createBrand('Saboris', 'Saboris Pantry Inc.', $actorId)['id'];
    $brandTerra = $catalog->createBrand('TerraCraft', 'TerraCraft Goods', $actorId)['id'];

    $catBev = $catalog->createCategory(null, 'Beverages', $actorId)['id'];
    $catCoffee = $catalog->createCategory($catBev, 'Roasted Coffee', $actorId)['id'];
    $catTea = $catalog->createCategory($catBev, 'Specialty Tea', $actorId)['id'];

    $catPantry = $catalog->createCategory(null, 'Pantry & Supplies', $actorId)['id'];
    $catSyrups = $catalog->createCategory($catPantry, 'Syrups & Sweeteners', $actorId)['id'];
    $catBrew = $catalog->createCategory($catPantry, 'Brewing Supplies', $actorId)['id'];

    // 5. Products (8 products, 11 variants across integer, decimal, and batch-tracked SKUs).
    $variants = [];
    $register = static function (array $product) use (&$variants): void {
        foreach ($product['variants'] as $v) {
            $variants[(string) $v['sku']] = $v;
        }
    };

    $p1 = $catalog->createProduct([
        'name' => 'Mt. Kanlaon Espresso Beans',
        'description' => 'Washed Arabica medium-dark roast harvested in Negros.',
        'brand_id' => $brandKape,
        'category_id' => $catCoffee,
        'status' => 'active',
        'supplier_id' => $roaster['id'],
        'attributes' => ['origin' => 'Mt. Kanlaon', 'roast' => 'Medium Dark'],
        'variants' => [
            ['sku' => 'KN-ESP-250', 'name' => '250g Bag', 'unit' => 'bag', 'retail_price' => '340.00', 'trade_price' => '285.00', 'reorder_level' => '12'],
            ['sku' => 'KN-ESP-1KG', 'name' => '1kg Roaster Bag', 'unit' => 'bag', 'retail_price' => '1180.00', 'trade_price' => '980.00', 'reorder_level' => '6', 'track_batches' => true],
        ],
    ], $actorId);
    $register($catalog->getProduct($p1['id']) ?? ['variants' => []]);

    $p2 = $catalog->createProduct([
        'name' => 'Barista House Blend',
        'description' => 'Balanced espresso and pour-over blend for daily service.',
        'brand_id' => $brandSaboris,
        'category_id' => $catCoffee,
        'status' => 'active',
        'supplier_id' => $roaster['id'],
        'attributes' => ['origin' => 'Bukidnon', 'roast' => 'Medium'],
        'variants' => [
            ['sku' => 'SB-HSB-500', 'name' => '500g Pouch', 'unit' => 'pouch', 'retail_price' => '520.00', 'trade_price' => '440.00', 'reorder_level' => '10', 'track_batches' => true],
        ],
    ], $actorId);
    $register($catalog->getProduct($p2['id']) ?? ['variants' => []]);

    $p3 = $catalog->createProduct([
        'name' => 'Loose Leaf Earl Grey',
        'description' => 'Black tea scented with bergamot oil, sold by weight.',
        'brand_id' => $brandSaboris,
        'category_id' => $catTea,
        'status' => 'active',
        'supplier_id' => $roaster['id'],
        'attributes' => ['caffeine' => 'High'],
        'variants' => [
            ['sku' => 'SB-EARL-KG', 'name' => 'Bulk Loose Leaf', 'unit' => 'kg', 'allow_decimal_qty' => true, 'qty_step' => '0.250', 'min_qty' => '0.250', 'retail_price' => '1480.00', 'reorder_level' => '3.000'],
        ],
    ], $actorId);
    $register($catalog->getProduct($p3['id']) ?? ['variants' => []]);

    $p4 = $catalog->createProduct([
        'name' => 'Muscovado Cane Syrup',
        'description' => 'Slow-simmered pure Negros muscovado simple syrup.',
        'brand_id' => $brandKape,
        'category_id' => $catSyrups,
        'status' => 'active',
        'supplier_id' => $roaster['id'],
        'attributes' => ['volume_ml' => 750],
        'variants' => [
            ['sku' => 'KN-SYR-750', 'name' => '750ml Glass Bottle', 'unit' => 'bottle', 'retail_price' => '265.00', 'trade_price' => '215.00', 'reorder_level' => '10', 'track_batches' => true],
        ],
    ], $actorId);
    $register($catalog->getProduct($p4['id']) ?? ['variants' => []]);

    $p5 = $catalog->createProduct([
        'name' => 'Unrefined Muscovado Sugar',
        'description' => 'Fine-milled cane sugar for baking and beverage prep.',
        'brand_id' => $brandKape,
        'category_id' => $catSyrups,
        'status' => 'active',
        'supplier_id' => $roaster['id'],
        'attributes' => ['grade' => 'Fine'],
        'variants' => [
            ['sku' => 'KN-SUG-1KG', 'name' => '1kg Pack', 'unit' => 'pack', 'retail_price' => '145.00', 'reorder_level' => '20'],
        ],
    ], $actorId);
    $register($catalog->getProduct($p5['id']) ?? ['variants' => []]);

    $p6 = $catalog->createProduct([
        'name' => 'Conical Paper Filters (02)',
        'description' => 'Oxygen-whitened and natural kraft cone filters, 100 per pack.',
        'brand_id' => $brandTerra,
        'category_id' => $catBrew,
        'status' => 'active',
        'supplier_id' => $packaging['id'],
        'attributes' => ['size' => '02'],
        'variants' => [
            ['sku' => 'TC-FIL-WHT', 'name' => 'White 100ct', 'unit' => 'pack', 'retail_price' => '220.00', 'reorder_level' => '15'],
            ['sku' => 'TC-FIL-NAT', 'name' => 'Natural Kraft 100ct', 'unit' => 'pack', 'retail_price' => '210.00', 'reorder_level' => '12'],
        ],
    ], $actorId);
    $register($catalog->getProduct($p6['id']) ?? ['variants' => []]);

    $p7 = $catalog->createProduct([
        'name' => 'Double-Wall Takeaway Cups',
        'description' => 'Insulated kraft paper cups, 50 pieces per sleeve.',
        'brand_id' => $brandTerra,
        'category_id' => $catBrew,
        'status' => 'active',
        'supplier_id' => $packaging['id'],
        'attributes' => ['capacity_oz' => 12],
        'variants' => [
            ['sku' => 'TC-CUP-12OZ', 'name' => '12oz Sleeve of 50', 'unit' => 'sleeve', 'retail_price' => '310.00', 'trade_price' => '260.00', 'reorder_level' => '10'],
        ],
    ], $actorId);
    $register($catalog->getProduct($p7['id']) ?? ['variants' => []]);

    $p8 = $catalog->createProduct([
        'name' => 'Ceramic Pour-Over Dripper',
        'description' => 'Ribbed stoneware dripper engineered for steady extraction.',
        'brand_id' => $brandTerra,
        'category_id' => $catBrew,
        'status' => 'active',
        'supplier_id' => $packaging['id'],
        'attributes' => ['material' => 'Stoneware'],
        'variants' => [
            ['sku' => 'TC-DRP-BLK', 'name' => 'Matte Black', 'unit' => 'pc', 'retail_price' => '890.00', 'reorder_level' => '5'],
            // Stocked via manual adjustment (no PO receipt) so cost-completeness reflects 1 uncosted SKU.
            ['sku' => 'TC-DRP-WHT', 'name' => 'Warm White', 'unit' => 'pc', 'retail_price' => '890.00', 'reorder_level' => '4'],
        ],
    ], $actorId);
    $register($catalog->getProduct($p8['id']) ?? ['variants' => []]);

    // 6. Purchase orders & receipts across branches.
    $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
    $expSoon = $today->modify('+14 days')->format('Y-m-d');
    $expLater = $today->modify('+180 days')->format('Y-m-d');

    $poMain = $procurement->createPurchaseOrder([
        'supplier_id' => $roaster['id'],
        'branch_id' => $branchIds['BCD-MAIN'],
        'expected_on' => $today->format('Y-m-d'),
        'notes' => 'Weekly coffee and syrup restock for Bacolod Main.',
        'lines' => [
            ['variant_id' => $variants['KN-ESP-250']['id'], 'quantity' => '30', 'unit_cost' => '195.0000'],
            ['variant_id' => $variants['KN-ESP-1KG']['id'], 'quantity' => '12', 'unit_cost' => '710.0000'],
            ['variant_id' => $variants['SB-HSB-500']['id'], 'quantity' => '18', 'unit_cost' => '305.0000'],
            ['variant_id' => $variants['SB-EARL-KG']['id'], 'quantity' => '6.000', 'unit_cost' => '840.0000'],
            ['variant_id' => $variants['KN-SYR-750']['id'], 'quantity' => '16', 'unit_cost' => '142.0000'],
            ['variant_id' => $variants['KN-SUG-1KG']['id'], 'quantity' => '10', 'unit_cost' => '85.0000'],
        ],
    ], $actorId);
    $procurement->sendPurchaseOrder($poMain['id'], $actorId);
    $poMainFull = $procurement->getPurchaseOrder($poMain['id']);
    $poMainLines = array_column($poMainFull['lines'], null, 'sku');
    $procurement->receive($poMain['id'], [
        ['line_id' => $poMainLines['KN-ESP-250']['id'], 'qty_received' => '30', 'qty_rejected' => '0'],
        ['line_id' => $poMainLines['KN-ESP-1KG']['id'], 'qty_received' => '12', 'qty_rejected' => '0', 'batch_code' => 'ROAST-26A', 'expires_on' => $expLater, 'bin' => 'A-01'],
        ['line_id' => $poMainLines['SB-HSB-500']['id'], 'qty_received' => '18', 'qty_rejected' => '0', 'batch_code' => 'HSB-2604', 'expires_on' => $expLater, 'bin' => 'A-02'],
        ['line_id' => $poMainLines['SB-EARL-KG']['id'], 'qty_received' => '6.000', 'qty_rejected' => '0'],
        ['line_id' => $poMainLines['KN-SYR-750']['id'], 'qty_received' => '14', 'qty_rejected' => '2', 'batch_code' => 'SYR-EXP', 'expires_on' => $expSoon, 'bin' => 'B-03'],
        ['line_id' => $poMainLines['KN-SUG-1KG']['id'], 'qty_received' => '10', 'qty_rejected' => '0'],
    ], $actorId);

    $poWarehouse = $procurement->createPurchaseOrder([
        'supplier_id' => $packaging['id'],
        'branch_id' => $branchIds['TLS-WH'],
        'expected_on' => $today->format('Y-m-d'),
        'notes' => 'Warehouse bulk supplies and drippers.',
        'lines' => [
            ['variant_id' => $variants['TC-FIL-WHT']['id'], 'quantity' => '40', 'unit_cost' => '125.0000'],
            ['variant_id' => $variants['TC-FIL-NAT']['id'], 'quantity' => '8', 'unit_cost' => '118.0000'],
            ['variant_id' => $variants['TC-CUP-12OZ']['id'], 'quantity' => '35', 'unit_cost' => '178.0000'],
            ['variant_id' => $variants['TC-DRP-BLK']['id'], 'quantity' => '14', 'unit_cost' => '490.0000'],
        ],
    ], $actorId);
    $procurement->sendPurchaseOrder($poWarehouse['id'], $actorId);
    $poWhLines = array_column($procurement->getPurchaseOrder($poWarehouse['id'])['lines'], null, 'sku');
    $procurement->receive($poWarehouse['id'], [
        ['line_id' => $poWhLines['TC-FIL-WHT']['id'], 'qty_received' => '40', 'qty_rejected' => '0'],
        ['line_id' => $poWhLines['TC-FIL-NAT']['id'], 'qty_received' => '8', 'qty_rejected' => '0'],
        ['line_id' => $poWhLines['TC-CUP-12OZ']['id'], 'qty_received' => '35', 'qty_rejected' => '0'],
        ['line_id' => $poWhLines['TC-DRP-BLK']['id'], 'qty_received' => '14', 'qty_rejected' => '0'],
    ], $actorId);

    $poWhCoffee = $procurement->createPurchaseOrder([
        'supplier_id' => $roaster['id'],
        'branch_id' => $branchIds['TLS-WH'],
        'expected_on' => $today->format('Y-m-d'),
        'notes' => 'Warehouse stock for delivery orders.',
        'lines' => [
            ['variant_id' => $variants['KN-ESP-250']['id'], 'quantity' => '25', 'unit_cost' => '195.0000'],
            ['variant_id' => $variants['KN-SUG-1KG']['id'], 'quantity' => '25', 'unit_cost' => '85.0000'],
        ],
    ], $actorId);
    $procurement->sendPurchaseOrder($poWhCoffee['id'], $actorId);
    $poWhCoffeeLines = array_column($procurement->getPurchaseOrder($poWhCoffee['id'])['lines'], null, 'sku');
    $procurement->receive($poWhCoffee['id'], [
        ['line_id' => $poWhCoffeeLines['KN-ESP-250']['id'], 'qty_received' => '25', 'qty_rejected' => '0'],
        ['line_id' => $poWhCoffeeLines['KN-SUG-1KG']['id'], 'qty_received' => '25', 'qty_rejected' => '0'],
    ], $actorId);

    $poSilay = $procurement->createPurchaseOrder([
        'supplier_id' => $packaging['id'],
        'branch_id' => $branchIds['SLY-HUB'],
        'expected_on' => $today->format('Y-m-d'),
        'notes' => 'Silay hub starter supplies.',
        'lines' => [
            ['variant_id' => $variants['TC-CUP-12OZ']['id'], 'quantity' => '15', 'unit_cost' => '178.0000'],
            ['variant_id' => $variants['TC-FIL-WHT']['id'], 'quantity' => '12', 'unit_cost' => '125.0000'],
        ],
    ], $actorId);
    $procurement->sendPurchaseOrder($poSilay['id'], $actorId);
    $poSilayLines = array_column($procurement->getPurchaseOrder($poSilay['id'])['lines'], null, 'sku');
    $procurement->receive($poSilay['id'], [
        ['line_id' => $poSilayLines['TC-CUP-12OZ']['id'], 'qty_received' => '15', 'qty_rejected' => '0'],
        ['line_id' => $poSilayLines['TC-FIL-WHT']['id'], 'qty_received' => '12', 'qty_rejected' => '0'],
    ], $actorId);

    // Open sent PO (not yet received) so reorder suggestions demonstrate the on-order column.
    $poOpen = $procurement->createPurchaseOrder([
        'supplier_id' => $packaging['id'],
        'branch_id' => $branchIds['BCD-MAIN'],
        'expected_on' => $today->modify('+5 days')->format('Y-m-d'),
        'notes' => 'Incoming filter replenishment for Bacolod Main.',
        'lines' => [
            ['variant_id' => $variants['TC-FIL-NAT']['id'], 'quantity' => '20', 'unit_cost' => '118.0000'],
        ],
    ], $actorId);
    $procurement->sendPurchaseOrder($poOpen['id'], $actorId);

    // 7. Manual adjustments and write-offs (reason required on both).
    $inventory->adjust($branchIds['BCD-MAIN'], $variants['TC-DRP-WHT']['id'], '3', 'Opening shelf count reconciliation', $actorId);
    $inventory->adjust($branchIds['SLY-HUB'], $variants['KN-ESP-250']['id'], '2', 'Transferred sample bags from store opening', $actorId);
    $inventory->writeOff($branchIds['BCD-MAIN'], $variants['KN-SYR-750']['id'], '1', 'Glass bottle cracked during shelf restock', $actorId);

    // 8. Customers & orders across stages.
    $insertCustomer = $organization->prepare(
        "INSERT INTO customers (full_name, email, phone, customer_type, tier, status)
         VALUES (:full_name, :email, :phone, :customer_type, :tier, 'active')
         RETURNING id, full_name, email, phone, customer_type, tier, status",
    );
    $insertCustomer->execute([
        'full_name' => 'Mara Villanueva',
        'email' => 'mara.villanueva@example.test',
        'phone' => '+63 917 555 0101',
        'customer_type' => 'Retail',
        'tier' => 'Standard',
    ]);
    $custMara = $insertCustomer->fetch(\PDO::FETCH_ASSOC);

    $insertCustomer->execute([
        'full_name' => 'Carlo Mendoza',
        'email' => 'carlo.mendoza@example.test',
        'phone' => '+63 917 555 0102',
        'customer_type' => 'Cafe Partner',
        'tier' => 'Gold',
    ]);
    $custCarlo = $insertCustomer->fetch(\PDO::FETCH_ASSOC);

    $insertCustomer->execute([
        'full_name' => 'Elena Reyes',
        'email' => 'elena.reyes@example.test',
        'phone' => '+63 917 555 0103',
        'customer_type' => 'Retail',
        'tier' => 'Standard',
    ]);
    $custElena = $insertCustomer->fetch(\PDO::FETCH_ASSOC);

    // Order 1: Completed & paid pickup order at Bacolod Main.
    $ord1 = $orders->createOrder(
        $custMara,
        [
            ['variant_id' => $variants['KN-ESP-250']['id'], 'quantity' => '3'],
            ['variant_id' => $variants['KN-SYR-750']['id'], 'quantity' => '2'],
        ],
        ['method' => 'pickup', 'branch_id' => $branchIds['BCD-MAIN']],
        [],
        $actorId,
        'online',
    );
    $orders->advance($ord1['id'], 'confirmed', $actorId);
    $orders->advance($ord1['id'], 'ready', $actorId);
    $orders->advance($ord1['id'], 'completed', $actorId);
    $orders->recordPayment($ord1['id'], ['method' => 'cash', 'amount' => (string) $ord1['total']], $actorId);

    // Order 2: Completed & GCash-paid delivery order from Talisay Warehouse.
    $ord2 = $orders->createOrder(
        $custCarlo,
        [
            ['variant_id' => $variants['TC-FIL-WHT']['id'], 'quantity' => '5'],
            ['variant_id' => $variants['TC-DRP-BLK']['id'], 'quantity' => '2'],
        ],
        [
            'method' => 'delivery',
            'line1' => '45BS Aquino Drive, Brgy. Villamonte',
            'city' => 'Bacolod City',
            'province' => 'Negros Occidental',
            'recipient' => 'Carlo Mendoza',
            'phone' => '+63 917 555 0102',
        ],
        [],
        $actorId,
        'staff',
    );
    $orders->advance($ord2['id'], 'confirmed', $actorId);
    $orders->advance($ord2['id'], 'out_for_delivery', $actorId);
    $orders->advance($ord2['id'], 'completed', $actorId);
    $orders->recordPayment($ord2['id'], ['method' => 'gcash', 'reference' => 'GC-202611-0042', 'amount' => (string) $ord2['total']], $actorId);

    // Order 3: In-progress pickup order at Silay Hub (partially paid / awaiting pickup).
    $ord3 = $orders->createOrder(
        $custElena,
        [
            ['variant_id' => $variants['TC-CUP-12OZ']['id'], 'quantity' => '2'],
        ],
        ['method' => 'pickup', 'branch_id' => $branchIds['SLY-HUB']],
        [],
        $actorId,
        'online',
    );
    $orders->advance($ord3['id'], 'confirmed', $actorId);

    // 9. POS shifts and sales (two completed sales on closed T1 shift, one needs_review oversell on T2).
    $shift1 = $pos->openShift($terminal1Id, $cashierId, '2000.00');
    $shift1Id = (string) $shift1['shift']['id'];

    $pos->syncSale([
        'id' => Uuid::v4(),
        'receipt_number' => 'T1-000001',
        'terminal_id' => $terminal1Id,
        'shift_id' => $shift1Id,
        'sold_at' => $today->format('Y-m-d\TH:i:sP'),
        'customer_id' => $custMara['id'],
        'lines' => [
            ['variant_id' => $variants['KN-ESP-250']['id'], 'quantity' => '2', 'unit_price' => '340.00'],
            ['variant_id' => $variants['KN-SUG-1KG']['id'], 'quantity' => '2', 'unit_price' => '145.00'],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => '970.00'],
        ],
    ]);

    $pos->syncSale([
        'id' => Uuid::v4(),
        'receipt_number' => 'T1-000002',
        'terminal_id' => $terminal1Id,
        'shift_id' => $shift1Id,
        'sold_at' => $today->format('Y-m-d\TH:i:sP'),
        'lines' => [
            ['variant_id' => $variants['SB-EARL-KG']['id'], 'quantity' => '0.500', 'unit_price' => '1480.00'],
            ['variant_id' => $variants['KN-ESP-1KG']['id'], 'quantity' => '1', 'unit_price' => '1180.00'],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => '1920.00'],
        ],
    ]);
    $pos->closeShift($shift1Id, '4890.00', $cashierId);

    // Terminal 2 open shift with a deliberate offline oversell (4 sold vs 2 on hand at Silay).
    $shift2 = $pos->openShift($terminal2Id, $cashierId, '1000.00');
    $shift2Id = (string) $shift2['shift']['id'];
    $pos->syncSale([
        'id' => Uuid::v4(),
        'receipt_number' => 'T2-000001',
        'terminal_id' => $terminal2Id,
        'shift_id' => $shift2Id,
        'sold_at' => $today->format('Y-m-d\TH:i:sP'),
        'lines' => [
            ['variant_id' => $variants['KN-ESP-250']['id'], 'quantity' => '4', 'unit_price' => '340.00'],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => '1360.00'],
        ],
    ]);
};
