<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Support\TenantServices;
use Gfh\Core\Support\Uuid;

/**
 * Seed 0002 (batch 8.1a). Adds 16 products to the 8 from seed 0001, stocks them with ordinary purchase orders and
 * receipts, and gives all 24 products a generated photo stored through MediaService.
 *
 * It only runs for the organization "demo" and does nothing when its anchor SKU already exists.
 */
final class DemoCatalogSeeder
{
    public const ANCHOR_SKU = 'IC-RCK-18W';

    /** Products that get a second photo. */
    private const SECOND_PHOTO = ['Digital Rice Cooker 1.8L', 'Rattan Lounge Chair', 'Platform Bed Frame', 'Dried Mango Cheeks', '16-inch Stand Fan', 'Capiz Pendant Lamp'];

    private TenantServices $services;

    private ?string $actorId;

    public function __construct(private readonly \PDO $organization, private readonly \PDO $core, private readonly string $uploadRoot)
    {
    }

    public function run(?string $slug): void
    {
        if ($slug !== 'demo') {
            throw new \RuntimeException('The demo catalog seed only runs for the organization "demo".');
        }
        if ($this->organization->query('SELECT 1 FROM product_variants WHERE sku = ' . $this->organization->quote(self::ANCHOR_SKU))->fetchColumn() !== false) {
            return;
        }
        if ($this->organization->query("SELECT 1 FROM branches WHERE code = 'BCD-MAIN'")->fetchColumn() === false) {
            throw new \RuntimeException('Run seed 0001 first: the demo branches do not exist yet.');
        }

        $found = $this->core->query("SELECT m.user_id FROM memberships m JOIN organizations o ON o.id = m.organization_id WHERE o.slug = 'demo' AND m.status = 'active' ORDER BY m.created_at ASC LIMIT 1")->fetchColumn();
        $this->actorId = is_string($found) && Uuid::isValid($found) ? $found : null;
        $this->services = new TenantServices($this->organization, new AuditService($this->core), new Hooks(), $this->uploadRoot, 'demo');

        $created = $this->createCatalog();
        $this->stockNewVariants($created);
        $this->attachPhotos();
    }

    /** @return list<array{sku: string, variant_id: string, supplier: string, retail: float, cost: float, batch: bool, decimal: bool, food: bool}> */
    private function createCatalog(): array
    {
        $catalog = $this->services->catalog();
        $procurement = $this->services->procurement();

        $brand = [];
        foreach (['Kape Negros', 'Saboris'] as $name) {
            $brand[$name] = $this->lookup('brands', $name);
        }
        foreach ([['Isla Cook', 'Isla Cook Appliances'], ['Tala Home', 'Tala Home Goods'], ['Habi Living', 'Habi Living Furniture'], ['Banig Decor', 'Banig Decor Crafts'], ['Sampaguita Pantry', 'Sampaguita Pantry Foods']] as [$name, $maker]) {
            $brand[$name] = $catalog->createBrand($name, $maker, $this->actorId)['id'];
        }

        $cat = [
            'Syrups & Sweeteners' => $this->lookup('categories', 'Syrups & Sweeteners'),
            'Snacks & Preserves' => $catalog->createCategory($this->lookup('categories', 'Pantry & Supplies'), 'Snacks & Preserves', $this->actorId)['id'],
        ];
        $appliances = $catalog->createCategory(null, 'Home Appliances', $this->actorId)['id'];
        $furniture = $catalog->createCategory(null, 'Furniture & Decor', $this->actorId)['id'];
        foreach (['Kitchen Appliances', 'Fans & Cooling', 'Home Care'] as $name) {
            $cat[$name] = $catalog->createCategory($appliances, $name, $this->actorId)['id'];
        }
        foreach (['Living Room', 'Bedroom', 'Lighting & Decor'] as $name) {
            $cat[$name] = $catalog->createCategory($furniture, $name, $this->actorId)['id'];
        }

        $supplier = [];
        foreach ([
            ['food', 'Negros Food Ingredients Trading', 'Rosario Tan', 'sales@negrosfood.example.test', '+63 34 433 2010', 'Brgy. Alijis, Bacolod City', 'Net 15', 4],
            ['appliance', 'Western Visayas Appliance Trading', 'Dennis Uy', 'orders@wvappliance.example.test', '+63 33 321 7788', 'Diversion Road, Iloilo City', 'Net 30', 10],
            ['furniture', 'Panay Furnishings Co.', 'Lourdes Ferrer', 'sales@panayfurnish.example.test', '+63 36 621 4590', 'Roxas City, Capiz', 'Net 30', 14],
        ] as [$key, $name, $contact, $email, $phone, $address, $terms, $lead]) {
            $supplier[$key] = $procurement->createSupplier(['name' => $name, 'contact_name' => $contact, 'contact_email' => $email, 'contact_phone' => $phone, 'address' => $address, 'payment_terms' => $terms, 'lead_time_days' => $lead], $this->actorId)['id'];
        }

        $created = [];
        foreach ($this->products() as $p) {
            $variants = [];
            foreach ($p['variants'] as $v) {
                $row = ['sku' => $v['sku'], 'name' => $v['name'], 'unit' => $v['unit'] ?? 'pc', 'retail_price' => number_format($v['retail'], 2, '.', ''), 'trade_price' => number_format(round($v['retail'] * 0.85, 0), 2, '.', ''), 'reorder_level' => (string) $v['reorder']];
                if (!empty($v['batch'])) {
                    $row['track_batches'] = true;
                }
                if (!empty($v['decimal'])) {
                    $row += ['allow_decimal_qty' => true, 'qty_step' => '0.100', 'min_qty' => '0.200'];
                }
                $variants[] = $row;
            }
            $product = $catalog->createProduct([
                'name' => $p['name'],
                'description' => $p['description'],
                'brand_id' => $brand[$p['brand']],
                'category_id' => $cat[$p['category']],
                'status' => 'active',
                'supplier_id' => $supplier[$p['supplier']],
                'attributes' => $p['attributes'],
                'variants' => $variants,
            ], $this->actorId);
            $bySku = array_column($catalog->getProduct($product['id'])['variants'] ?? [], null, 'sku');
            foreach ($p['variants'] as $v) {
                $id = $bySku[$v['sku']]['id'];
                if (isset($p['tier'][$v['sku']])) {
                    [$min, $price] = $p['tier'][$v['sku']];
                    $catalog->saveTier($id, ['basis' => 'quantity', 'label' => 'Buy ' . $min . ' or more', 'min_qty' => (string) $min, 'price' => number_format($price, 2, '.', '')], null, $this->actorId);
                }
                $created[] = ['sku' => $v['sku'], 'variant_id' => $id, 'supplier' => $supplier[$p['supplier']], 'retail' => $v['retail'], 'cost' => $v['cost'], 'batch' => !empty($v['batch']), 'decimal' => !empty($v['decimal']), 'food' => $p['supplier'] === 'food'];
            }
        }

        return $created;
    }

    /** One purchase order per supplier and branch, sent and received in full. */
    private function stockNewVariants(array $created): void
    {
        $procurement = $this->services->procurement();
        $branches = [];
        foreach ($this->organization->query('SELECT code, id FROM branches')->fetchAll(\PDO::FETCH_KEY_PAIR) as $code => $id) {
            $branches[$code] = $id;
        }
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));

        foreach (['TLS-WH' => 1.0, 'BCD-MAIN' => 0.4, 'SLY-HUB' => 0.25] as $code => $factor) {
            $bySupplier = [];
            foreach ($created as $c) {
                // The Silay hub only stocks food and drink lines.
                if ($code === 'SLY-HUB' && !$c['food']) {
                    continue;
                }
                $bySupplier[$c['supplier']][] = $c;
            }
            foreach ($bySupplier as $supplierId => $items) {
                $lines = [];
                $quantities = [];
                foreach ($items as $c) {
                    $base = $c['decimal'] ? 30 : ($c['retail'] < 500 ? 60 : ($c['retail'] < 2000 ? 24 : ($c['retail'] < 6000 ? 10 : 6)));
                    $qty = max(2, (int) round($base * $factor));
                    $quantities[$c['sku']] = $c['decimal'] ? $qty . '.000' : (string) $qty;
                    $lines[] = ['variant_id' => $c['variant_id'], 'quantity' => $quantities[$c['sku']], 'unit_cost' => number_format($c['cost'], 4, '.', '')];
                }
                $po = $procurement->createPurchaseOrder(['supplier_id' => $supplierId, 'branch_id' => $branches[$code], 'expected_on' => $today->format('Y-m-d'), 'notes' => 'Opening stock for the new catalog lines.', 'lines' => $lines], $this->actorId);
                $procurement->sendPurchaseOrder($po['id'], $this->actorId);
                $poLines = array_column($procurement->getPurchaseOrder($po['id'])['lines'], null, 'sku');
                $receive = [];
                foreach ($items as $index => $c) {
                    $entry = ['line_id' => $poLines[$c['sku']]['id'], 'qty_received' => $quantities[$c['sku']], 'qty_rejected' => '0'];
                    if ($c['batch']) {
                        $entry += ['batch_code' => 'L' . $today->format('ymd') . '-' . substr($code, 0, 3) . '-' . ($index + 1), 'expires_on' => $today->modify('+' . (150 + ($index * 37) % 200) . ' days')->format('Y-m-d'), 'bin' => 'F-' . sprintf('%02d', $index + 1)];
                    }
                    $receive[] = $entry;
                }
                $procurement->receive($po['id'], $receive, $this->actorId);
            }
        }
    }

    /** Draws and attaches a photo for every product that has none yet. */
    private function attachPhotos(): void
    {
        $products = $this->organization->query(
            "SELECT p.id, p.name, COALESCE(parent.name, c.name) AS root
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN categories parent ON parent.id = c.parent_id
              WHERE NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)
              ORDER BY p.name",
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($products as $product) {
            $line = match ($product['root']) {
                'Home Appliances' => 'ha',
                'Furniture & Decor' => 'fd',
                default => 'fb',
            };
            $count = in_array($product['name'], self::SECOND_PHOTO, true) ? 2 : 1;
            for ($variant = 0; $variant < $count; $variant++) {
                $this->attach($product['id'], $product['name'], DemoProductPhoto::render($product['name'], $line, $variant), $variant);
            }
        }
    }

    private function attach(string $productId, string $name, string $jpeg, int $variant): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'gfhphoto');
        if ($temp === false || file_put_contents($temp, $jpeg) === false) {
            throw new \RuntimeException('Could not write a temporary product photo.');
        }
        try {
            $media = $this->services->media()->upload($temp, 'product-photo-' . ($variant + 1) . '.jpg', $name . ($variant === 0 ? '' : ' (second view)'), $this->actorId);
            $this->services->catalog()->attachImage($productId, $media['id'], null, false);
        } finally {
            @unlink($temp);
        }
    }

    private function lookup(string $table, string $name): string
    {
        $statement = $this->organization->prepare('SELECT id FROM ' . $table . ' WHERE name = :name ORDER BY created_at LIMIT 1');
        $statement->execute(['name' => $name]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('Run seed 0001 first: ' . $name . ' is missing.');
        }

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        $food = static fn (array $extra): array => $extra + ['supplier' => 'food'];

        return [
            $food(['name' => 'Dried Mango Cheeks', 'description' => 'Sun-sweet dried mango, no added colouring. Sold packed or loose by weight.', 'brand' => 'Sampaguita Pantry', 'category' => 'Snacks & Preserves', 'attributes' => ['shelf_life_days' => 180], 'tier' => ['SP-MNG-100' => [6, 150]], 'variants' => [
                ['sku' => 'SP-MNG-100', 'name' => '100g Pack', 'retail' => 165, 'cost' => 98, 'reorder' => 24, 'batch' => true],
                ['sku' => 'SP-MNG-KG', 'name' => 'Bulk per kg', 'unit' => 'kg', 'retail' => 1180, 'cost' => 720, 'reorder' => 4, 'batch' => true, 'decimal' => true],
            ]]),
            $food(['name' => 'Pili Nut Brittle', 'description' => 'Crunchy pili nut brittle in a gift box.', 'brand' => 'Sampaguita Pantry', 'category' => 'Snacks & Preserves', 'attributes' => ['allergens' => 'Tree nuts'], 'tier' => [], 'variants' => [
                ['sku' => 'SP-PIL-200', 'name' => '200g Box', 'unit' => 'box', 'retail' => 285, 'cost' => 170, 'reorder' => 12, 'batch' => true],
            ]]),
            $food(['name' => 'Raw Forest Honey', 'description' => 'Unheated honey from the Negros highlands.', 'brand' => 'Kape Negros', 'category' => 'Syrups & Sweeteners', 'attributes' => ['origin' => 'Negros Occidental'], 'tier' => ['KN-HON-350' => [3, 295]], 'variants' => [
                ['sku' => 'KN-HON-350', 'name' => '350ml Jar', 'unit' => 'jar', 'retail' => 320, 'cost' => 190, 'reorder' => 12, 'batch' => true],
                ['sku' => 'KN-HON-750', 'name' => '750ml Jar', 'unit' => 'jar', 'retail' => 590, 'cost' => 360, 'reorder' => 8, 'batch' => true],
            ]]),
            $food(['name' => 'Calamansi Concentrate', 'description' => 'Bottled calamansi juice concentrate for drinks and marinades.', 'brand' => 'Saboris', 'category' => 'Syrups & Sweeteners', 'attributes' => ['volume_ml' => 500], 'tier' => [], 'variants' => [
                ['sku' => 'SB-CAL-500', 'name' => '500ml Bottle', 'unit' => 'bottle', 'retail' => 198, 'cost' => 112, 'reorder' => 15, 'batch' => true],
            ]]),
            ['supplier' => 'appliance', 'name' => 'Digital Rice Cooker 1.8L', 'description' => 'Ten-cup digital rice cooker with keep-warm and steam tray.', 'brand' => 'Isla Cook', 'category' => 'Kitchen Appliances', 'attributes' => ['capacity_l' => 1.8, 'wattage' => 700], 'tier' => [], 'variants' => [
                ['sku' => self::ANCHOR_SKU, 'name' => 'White', 'retail' => 2450, 'cost' => 1690, 'reorder' => 4],
                ['sku' => 'IC-RCK-18G', 'name' => 'Graphite', 'retail' => 2550, 'cost' => 1760, 'reorder' => 3],
            ]],
            ['supplier' => 'appliance', 'name' => 'Electric Kettle 1.7L', 'description' => 'Stainless steel cordless kettle with auto shut-off.', 'brand' => 'Isla Cook', 'category' => 'Kitchen Appliances', 'attributes' => ['capacity_l' => 1.7, 'wattage' => 1850], 'tier' => [], 'variants' => [
                ['sku' => 'IC-KTL-17', 'name' => 'Stainless', 'retail' => 1290, 'cost' => 880, 'reorder' => 6],
            ]],
            ['supplier' => 'appliance', 'name' => 'Air Fryer 4.5L', 'description' => 'Digital air fryer with a removable basket and eight presets.', 'brand' => 'Isla Cook', 'category' => 'Kitchen Appliances', 'attributes' => ['capacity_l' => 4.5, 'wattage' => 1500], 'tier' => ['IC-AFR-45' => [2, 4690]], 'variants' => [
                ['sku' => 'IC-AFR-45', 'name' => 'Black', 'retail' => 4990, 'cost' => 3500, 'reorder' => 3],
            ]],
            ['supplier' => 'appliance', 'name' => 'Countertop Blender 1.5L', 'description' => 'Glass jar blender with six speeds and a pulse button.', 'brand' => 'Isla Cook', 'category' => 'Kitchen Appliances', 'attributes' => ['capacity_l' => 1.5, 'wattage' => 600], 'tier' => [], 'variants' => [
                ['sku' => 'IC-BLD-15', 'name' => 'Glass Jar', 'retail' => 2190, 'cost' => 1500, 'reorder' => 4],
            ]],
            ['supplier' => 'appliance', 'name' => '16-inch Stand Fan', 'description' => 'Three-speed oscillating stand fan, height adjustable.', 'brand' => 'Tala Home', 'category' => 'Fans & Cooling', 'attributes' => ['blade_inch' => 16, 'wattage' => 55], 'tier' => [], 'variants' => [
                ['sku' => 'TH-FAN-16', 'name' => 'Standard', 'retail' => 1890, 'cost' => 1290, 'reorder' => 5],
                ['sku' => 'TH-FAN-16R', 'name' => 'With Remote', 'retail' => 2390, 'cost' => 1640, 'reorder' => 4],
            ]],
            ['supplier' => 'appliance', 'name' => 'Steam Iron 1600W', 'description' => 'Steam iron with a ceramic soleplate and self-clean function.', 'brand' => 'Tala Home', 'category' => 'Home Care', 'attributes' => ['wattage' => 1600], 'tier' => [], 'variants' => [
                ['sku' => 'TH-IRN-1600', 'name' => 'Ceramic Soleplate', 'retail' => 1150, 'cost' => 780, 'reorder' => 5],
            ]],
            ['supplier' => 'furniture', 'name' => 'Rattan Lounge Chair', 'description' => 'Hand-woven rattan lounge chair with a cushioned seat.', 'brand' => 'Habi Living', 'category' => 'Living Room', 'attributes' => ['material' => 'Rattan'], 'tier' => [], 'variants' => [
                ['sku' => 'HL-CHR-RAT', 'name' => 'Natural', 'retail' => 6800, 'cost' => 3900, 'reorder' => 2],
                ['sku' => 'HL-CHR-RTB', 'name' => 'Black Stain', 'retail' => 7200, 'cost' => 4150, 'reorder' => 2],
            ]],
            ['supplier' => 'furniture', 'name' => 'Solid Wood Coffee Table', 'description' => 'Mahogany coffee table with a walnut finish and a lower shelf.', 'brand' => 'Habi Living', 'category' => 'Living Room', 'attributes' => ['material' => 'Mahogany'], 'tier' => [], 'variants' => [
                ['sku' => 'HL-TBL-COF', 'name' => 'Walnut Finish', 'retail' => 8900, 'cost' => 5200, 'reorder' => 2],
            ]],
            ['supplier' => 'furniture', 'name' => 'Five-Tier Bookshelf', 'description' => 'Open bookshelf with five shelves, ships flat-packed.', 'brand' => 'Habi Living', 'category' => 'Living Room', 'attributes' => ['material' => 'Engineered wood'], 'tier' => [], 'variants' => [
                ['sku' => 'HL-SHF-5T', 'name' => 'Oak Finish', 'retail' => 5490, 'cost' => 3100, 'reorder' => 2],
            ]],
            ['supplier' => 'furniture', 'name' => 'Platform Bed Frame', 'description' => 'Low platform bed frame with a slatted base, no box spring needed.', 'brand' => 'Habi Living', 'category' => 'Bedroom', 'attributes' => ['material' => 'Mahogany'], 'tier' => [], 'variants' => [
                ['sku' => 'HL-BED-QN', 'name' => 'Queen', 'retail' => 14500, 'cost' => 8600, 'reorder' => 1],
                ['sku' => 'HL-BED-KG', 'name' => 'King', 'retail' => 17900, 'cost' => 10500, 'reorder' => 1],
            ]],
            ['supplier' => 'furniture', 'name' => 'Capiz Pendant Lamp', 'description' => 'Handcrafted capiz shell pendant lamp for dining and living areas.', 'brand' => 'Habi Living', 'category' => 'Lighting & Decor', 'attributes' => ['material' => 'Capiz shell'], 'tier' => ['HL-LMP-CAP' => [2, 2650]], 'variants' => [
                ['sku' => 'HL-LMP-CAP', 'name' => '40cm', 'retail' => 2850, 'cost' => 1500, 'reorder' => 3],
            ]],
            ['supplier' => 'furniture', 'name' => 'Woven Wall Basket Set', 'description' => 'Three woven baskets in graduated sizes for wall display.', 'brand' => 'Banig Decor', 'category' => 'Lighting & Decor', 'attributes' => ['material' => 'Buri palm'], 'tier' => [], 'variants' => [
                ['sku' => 'BD-BSK-SET3', 'name' => 'Set of 3', 'unit' => 'set', 'retail' => 1350, 'cost' => 720, 'reorder' => 6],
            ]],
        ];
    }
}
