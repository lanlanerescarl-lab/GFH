# JSON API (Stage 3)

All endpoints live under `/{org}/api/v1/`. `{org}` is the organization's slug. Examples use `/demo/api/v1/...`.

## Conventions

**Envelope.** Success: `{"ok": true, "data": ...}`. Failure: `{"ok": false, "error": {"code", "message", "fields"}}`, where `fields`
is an object of field name to message (empty when the error is not about a field). Errors:

| Status | code | Meaning |
| --- | --- | --- |
| 400 | `bad_request` | Unreadable request or invalid JSON |
| 401 | `unauthorized` / `invalid_credentials` | Not signed in |
| 403 | `forbidden` / `csrf_mismatch` | `can()` said no, or the CSRF token is missing or wrong |
| 404 | `not_found` | Unknown id, or someone else's order or address |
| 409 | `conflict` | The change clashes with the current state (a brand in use, an open shift, a reused receipt number, a customer who already has 10 addresses) |
| 422 | `validation_failed` | Field errors in `fields`. Nested names use dots: `lines.1.batch_code`, `attributes.wattage` |
| 429 | `too_many_requests` | Sign-in lockout (`Retry-After` header) |

**CSRF.** Every state-changing request (POST, PUT, PATCH, DELETE) carries the session's token in the `X-CSRF-Token` header.
Get one from `GET .../auth/staff/csrf` or `GET .../auth/customer/csrf` (or from the response of a sign-in).

**Sessions.** Staff and customers use separate session cookies. Authentication is never kept in browser storage.

**Money and quantities.** Amounts are strings with two decimals (`"112.00"`), costs have four (`"50.0000"`), quantities three (`"2.500"`).
Send amounts and quantities as strings or numbers; more decimals than allowed is a field error. Prices include 12% VAT.
Dates are `YYYY-MM-DD`; timestamps are ISO 8601. Calendar days are Asia/Manila.

**Lists.** Every list accepts the same parameters, and returns the same shape:

| Parameter | Meaning |
| --- | --- |
| `page` | Page number, from 1 |
| `per_page` | Rows per page, default 20, at most 100 |
| `sort` | A sort name; a leading `-` reverses it. Each endpoint lists its names |
| `q` | Search text |
| other names | Filters, listed per endpoint |

```json
{"ok": true, "data": {"items": [ ... ], "pagination": {"page": 1, "per_page": 20, "total": 57, "pages": 3}, "sort": "name"}}
```

**Permissions.** Every Admin and POS endpoint checks `can(user, organization, module, action)`; the module and action are listed per
endpoint. A person needs an active membership in the organization. Roles and defaults: see `database/core/migrations/0002_auth.sql`.

---

## Catalog (public, no sign-in)

Only active products with an active variant are visible. Costs and trade prices are never shown.

| Method and path | Notes |
| --- | --- |
| `GET /catalog/products` | Sort: `name`, `price`, `newest`. Filters: `brand_id` (one or comma-separated), `category_id` (a top-level category includes its children), `min_price`, `max_price`, `attr[name]=value`, `attr[name][min]=`, `attr[name][max]=`, `attr[name]=true\|false`. Search: `q` (name, brand, SKU, barcode) |
| `GET /catalog/filters` | Same parameters. Returns the filter options for that listing: `brands` (with counts), `categories` (two-level tree with counts), `price` (`min`, `max`), `attributes` (per key: `type`, `values` with counts, or `min` and `max` for numbers) |
| `GET /catalog/products/{slug}` | One product with variants, photos and attributes. A signed-in customer also gets the price tiers they qualify for |
| `GET /catalog/categories` | The two-level category tree |
| `GET /catalog/brands` | Sort: `name` |

Photos: `GET /media/{org}/{mediaId}` (add `?thumb=1` for the 400 px thumbnail). Public, served from `storage/uploads` with
`Cache-Control: public, max-age=31536000, immutable` and an `ETag` (a matching `If-None-Match` returns 304).

## Cart and checkout (Shop)

| Method and path | Who | Notes |
| --- | --- | --- |
| `GET /checkout/options` | anyone | `pickup_branches`, `delivery_available`, `payment_methods`, `fields` (extension checkout fields) |
| `POST /cart/price` | anyone (CSRF) | Body `{items: [{variant_id, quantity}], fulfillment?, checkout?}`. Returns the cart priced on the server: `lines` (with `product_name`, `variant_name`, `unit_price`, `tier_applied`, `line_total` and, since Batch 6.2, the variant's own quantity rule: `allow_decimal_qty` (boolean), `qty_step` and `min_qty` (three-decimal text such as `"0.500"`), so a client can offer the right quantity control; the server still enforces the rule), `subtotal`, `discount_total`, `delivery_fee`, `assembly_fee`, `installation_fee`, `total`, `vat_amount`, `net_of_vat`, `deposit_due`, `promised_date`. Client prices are ignored. Pricing is all or nothing: if any line is bad the answer is a 422 with no prices. Besides the existing `items` message (and `items.{SKU}.quantity` for a quantity that breaks the rule), the 422 `fields` carry, since Batch 6.2, one key per bad line named by its variant id: `items.{variant_id}` is `This product is no longer available.` for a variant that is gone or inactive, or `{SKU}: {reason}` when the quantity breaks the variant's rule. Good lines have no key. The existing keys and messages are unchanged |
| `POST /checkout` | customer | Body `{items, fulfillment, checkout}`. `fulfillment` is `{method: "pickup", branch_id}` or `{method: "delivery", line1, barangay?, city, province, postal_code?, recipient?, phone?}`. Places the order and reserves the stock. 201 with the order in the customer shape below |
| `GET /orders` | customer | The customer's own orders. Sort: `placed`, `total`, `number`. Filters: `progress_key`, `payment_status`, `from`, `to`. Each item has exactly the fields of the Shop's order list page (see below) |
| `GET /orders/{id}` | customer | One of their own orders with its path, payments and history, in the customer shape below. Another customer's order is 404 |

Stock short at checkout is a 422 on `items` ("Not enough stock for: SKU (only 3 available)"), and nothing is created.

### Customer order shape

`GET /orders/{id}` and the `POST /checkout` answer return the same object. **Costs and staff ids are never returned to customers** (no `unit_cost` or other cost, margin or profit field, no `recorded_by`, `changed_by` or other staff user reference, no staff-only flags or notes); staff read those on `GET /admin/orders/{id}`, which is unchanged. The fields are decided in one place, `ShopOrderService`, which the Shop's order pages use too.

| Field | Content |
| --- | --- |
| `id`, `order_number`, `placed_at`, `fulfillment_method` (`pickup` or `delivery`), `progress_key`, `payment_status` | Identity, when it was placed, how it is fulfilled, the current step key and the payment status |
| `subtotal`, `discount_total`, `delivery_fee`, `assembly_fee`, `installation_fee`, `vat_amount`, `total`, `deposit_due` | Money as two-decimal text. VAT is included in `total` |
| `promised_date`, `cancelled_at` | Text, or `null` |
| `pickup_branch` | `{name, address}` for a pickup order, otherwise `null` |
| `ship_to` | `{recipient, line1, barangay, city, province, postal_code, phone}` for a delivery order, otherwise `null` |
| `lines[]` | `{name, sku, qty, unit_price, line_total, is_made_to_order}`. `qty` has three decimals |
| `payments[]` | `{method, amount, paid_at, status}` |
| `history[]` | `{progress_key, label, changed_at}`, oldest first |
| `path[]` | The steps that apply to this order: `{key, label, maps_to, is_terminal, current}` |
| `payment_summary` | `{status, paid, balance, deposit_due}` |

Each item of `GET /orders` is `{id, order_number, placed_at, progress_key, progress_label, payment_status, payment_label, fulfillment_method, total, cancelled}` (`cancelled` is a boolean), the same fields as the order list page. Before this fix the order object also carried `unit_cost` on every line, `recorded_by` on every payment, `changed_by` on every history entry, `refund_review`, `cancellation_reason`, `customer_id`, `channel`, `pickup_branch_id`, `ship_from_branch_id`, `updated_at`, the line `id`, `variant_id` and `discount`, the payment `id` and `reference`, and each list item carried `refund_review`, `customer_id`, `customer_name`, `channel`, `deposit_due`, `cancelled_at` and `paid_total`. Nothing in the Shop reads them: the checkout page reads only `id` from the checkout answer.

## Account (customer)

The signed-in customer's own profile, saved addresses and password. Every endpoint needs a customer session (a staff session does not
count) and answers 401 without one. Every write (PUT, POST, DELETE) carries the `X-CSRF-Token` header. The customer is always the one
in the session: a customer id in the URL, query or body is never read, so nobody can reach another customer's data.

| Method and path | Notes |
| --- | --- |
| `GET /account/profile` | `{customer: {id, full_name, email, phone}}`. No password or hash is ever returned |
| `PUT /account/profile` | Body `{full_name, phone?}`, with the same rules as registration (name 2 to 120 characters, phone 7 to 20 of digits, spaces and `+ - ( )`). A `phone` that is left out or empty is cleared. The email and status cannot be changed here, and any other field sent is ignored. Never touches the password. Returns `{customer}` |
| `POST /account/password` | Body `{current_password, new_password, new_password_confirmation}`. Returns `{password_changed: true}` |
| `GET /account/addresses` | `{items, max_addresses}`: the default address first, then the newest. At most 10, so the list is not paginated and takes none of the list parameters |
| `POST /account/addresses` | Body `{label?, line1, barangay?, city, province, postal_code?}`. 201 with `{address}`. A 409 when the customer already has 10 |
| `PUT /account/addresses/{id}` | Same body. It replaces the six fields, so an optional field that is left out is cleared. Never changes which address is the default. Returns `{address}` |
| `POST /account/addresses/{id}/default` | Makes this address the only default (the old one is cleared in the same transaction). Returns `{address}` |
| `DELETE /account/addresses/{id}` | Returns `{id, deleted: true}`. Deleting the default promotes the most recently created address that is left |

An address is `{id, label, line1, barangay, city, province, postal_code, is_default, created_at, updated_at}` (ISO 8601 times, UTC).

Address fields: `label` optional, up to 40 characters; `line1` required, up to 200; `barangay` optional, up to 100; `city` and `province` required, up to
100 each; `postal_code` optional, exactly 4 digits, sent as a string (`"6100"`). Text is trimmed and runs of spaces are collapsed. Each bad field is a 422
with its own message in `fields`. `is_default` in a create or update body is ignored: the first address a customer saves becomes the default, and only
`POST .../default` (or deleting the default) moves it. A customer always has exactly one default while they have any address.

An address that is not the customer's, an unknown id and a malformed id all give the same plain 404 (also for a body that would fail validation), so the
answer never shows that an address exists. Deleting or editing a saved address never changes an order: an order keeps its own copy of the delivery address.

Changing the password:

- A wrong or missing `current_password` is a 422 on `current_password`. A wrong one is recorded in the same sign-in log, scope and lockout as a wrong password at
  sign-in (`LOGIN_MAX_ATTEMPTS` failures in `LOGIN_LOCKOUT_MINUTES`), and failed sign-ins count here too. A locked-out customer gets the sign-in 429 with `Retry-After`,
  even with the right password. A correct password clears the earlier failures, as at sign-in. A blank field is a form error and is not counted.
- `new_password` follows the registration password rules (10 to 72 bytes, not only spaces, not the customer's email address): a 422 on `new_password`.
  `new_password_confirmation` must match: a 422 on `new_password_confirmation`. Both can be reported together.
- On success the new hash is stored and the session id is regenerated; the CSRF token and the sign-in stay. **Other devices are not signed out**: sessions are
  PHP sessions kept per browser, so a session on another device stays valid until it ends by itself (`SESSION_IDLE_MINUTES` of inactivity or
  `SESSION_ABSOLUTE_HOURS` in total) or the account is deactivated. Server-side revocation of other sessions does not exist yet.

Order of checks: a write is checked for CSRF before sign-in, like every customer route, so a signed-out write without a token is a 403 `csrf_mismatch`, and one with
the token from `GET .../auth/customer/csrf` is a 401.

Audit (core log, entity `customer` or `customer_address`, no actor user): `customer.profile_updated` (names the fields that changed, not their values),
`customer.address_created`, `customer.address_updated`, `customer.address_default_changed`, `customer.address_deleted` (records the promoted address id),
`customer.password_changed` and `customer.password_change_failed` (the request's IP only). No entry holds a password, a hash, a token or address text.

## Orders (Admin, module `sales`)

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /admin/orders` | view | Sort: `placed`, `total`, `number`, `customer`. Filters: `progress_key`, `payment_status`, `customer_id`, `channel`, `refund_review`, `cancelled`, `from`, `to`. Search: `q` (number, customer) |
| `GET /admin/orders/{id}` | view | Order with `lines`, `payments`, `history`, `path` (the steps that apply to this order, `current` marked) and `payment_summary` |
| `POST /admin/orders/{id}/progress` | edit | Body `{step}`. The step must be in the order's own path. Reaching a step that maps to "complete" sells the reserved stock and snapshots costs. Never changes payment status |
| `POST /admin/orders/{id}/payments` | edit | Body `{method, amount, reference?}`. Methods: `cash`, `gcash`, `maya`, `card`, `bank_transfer`, `cod` (all but cash and cod need a `reference`). Payment status becomes `unpaid`, `deposit_paid` or `paid`. Paying more than the balance is a 422 on `amount` |
| `POST /admin/orders/{id}/cancel` | edit | Body `{reason}`. Releases the stock, keeps payments, sets `refund_review` when money was taken |

Progress and payment are separate fields (`progress_key` and `payment_status`) and change independently.

## Catalog management (Admin, module `products`)

View to read, edit to create and change, **delete** to archive a brand or category.

| Method and path | Permission |
| --- | --- |
| `GET /admin/products` (sort `name`, `price`, `newest`, `status`; filters as the public list plus `status`) | view |
| `POST /admin/products` (name, description, brand_id, category_id, status, supplier_id, attributes, variants[]) | edit |
| `GET /admin/products/{id}` (includes tiers, costs and stock) | view |
| `PUT /admin/products/{id}`, `POST /admin/products/{id}/status` (`{status}`) | edit |
| `POST /admin/products/{id}/variants`, `PUT /admin/variants/{id}` | edit |
| `POST /admin/variants/{id}/tiers`, `PUT /admin/tiers/{id}` (send `variant_id`), `DELETE /admin/tiers/{id}` | edit |
| `POST /admin/products/{id}/images` (`{media_id, variant_id?, primary?}`), `PUT /admin/products/{id}/images/order` (`{image_ids}`), `POST /admin/images/{id}/primary`, `DELETE /admin/images/{id}` | edit |
| `POST /admin/media` (multipart, field `file`, optional `alt_text`) | edit |
| `GET /admin/brands` (`archived=1` to include archived), `POST /admin/brands`, `PUT /admin/brands/{id}`, `PUT /admin/brands/{id}/logo` (`{media_id}`), `POST /admin/brands/{id}/restore` | view / edit |
| `DELETE /admin/brands/{id}` (archive; 409 while products use it) | delete |
| `GET /admin/categories`, `POST /admin/categories` (`{name, parent_id?}`), `PUT /admin/categories/{id}`, `POST /admin/categories/{id}/restore` | view / edit |
| `DELETE /admin/categories/{id}` (archive; 409 while products or sub-categories use it) | delete |

Rules: brand names must not start with GFH; categories go two levels deep; a product cannot be active without an active variant;
SKUs and barcodes are unique; whole-number variants need whole-number quantities, and decimals need `allow_decimal_qty`
with `qty_step` and `min_qty`. Price tiers: `basis` is `quantity` (applies from `min_qty`), `customer_tier` or `customer_type`
(`tier_key` names the tier or type); the lowest matching price wins and a tier cannot be dearer than retail.
Attributes are text, numbers or yes/no; an organization's `validation.attributes` hook adds its own rules and returns errors
named `attributes.{key}`. Price changes are written to `price_history` and the audit log.

Upload limits: JPEG, PNG or WebP, at most 5 MB and 24 megapixels, checked by content, re-encoded, resized to 2000 px, with a thumbnail.

## Inventory (Admin, module `inventory`)

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /admin/inventory/levels` | view | Per branch and variant: `on_hand`, `reserved`, `available`. Sort: `product`, `sku`, `branch`, `on_hand`, `available`. Filters: `branch_id`, `variant_id`, `low_stock=1` |
| `GET /admin/inventory/ledger` | view | The append-only movements. Sort: `time`, `sku`, `product`, `type`. Filters: `branch_id`, `variant_id`, `batch_id`, `type`, `from`, `to` |
| `GET /admin/inventory/reorder-suggestions` | view | Active variants at or below `reorder_level`. Sort: `suggested_qty`, `product`, `sku`, `on_hand`, `available`, `reorder_level`, `supplier`. Filters: `supplier_id`, `status` (`out_of_stock`, `low_stock`, `needs_po`) |
| `POST /admin/inventory/adjustments` | edit | `{branch_id, variant_id, delta, reason}`; `delta` may be negative; cannot take stock below zero; a reason is required |
| `POST /admin/inventory/write-offs` | edit | `{branch_id, variant_id, quantity, reason}`; a reason is required |

Stock on hand is the sum of movements. Weighted-average cost changes only on receipts and the incoming side of conversions.
Conversions (`convert_out` and `convert_in`) are done through `InventoryService::convert()` by extensions; they are not purchases.

## Procurement (Admin, module `procurement`)

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /admin/suppliers` | view | Sort: `name`, `lead_time`, `status`. Filter: `status` |
| `POST /admin/suppliers`, `PUT /admin/suppliers/{id}`, `POST /admin/suppliers/{id}/status` | edit | name, contact_name, contact_email, contact_phone, address, payment_terms, lead_time_days |
| `GET /admin/suppliers/{id}` | view | |
| `GET /admin/purchase-orders` | view | Sort: `created`, `number`, `expected`, `supplier`. Filters: `status`, `supplier_id`, `branch_id` |
| `POST /admin/purchase-orders` | edit | `{supplier_id, branch_id, expected_on?, notes?, lines: [{variant_id, quantity, unit_cost}]}`. Numbered `PO-2026-0001` |
| `GET /admin/purchase-orders/{id}` | view | With lines. `sent_at` is the date and time it was sent (null for a draft) |
| `POST /admin/purchase-orders/{id}/send`, `.../cancel` | edit | A draft is sent; a draft or sent order that received nothing is cancelled |
| `POST /admin/purchase-orders/{id}/receive` | edit | `{lines: [{line_id, qty_received, qty_rejected, batch_code, expires_on, bin}]}`. Accepted stock comes in at the line cost and updates the average cost; rejected quantities are recorded without stock. Variants that track batches need `batch_code` (errors are `lines.N.batch_code`); `expires_on` for such a variant cannot be earlier than today in Asia/Manila (error `lines.N.expires_on`). Variants that do not track batches ignore `batch_code`, `expires_on` and `bin`. The whole receipt is refused if any line is invalid, and nothing is saved. |

## POS (module `pos`; the review queue is module `sales`)

A cashier works only on their own shift unless they also have edit permission on `sales`.

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /pos/terminals` | pos view | The terminals the POS app offers at start-up: `{items: [{id, code, name, branch_name, has_open_shift}]}`, active terminals only, ordered by branch name and code. Needs only pos view (the Settings terminal list needs Admin) |
| `GET /pos/terminals/{id}/snapshot` | pos view | Everything the terminal needs offline: terminal (`id`, `code`, `name`, `branch_id`, `branch_name`, and `branch_address`, one printable line of address, city and province, empty when the branch has none), VAT rate, payment methods, active discount rules, products (price, barcode, decimals rules, `on_hand` at the branch, quantity tiers, `media_id`), and an `etag` |
| `GET /pos/terminals/{id}/shift` | pos view | `{shift, next_receipt_number}`: the open shift and the receipt number to use next, or both `null` when no shift is open. The app needs the number when it resumes an open shift, because only opening a shift used to return it |
| `POST /pos/shifts` | pos edit | `{terminal_id, opening_cash}`. 409 if the terminal already has an open shift. Returns `next_receipt_number` |
| `POST /pos/shifts/{id}/close` | pos edit | `{counted_cash}`. Expected cash is the opening cash plus cash payments; the response has `expected_cash` and `variance` |
| `POST /pos/sales` | pos edit | Uploads one sale: `{id, receipt_number, terminal_id, shift_id, sold_at, lines: [{variant_id, quantity, unit_price, discount?}], payments: [{method, amount, reference?}], discount?: {key, id_ref_last4?}, customer_id?}`. 201 for a new sale, 200 with `duplicate: true` when it was already uploaded |
| `GET /pos/sales/{id}` | pos view | A sale with lines, payments and discounts (for a reprint) |
| `GET /admin/pos-sales` | sales view | Sort: `sold`, `synced`, `receipt`, `total`. Filters: `status` (use `needs_review` for the queue), `terminal_id`, `shift_id` |
| `POST /admin/pos-sales/{id}/resolve` | sales edit | `{note}` accepts a flagged sale |

Sale sync rules: **idempotent** (the sale id is the key, so a retry returns the first result); **never rejected for stock** (a sale
that sold more than was on record, or that a stock rule objected to, is kept with `status: "needs_review"` and a `review_reason`);
**recomputed** (totals, VAT and discounts are worked out on the server from the unit prices sent, so an offline sale keeps its price;
the payments must add up to that total). Receipt numbers are the terminal code and a counter, like `T3-000123`; the same number from
a different sale is a 409. A sale dated after its shift was closed is a 409. Senior and PWD rules exist but are inactive until an
Admin switches them on.

**Offline use (Batch 7.2).** These endpoints are unchanged; the POS page now queues sales and sends them later, so what it relies on is worth stating. `POST /pos/sales` is idempotent by sale id (a repeat returns the earlier result with `duplicate: true`), which is what makes it safe to send a sale again after a crash or a lost answer. The snapshot carries an `etag` (a hash of its content) but the API does not answer conditional requests, so the POS downloads the whole snapshot each time and compares etags itself. A `409` (for example a receipt number already used) or a `422` for a queued sale is not retried: the POS moves that sale to "needs attention" with the message shown here and leaves it for a manager. A `401` pauses sending until the cashier signs in again; a network error, a `5xx`, `408` or `429` is retried with a backoff. The heartbeat is `GET /auth/staff/csrf`, used only to see whether the server answers. The service worker is not part of the API: it is served by the page's own route `GET /{org}/pos/sw.js` (no session, `Service-Worker-Allowed: /{org}/pos`) and never touches anything under `/api/`.

## Reports (Admin, module `dashboard`)

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /admin/reports/dashboard` | view | `periods` (`today`, `month_to_date`, `last_30_days`, each with `gross_profit`, `net_sales`, `cost`, `units`, `orders`, `cost_complete_pct`), `series` (daily, last 30 days), `kpis` (low stock, out of stock, active orders, open and overdue purchase orders), `alerts`, `order_stages`, `widgets` |

Gross profit is net sales (VAT removed, after discounts) minus the cost of lines that have a cost; it is never revenue. Lines with no cost
are left out of profit and lower `cost_complete_pct`. Calendar reports by day, month and year are provided by the OLAP module, not here.

## Authentication and accounts (Stage 2)

`/auth/staff/{csrf,login,logout,me}`, `/auth/customer/{csrf,register,login,logout,me}`, `POST /auth/reset-password`,
`POST /admin/reset-links`. See `docs/testing.md` for examples.

## Not in this stage

Discount rule, order step and organization settings management (Batch 5.4c-2, Stage 5), JSON endpoints for branches and terminals (their Admin pages
exist since Batch 5.4c-1), and the reserved `/{org}/api/v1/olap/` prefix (Stage 6).
