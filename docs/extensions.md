# Extensions (Stage 4)

Extensions add optional or organization-specific behavior without changing `core/` or `templates/`. Every extension has a manifest (`extension.json`) and a PHP entry file that registers hook callbacks through `ExtensionContext`.

## Where extensions live and which wins

| Folder | Scope | When it loads |
| --- | --- | --- |
| `extensions/{key}/` | Shared across organizations | Only when the organization has the matching feature switched on (`cold_chain` enables `extensions/cold-chain/`). A disabled shared extension is not opened |
| `orgs/{slug}/extensions/{key}/` | Local to one organization | Always loaded for `{slug}`, never for any other organization |

Extensions are discovered in alphabetical order (`extensions/` first, then `orgs/{slug}/extensions/`). If both folders have an extension with the same `key`, the **organization-local extension wins** and completely replaces the shared one for that organization.

Each organization has its own `Hooks` and `SettingsService` instance inside `Services::forTenant()`, so one organization's extensions never affect another organization.

## `extension.json` fields

`extension.json` must be a JSON object with no unknown fields.

| Field | Required | Rules |
| --- | --- | --- |
| `key` | Yes | 2 to 41 characters (`^[a-z][a-z0-9-]{1,40}$`): lower-case letters, digits and hyphens, starting with a letter. Must equal the extension's folder name |
| `name` | Yes | Non-empty text, at most 80 characters |
| `version` | Yes | Three dot-separated numbers (`^\d{1,4}\.\d{1,4}\.\d{1,4}$`), such as `1.0.0` |
| `description` | No | Text, at most 300 characters (defaults to `""`) |
| `entry` | Yes | Plain PHP file name in the extension folder (`^[A-Za-z0-9_-]+\.php$`), such as `register.php`. Must exist inside the extension folder; no slashes, paths or surrounding whitespace |
| `hooks` | No | JSON list of hook names from `Hooks::NAMES` that this extension may register |
| `settings` | No | JSON object mapping setting keys to setting definitions (see below) |
| `namespace` | No | JSON object mapping PSR-4 namespace prefixes (ending with `\`, such as `Acme\\ColdChain\\`) to plain relative folders inside the extension (such as `src/`). Parsed and validated in Stage 4; wired to `Autoloader::addNamespace()` in Stage 8 |

Example:

```json
{
  "key": "cold-chain",
  "name": "Cold chain",
  "version": "1.2.0",
  "description": "Temperature checks for chilled goods.",
  "entry": "register.php",
  "hooks": ["stock.pick_strategy", "navigation.admin"],
  "settings": {
    "cold_chain.max_temp": {
      "default": 8,
      "type": "int",
      "min": 0,
      "max": 30,
      "label": "Highest allowed temperature"
    }
  },
  "namespace": {
    "Acme\\ColdChain\\": "src/"
  }
}
```

## Settings and setting types

Every setting key must start with the extension's key with hyphens replaced by underscores, followed by a dot (for example, extension `cold-chain` owns keys starting with `cold_chain.`), and match `^[a-z][a-z0-9_.]{2,60}$`.

Each setting definition is an object with only these fields:

| Field | Required | Rules |
| --- | --- | --- |
| `default` | Yes | Whole number (`int`), text (`string`) or `true`/`false` (`bool`) |
| `type` | No | `int`, `string` or `bool`. Defaults to the type of `default` and must match it |
| `min`, `max` | No | Whole numbers (`int` settings only). `min` cannot exceed `max`, and `default` must fall within `[min, max]` |
| `label` | No | Non-empty text up to 120 characters (defaults to the setting key) |

When the extension loads, its settings are registered on the organization's `SettingsService` with a validator (`ExtensionManifest::settingError()`):

- `bool`: accepts `true` or `false`.
- `string`: accepts strings up to 500 characters.
- `int`: accepts whole numbers (or 1–9 digit numeric strings) within `min` and `max`.

## Valid hook names

All valid hook names are listed in `Gfh\Core\Hooks\Hooks::NAMES`:

| Hook name | Constant | What it filters / does |
| --- | --- | --- |
| `navigation.admin` | `Hooks::NAVIGATION_ADMIN` | Header navigation items (`list<array{label: string, url: string, current?: bool}>`) in the Admin app. An item with a `module` is shown only to people who pass `can()` for it; add `action` (`view`, `edit` or `delete`, default `view`) when the page needs more than view |
| `navigation.shop` | `Hooks::NAVIGATION_SHOP` | Header navigation items (`list<array{label: string, url: string, current?: bool}>`) in the Shop app, after the built-in Shop link. See "Shop hooks" below |
| `product.form.tabs` | `Hooks::PRODUCT_FORM_TABS` | Extra tabs on the Admin product form. Each tab is `{id, label, contentHtml}`. The context has `product`, `can_edit`, `admin_base` (for example `/demo/admin`), `csrf` (the token for a form the tab posts), `errors` (field errors of a refused save) and `input` (the extension's own posted fields after a refused save, otherwise empty) |
| `product.form.save` | `Hooks::PRODUCT_FORM_SAVE` | Product form save payload / side effects. The value is the payload; the context has `product_id` (null when creating), `actor_id` and `current_attributes` (the stored attributes, `[]` when creating). Throw `HttpException::unprocessable()` to refuse. A tab form posts only its own field, so the payload holds only that field and the other product fields keep their stored values |
| `product.detail.sections` | `Hooks::PRODUCT_DETAIL_SECTIONS` | Extra sections (`list<array{id: string, title: string, bodyHtml: string}>`) on the Shop product detail page, after the attribute table. See "Shop hooks" below |
| `checkout.fields` | `Hooks::CHECKOUT_FIELDS` | Extra checkout fields returned by `GET /{org}/api/v1/checkout/options` |
| `checkout.adjustments` | `Hooks::CHECKOUT_ADJUSTMENTS` | Online cart fees (`delivery_fee`, `assembly_fee`, `installation_fee`), `deposit_due`, `promised_date` and `made_to_order_variants` in `PricingService` |
| `order.progress.steps` | `Hooks::ORDER_PROGRESS_STEPS` | The steps in an order's progress path (`context`: `order`, `all_steps`) in `OrderService` |
| `order.detail.panels` | `Hooks::ORDER_DETAIL_PANELS` | Extra panels on the Admin order detail view |
| `stock.pick_strategy` | `Hooks::STOCK_PICK_STRATEGY` | Batch picks (`list<array{batch_id: ?string, quantity_milli: int}>`) when issuing stock in `InventoryService`; may throw `HttpException` to refuse a sale |
| `validation.attributes` | `Hooks::VALIDATION_ATTRIBUTES` | Product attribute field errors (`attributes.{key}` => message) in `CatalogService` |
| `dashboard.widgets` | `Hooks::DASHBOARD_WIDGETS` | Extra dashboard widgets returned by `ReportService::dashboard()` |
| `reports.tabs` | `Hooks::REPORTS_TABS` | Extra tabs on the Admin Reports page. Each tab is `{id, label, contentHtml}`; `context` has `view` (`day`, `month` or `year`), `from`, `to` (`YYYY-MM-DD`, Asia/Manila) and `totals` of the chosen period. Only people who may view Reports see the tabs |

## Shop hooks (Batch 6.1)

**`navigation.shop`.** The value starts as `[['label' => 'Shop', 'url' => '/{org}/shop/products']]`; add items after it. The context has two text values only: `organization` (the slug) and `base_url` (where the Shop lives, for example `/demo/shop`; build your links from it). Each item needs a `label` and a `url` that starts with a single `/` (a path inside this site); an item with an empty label, an absolute address, a `//` address or a `javascript:` address is dropped, both by `ShopNavigationService` and again by `partials/nav.php`. `current` is set for you when the item's `url` equals the page's path; set it yourself to mark an item current in other cases.

**`product.detail.sections`.** The value starts as `[]`; return it with your sections added. Each section is `['id' => ..., 'title' => ..., 'bodyHtml' => ...]`.

- The context has `product` (the public product the page shows), `selected_variant_id` and `signed_in` (a boolean; the customer's identity and tier are not passed). The product has `id`, `name`, `slug`, `description`, `brand_id`, `brand_name`, `category_id`, `category_name`, `attributes`, `images` and `variants`. Each variant has only `id`, `sku`, `name`, `unit`, `unit_size`, `allow_decimal_qty`, `qty_step`, `min_qty`, `retail_price`, `in_stock` (a boolean) and `tiers` (empty for a guest). **No cost, trade price, reorder level, barcode or stock count is ever passed.**
- `title` is plain text and the page escapes it. A section without a title is dropped. `id` is reduced to letters, digits and hyphens (it becomes the section's `id` attribute and its anchor); an empty one becomes `section-N`. At most 10 sections are shown.
- `bodyHtml` (also accepted as `contentHtml`) is **printed as you wrote it, without escaping**, the same trust the Admin gives order panels. The extension must escape anything it prints that a product record, a customer or a request supplied (use `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`). Do not print costs; you were not given any, and must not look them up.

A hook callback has the signature `fn (mixed $value, array $context): mixed` and returns the modified `$value`. Lower priority numbers (`0` to `100`, default `10`) run first across all loaded extensions.

## Entry file and `ExtensionContext`

The entry file (`entry` in `extension.json`) must return a `Closure` that accepts `ExtensionContext`:

```php
<?php

declare(strict_types=1);

use Gfh\Core\Extension\ExtensionContext;

return function (ExtensionContext $x): void {
    $x->hook('navigation.admin', function (array $items) use ($x): array {
        $limit = $x->setting('cold_chain.max_temp');
        $items[] = ['label' => 'Cold chain (' . $limit . '°C)', 'url' => '/demo/admin/cold-chain'];
        return $items;
    }, 10);
};
```

`ExtensionContext` provides:

- `$x->key(): string` — the extension's key.
- `$x->path(string $file = ''): string` — absolute path inside the extension's own folder (refuses `..`, leading slashes or unsafe segments).
- `$x->hook(string $name, callable $callback, int $priority = 10): void` — queues a callback for a hook listed in the manifest's `hooks` array, with priority `0` to `100`.
- `$x->setting(string $key): mixed` — reads one of this extension's declared settings from `SettingsService`. Call this **inside a hook callback**, never while the entry file is still registering.

## What the loader does with a broken extension

`ExtensionLoader` isolates every extension:

1. Output buffered during `require` of the entry file is discarded (`ob_start()` / `ob_end_clean()`).
2. Hook registrations and setting definitions are staged in `ExtensionContext` and only committed to `Hooks` and `SettingsService` **after** the entry file finishes without throwing. An extension that fails halfway leaves no hooks or settings behind.
3. Any `\Throwable` (missing or invalid `extension.json`, unknown field or hook, registering an undeclared hook, reading a setting during registration, a non-Closure return value, a `ParseError` or runtime exception) is caught.
4. The failure is recorded in the loader's report (`['loaded' => [...], 'failed' => [$key => $reason]]`, accessible via `Services::extensionReport($slug)`) and logged once through the application logger. All other extensions and the site continue to run normally.

## Extension-owned product attributes (Batch 8.2)

The Migrator runs only the `core` and `org` migrations, so an extension has no table of its own yet. The place for a per-product value is the product's attributes, under a key that starts with `ext_` (`Gfh\Core\Support\ExtensionAttributes::PREFIX`). Core treats such a key as private to extensions:

- The Admin attribute list does not show it, and a name that starts with `ext_` typed there is refused (it would vanish).
- Saving the Admin attribute list keeps the stored `ext_` values. An extension changes one through `product.form.save`: take the complete set from `$data['attributes']` or, when the form posted none, from `$context['current_attributes']`, set the key, and return it in `$data['attributes']`. An empty value removes the key.
- The Shop attribute table and the Shop filters never list it, and the public API (`/api/v1/catalog/products/{slug}`) leaves it out. `product.detail.sections` receives the product with it in `attributes`, so the extension that owns the key can print it.
- It stays in the record when the extension is switched off and is then shown by nothing.

**Nothing secret goes in an `ext_` attribute.** It is public data that reaches Shop hooks with the product, and Admin users with the right permission can read and write it through the product API (`attributes` in `PUT /api/v1/admin/products/{id}`). A value is at most 200 characters, like any attribute.

The demonstration extension `extensions/care-notes/` uses `ext_care_notes`. `docs/extension-guide.md` shows how to write and switch on an extension.
