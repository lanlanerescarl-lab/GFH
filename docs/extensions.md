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
| `account.menu` | `Hooks::ACCOUNT_MENU` | Items of the customer account menu in the Shop (`list<array{label: string, url: string, current?: bool}>`), after the built-in Profile, Addresses, Password and My orders. See "Shop hooks" below |
| `pos.receipt.lines` | `Hooks::POS_RECEIPT_LINES` | Extra lines on the POS receipt: a list of `{text, variant_id?}`. Run while the terminal snapshot is built, not when a sale is made. See "POS receipt lines" below |
| `product.form.tabs` | `Hooks::PRODUCT_FORM_TABS` | Extra tabs on the Admin product form. Each tab is `{id, label, contentHtml}`. The context has `product`, `can_edit`, `admin_base` (for example `/demo/admin`), `csrf` (the token for a form the tab posts), `errors` (field errors of a refused save) and `input` (the extension's own posted fields after a refused save, otherwise empty) |
| `product.form.save` | `Hooks::PRODUCT_FORM_SAVE` | Product form save payload / side effects. The value is the payload; the context has `product_id` (null when creating), `actor_id` and `current_attributes` (the stored attributes, `[]` when creating). Throw `HttpException::unprocessable()` to refuse. A tab form posts only its own field, so the payload holds only that field and the other product fields keep their stored values |
| `product.detail.sections` | `Hooks::PRODUCT_DETAIL_SECTIONS` | Extra sections (`list<array{id: string, title: string, bodyHtml: string}>`) on the Shop product detail page, after the attribute table. See "Shop hooks" below |
| `checkout.fields` | `Hooks::CHECKOUT_FIELDS` | Extra checkout fields (a list of field definitions, see "Checkout fields" below) returned by `GET /{org}/api/v1/checkout/options` and shown by the Shop checkout page |
| `checkout.adjustments` | `Hooks::CHECKOUT_ADJUSTMENTS` | Online cart fees (`delivery_fee`, `assembly_fee`, `installation_fee`), `deposit_due`, `promised_date` and `made_to_order_variants` in `PricingService`. The context has `checkout` = the checked values of the extension checkout fields (id => text or true/false, see "Checkout fields"), plus `items`, `fulfillment` and the other keys `PricingService` gives it |
| `order.progress.steps` | `Hooks::ORDER_PROGRESS_STEPS` | The steps in an order's progress path (`context`: `order`, `all_steps`) in `OrderService` |
| `order.detail.panels` | `Hooks::ORDER_DETAIL_PANELS` | Extra panels on the Admin order detail view |
| `stock.pick_strategy` | `Hooks::STOCK_PICK_STRATEGY` | Batch picks (`list<array{batch_id: ?string, quantity_milli: int}>`) when issuing stock in `InventoryService`; may throw `HttpException` to refuse a sale |
| `validation.attributes` | `Hooks::VALIDATION_ATTRIBUTES` | Product attribute field errors (`attributes.{key}` => message) in `CatalogService` |
| `dashboard.widgets` | `Hooks::DASHBOARD_WIDGETS` | Extra dashboard widgets returned by `ReportService::dashboard()` |
| `reports.tabs` | `Hooks::REPORTS_TABS` | Extra tabs on the Admin Reports page. Each tab is `{id, label, contentHtml}`; `context` has `view` (`day`, `month` or `year`), `from`, `to` (`YYYY-MM-DD`, Asia/Manila) and `totals` of the chosen period. Only people who may view Reports see the tabs |

## Shop hooks (Batch 6.1)

**`navigation.shop`.** The value starts as `[['label' => 'Shop', 'url' => '/{org}/shop/products']]`; add items after it. The context has two text values only: `organization` (the slug) and `base_url` (where the Shop lives, for example `/demo/shop`; build your links from it). Each item needs a `label` and a `url` that starts with a single `/` (a path inside this site); an item with an empty label, an absolute address, a `//` address or a `javascript:` address is dropped, both by `ShopNavigationService` and again by `partials/nav.php`. `current` is set for you when the item's `url` equals the page's path; set it yourself to mark an item current in other cases.

**`account.menu` (Batch 6.3b).** The account menu is shown on the signed-in customer's account pages (`/{org}/shop/account`, `/profile`, `/addresses`, `/password`). The value starts as the built-in items, in this order: `Profile` (`{base_url}/account/profile`), `Addresses` (`{base_url}/account/addresses`), `Password` (`{base_url}/account/password`) and `My orders` (`{base_url}/orders`). Return it with your items added after them. The context has two text values only, like `navigation.shop`: `organization` (the slug) and `base_url` (for example `/demo/shop`; build your links from it). Each item needs a `label` and a `url` that starts with a single `/`; an item with an empty label, a missing or non-text label or url, an absolute, `//`, `javascript:` or backslash address, or an address with a space is dropped, both by `ShopAccountMenuService` and again by `partials/account-menu.php`, and labels are escaped when printed. `current` is set for you when the item's `url` equals the page's path (an address's edit form counts as Addresses and an order as My orders); set it yourself to mark an item current in other cases. A page behind your link is yours to build, and it must check that the visitor is signed in itself. Two layers keep a broken extension from breaking the account pages: the extension loader isolates an entry file that throws while it loads, and `Hooks::filter` skips a callback that throws, or returns something that is not a list, when the menu is built, keeping the built-in items and the other extensions' items (see "When a hook callback fails" below). Since Batch 8.3 `ShopAccountMenuService` has no catch of its own.

**`product.detail.sections`.** The value starts as `[]`; return it with your sections added. Each section is `['id' => ..., 'title' => ..., 'bodyHtml' => ...]`.

- The context has `product` (the public product the page shows), `selected_variant_id` and `signed_in` (a boolean; the customer's identity and tier are not passed). The product has `id`, `name`, `slug`, `description`, `brand_id`, `brand_name`, `category_id`, `category_name`, `attributes`, `images` and `variants`. Each variant has only `id`, `sku`, `name`, `unit`, `unit_size`, `allow_decimal_qty`, `qty_step`, `min_qty`, `retail_price`, `in_stock` (a boolean) and `tiers` (empty for a guest). **No cost, trade price, reorder level, barcode or stock count is ever passed.**
- `title` is plain text and the page escapes it. A section without a title is dropped. `id` is reduced to letters, digits and hyphens (it becomes the section's `id` attribute and its anchor); an empty one becomes `section-N`. At most 10 sections are shown.
- `bodyHtml` (also accepted as `contentHtml`) is **printed as you wrote it, without escaping**, the same trust the Admin gives order panels. The extension must escape anything it prints that a product record, a customer or a request supplied (use `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`). Do not print costs; you were not given any, and must not look them up.

A hook callback has the signature `fn (mixed $value, array $context): mixed` and returns the modified `$value`. Lower priority numbers (`0` to `100`, default `10`) run first across all loaded extensions. A callback that throws or returns the wrong type is skipped, not fatal: see "When a hook callback fails" below.

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

This list is about **registering**. A callback that fails later, when a page runs the hook, is handled by `Hooks::filter()`: see "When a hook callback fails" below.

## When a hook callback fails (Batch 8.3)

`ExtensionLoader` (above) only covers the moment an extension registers its hooks. A callback can also fail later, when a page runs the hook. `Hooks::filter()` is the only place core calls hook callbacks, and it runs **each callback on its own**:

| What the callback does | What happens |
| --- | --- |
| Returns a value of the same type | The value is used and passed to the next callback. With nothing registered, or nothing failing, the result is exactly what it was before this rule existed |
| Throws `HttpException` (any status) | It passes through untouched to the caller, which turns it into the page or JSON error: a refused product save (422 with field messages), a refused sale. Later callbacks of that hook do not run. It is on purpose, not a failure, so it is not logged |
| Throws anything else (`Exception`, `Error`, `TypeError` from a wrong signature, `ArgumentCountError`, a PHP warning turned into an exception, `PDOException`) | The callback is **skipped**: the value from before it is kept, the next callbacks still run, and the page answers normally |
| Returns another type than it received (`null`, a string, a number or an object where the hook passes an array) | The result is **ignored** like a skipped callback: the value from before it is kept |

Types are compared as `array`, `string`, `int`, `float`, `bool`, `null` or `object`. A list may come back as a map and an empty array as a full one, because both are arrays. **No hook may change its type today** (`Hooks::MAY_CHANGE_TYPE` is empty); if one ever may, it is named there and in this table.

**The log.** A skipped or ignored callback writes one `ERROR` line to the application log (`storage/logs/app.log`), **once per request and per callback**, however often the hook runs in that request:

```
Hook callback skipped: navigation.shop (care-notes) RuntimeException: the message {"hook":"navigation.shop","extension":"care-notes","callback":"closure in register.php:14","organization":"demo"}
```

It names the hook, the extension (the manifest `key`; a callback added from code instead of an extension is named by its class and method, or by the file and line of its closure), the exception's class and its message, or `returned string where array was expected`. It carries **no stack trace** and **never the hook's value or context**, which can hold a customer's input. The exception message is cut to 300 characters, turned into one line, and credentials in an address (`scheme://user:pass@host`) and in `password=`, `secret=`, `token=`, `api_key=`, `csrf=`, `authorization=` or `cookie=` pairs are replaced by `***`. That is a safety net, not a licence: **put no secret and no customer input in an exception message.** The visitor sees no error text at all. If the log file cannot be written, the failure is skipped silently like any other.

**What this does not cover.**

- A fatal error, `exit()` and a time-out inside a callback cannot be caught by any PHP code.
- Core still checks what a hook returns where the content matters. For example `checkout.adjustments` returning a negative or non-numeric fee is refused with an error (HTTP 500) because core would otherwise charge a wrong price; the type check above does not look inside an array.
- A callback must not write to the database. A failed SQL statement inside the page's transaction aborts that transaction: the callback is skipped, but the request's own queries then fail too.
- A callback that is skipped changes what the page shows or does. A hook that you rely on for a rule (for example one that refuses a sale) must refuse with `HttpException`, never by throwing anything else, because anything else is skipped and the sale goes ahead.

## Checkout fields (Batch 8.3)

An extension adds fields to the Shop checkout page (a gift note, a company name, a delivery time slot, an "I agree" box) with the hook `checkout.fields`. The value is a list of field definitions; a callback adds its own and returns the whole list. `OrderService` reads it for `GET /checkout/options`, and the checkout page builds the fields from that answer.

**A field definition** (every key is plain text or a number; unknown keys are ignored):

| Key | Required | Meaning |
| --- | --- | --- |
| `id` | Yes | 1 to 40 lower-case letters, digits or underscores (`^[a-z0-9_]{1,40}$`). Unique in the list: the first field with an id wins. It is the key of the value in the `checkout` object |
| `label` | Yes | Plain text, 1 to 80 characters, shown next to the input (with "(required)" added for a required field) |
| `type` | Yes | `text` (one line), `textarea`, `select` or `checkbox` |
| `required` | No | `true` or `false` (default `false`). A required checkbox must be ticked |
| `options` | For `select` | 1 to 50 objects `{value, label}`: `value` plain text up to 100 characters and unique, `label` plain text up to 80. Ignored for the other types |
| `help` | No | Plain text up to 200 characters, shown under the input |
| `max_length` | No | Whole number 1 to 500, for `text` and `textarea` (default 150 and 500). Counts characters, not bytes. Ignored for `select` and `checkbox` |

At most **10** fields are shown. A definition that breaks any rule (an id with a capital letter or a dash, a missing label, a type that is not one of the four, a select without usable options, a duplicate id, `required` that is not a boolean, the eleventh field, an entry that is not an object) is **ignored, and logged once per request** as `Hook value problem: checkout.fields: ...` with the reason; the good fields still work and the customer sees nothing. A callback that throws, or returns something that is not a list, is isolated as described in "When a hook callback fails".

```php
return function (ExtensionContext $x): void {
    $x->hook('checkout.fields', static fn (array $fields): array => [
        ...$fields,
        ['id' => 'gift_note', 'label' => 'Gift note', 'type' => 'textarea', 'help' => 'We print it on a card.', 'max_length' => 120],
        ['id' => 'slot', 'label' => 'Delivery time', 'type' => 'select', 'required' => true,
            'options' => [['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon']]],
        ['id' => 'gift_wrap', 'label' => 'Gift wrap', 'type' => 'checkbox'],
    ]);

    // The checked values arrive in the context. A fee that depends on one is part of the price the customer sees.
    $x->hook('checkout.adjustments', static function (array $adjustments, array $context): array {
        if (($context['checkout']['gift_wrap'] ?? false) === true) {
            $adjustments['assembly_fee'] = '30.00';
        }

        return $adjustments;
    });
};
```

(The manifest lists both hook names in `hooks`.)

**What the server checks.** The page posts the values in the `checkout` object of `POST /checkout`, and the server checks them again whatever the browser did, before any order or reservation is written:

- An id that is not a declared field is refused (`checkout.{id}`: "This field is not part of the checkout."; a key that is not even a valid id is reported as `checkout` without echoing it). With no extension fields at all, every posted id is refused.
- A required field must have a value (a required checkbox must be ticked).
- Text is trimmed, must be a string, valid UTF-8, **plain text**: no control characters (a `textarea` may hold line breaks and tabs) and nothing that starts an HTML tag (`<b`, `</`, `<!`, `<?`). A `<` followed by a space or a digit is fine. Values are passed as typed (never rewritten) and must be escaped wherever an extension prints them.
- Length at most `max_length` characters.
- A `select` value must be exactly one of the option values (an empty choice is fine unless the field is required).
- A checkbox is `true`, `false`, `"1"`, `"0"`, `"on"`, `"true"`, `"false"`, `""` or absent; anything else is refused.
- All problems are returned at once as a 422 with `fields` keyed `checkout.{id}`, and nothing is created. The page shows each message under its field and in the error summary.

**What reaches `checkout.adjustments`.** The context key `checkout` is the checked values: **every declared id**, text as a trimmed string (`""` when blank), a checkbox as `true` or `false`. A value that failed a rule is never passed (it is blank). `POST /cart/price` (the price the page shows while the customer fills the form in) takes the same values but never fails: unknown ids are dropped and invalid values are blank, so a half-filled form still gets a price. Until Batch 8.3 `checkout` was the raw posted object; undeclared data no longer reaches the hook.

**The values are not stored with the order.** The orders schema has no place for per-order extension data, and Batch 8.3 adds no migration. A value reaches the hook that prices the order and is not saved afterwards: an extension that needs it on the order (a gift note to print) has to wait for a place to store it. Do not fake one with another column.

**The page.** The Shop checkout page is built by `checkout.js` from the options. It shows the fields in a "More about your order" group after the delivery choice, each with its label tied to its input, the help text and the error message tied with `aria-describedby`, using the same `.field`, `.choice`, `.help` and `.field-error` markup as the core fields. All text is placed as text, never as markup. The page sends the values in the `checkout` object it already sends, and prices the cart again when a field changes, so a fee that depends on a value is the one charged.

## POS receipt lines (Batch 8.3b)

An extension prints extra text on the POS receipt: the warranty period under a product, a line about claims under the totals. The hook is `pos.receipt.lines`. It is meant for text that depends on the product or the terminal, not on the sale.

**Why it runs when the snapshot is built.** Since Batch 7.2 the terminal builds its receipt in the browser, from the snapshot it already holds, at the moment the sale is saved, whether the sale is online or offline; the server builds no POS receipt and `GET /pos/sales/{id}` is not used to print. So the only place a server-side extension can add receipt text, without changing the sale API or the offline queue, is the snapshot. `PosService::snapshot()` (`GET /pos/terminals/{id}/snapshot`) runs the hook and adds the result as the snapshot key `receipt_lines`.

**The hook.** The value starts as `[]`. A callback adds lines and returns the list. A line is an object:

| Key | Required | Meaning |
| --- | --- | --- |
| `text` | Yes | One line of plain text, 1 to **120** characters (counted as characters, not bytes), no line break or other control character, nothing that starts an HTML tag (`<b`, `</`, `<!`, `<?`). Edge spaces are trimmed |
| `variant_id` | No | A variant in this snapshot. The line is printed under **every sale line of that variant**. Without it the line is printed **once, under the totals**, before the receipt's footer line |

At most **3** lines per variant and **3** under the totals; a repeated line for the same place is kept once. The context has `terminal` (`id`, `code`, `name`, `branch_id`, `branch_name`) and `variants`: every variant in the snapshot as `{variant_id, product_id, sku, name, attributes}`, where `attributes` holds **only the product's extension-owned `ext_` attributes** (see "Extension-owned product attributes"), never the rest of the catalog's attributes.

**The server enforces the limits.** A line that breaks a rule (too long, a tag or control character, a variant that is not in the snapshot, a fourth line) is dropped and logged as `Hook value problem: pos.receipt.lines: ...` (once per request for each dropped line, variant or footer); at most five are logged per snapshot and one more line counts the rest. The terminal never sees an error for it. The terminal repeats the same checks before it prints, so a damaged snapshot cannot put anything but short plain text on a receipt. A callback that throws, or returns something that is not a list, is skipped as described in "When a hook callback fails". A callback that throws an `HttpException` makes the snapshot go out **without any receipt lines** (and is logged): a refusal means nothing for a receipt line, and the terminal must always get its prices and stock.

**With nothing registered nothing changes.** `receipt_lines` is added only when a callback is registered **and** at least one line is valid; otherwise the snapshot has exactly the keys, and the same `etag`, it had before, and every receipt is built and printed exactly as before. The key looks like this (`variants` is an object keyed by variant id, and a part that is empty is left out):

```json
"receipt_lines": {
  "variants": { "0b1f...": ["Warranty: 12 months"] },
  "footer": ["Keep this receipt for warranty claims."]
}
```

**A warranty example** (Home Appliances). The warranty period of each product is an extension-owned attribute, `ext_warranty_months`, set by the extension's own Admin tab exactly as `extensions/care-notes/` does for `ext_care_notes`:

```php
$x->hook('pos.receipt.lines', static function (array $lines, array $context): array {
    $any = false;
    foreach ($context['variants'] as $variant) {
        $months = $variant['attributes']['ext_warranty_months'] ?? null;
        if (is_int($months) && $months > 0) {
            $lines[] = ['variant_id' => $variant['variant_id'], 'text' => sprintf('Warranty: %d month%s', $months, $months === 1 ? '' : 's')];
            $any = true;
        }
    }
    if ($any) {
        $lines[] = ['text' => 'Keep this receipt for warranty claims.'];
    }

    return $lines;
});
```

(The manifest lists `pos.receipt.lines` in `hooks`.) The hook runs once per snapshot download over every variant, so keep the callback quick: no database access, no network.

**Offline, reprints and an older snapshot.**

- The terminal copies the lines from the snapshot it holds **when the sale is saved**, and keeps them in the receipt it stores. A sale made offline therefore prints the same lines as an online one, and a reprint (before or after the sale is sent) prints exactly what was printed the first time. A later change never alters a receipt that was already printed.
- The terminal downloads a snapshot when it is started with a connection, when a shift is opened and when the cashier presses "Refresh prices and stock"; there is no timer. If the warranty data changed on the server after the last download, the terminal keeps printing the **old** lines: a changed period prints the old period, a product that gained a warranty prints none, and a product whose warranty was removed still prints it, until the next download. The `etag` changes with the lines, so the terminal's saved copy is replaced at that download. After 24 hours without a download the banner warns, and selling is never blocked.
- This is different from what the brief for 8.3 assumed (a server-built receipt for online sales): there is none, so online and offline sales behave the same.
- The server does not store what was printed. The sale record, `POST /pos/sales`, the offline queue's payload and the database are unchanged, and the lines are not on `GET /pos/sales/{id}` or in the Admin sale view.

**Receipt templates.** The lines under a sale line are added by `receipt.js` inside the line's own element, so any layout shows them. The lines under the totals are placed before the element with `data-bind="footer"` and at the end of the receipt when a template has none, so an organization's own `receipt.php` needs no change. The `.receipt__note` and `.receipt__notes` classes style them; the text is always set as text, never as markup.

## Not provided yet

Two hooks were considered for the POS and are **not provided**. They are not in `Hooks::NAMES`, so `ExtensionContext::hook()` refuses them and an extension that registers one fails to load (logged). Do not write an extension for them.

- **`pos.line.fields` (extra data on each sale line, such as a serial number).** The terminal sends only the variant, quantity, price and discount of a line, and the server reads nothing else. A value typed at the till has to travel in a new key of the sale (`lines[].fields` of `POST /pos/sales`), which changes the **sale API contract**; it has to be held by the **offline queue** (the sale payload the terminal stores until it can send it) and entered on the POS screen; and `pos_sale_lines` has **no column** to keep it in, so it needs a database migration. Serial numbers cannot be worked out on the server.
- **`pos.tender.types` (extra payment methods).** The `payments.method` column has a database check that allows only `cash`, `gcash`, `maya`, `card`, `bank_transfer` and `cod`, so a sale paid another way would fail to save with a server error, and the terminal would keep retrying it. The terminal also hard-codes which methods need a reference number (`tender.js`), so it cannot ask for the reference an extension method needs: the sale would be refused at upload, after it was rung up and printed. The list of methods is shared with the online checkout and the Admin order payments, which a POS-only type must not reach.

Each needs a decision on the sale contract, the offline queue and the database before it can be built.

## Extension-owned product attributes (Batch 8.2)

The Migrator runs only the `core` and `org` migrations, so an extension has no table of its own yet. The place for a per-product value is the product's attributes, under a key that starts with `ext_` (`Gfh\Core\Support\ExtensionAttributes::PREFIX`). Core treats such a key as private to extensions:

- The Admin attribute list does not show it, and a name that starts with `ext_` typed there is refused (it would vanish).
- Saving the Admin attribute list keeps the stored `ext_` values. An extension changes one through `product.form.save`: take the complete set from `$data['attributes']` or, when the form posted none, from `$context['current_attributes']`, set the key, and return it in `$data['attributes']`. An empty value removes the key.
- The Shop attribute table and the Shop filters never list it, and the public API (`/api/v1/catalog/products/{slug}`) leaves it out. `product.detail.sections` receives the product with it in `attributes`, so the extension that owns the key can print it.
- It stays in the record when the extension is switched off and is then shown by nothing.

**Nothing secret goes in an `ext_` attribute.** It is public data that reaches Shop hooks with the product, and Admin users with the right permission can read and write it through the product API (`attributes` in `PUT /api/v1/admin/products/{id}`). A value is at most 200 characters, like any attribute.

The demonstration extension `extensions/care-notes/` uses `ext_care_notes`. `docs/extension-guide.md` shows how to write and switch on an extension.
