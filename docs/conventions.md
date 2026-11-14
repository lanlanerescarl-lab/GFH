# GFH conventions

These rules apply to every stage and to every team member who extends the templates.
When a rule here and a later document disagree, the Architecture and Database Design documents win,
and this file gets corrected.

## 1. Stack rules

- PHP 8.2 or newer, HTML, CSS and vanilla JavaScript (ES modules). PostgreSQL through PDO.
- No PHP framework, no JavaScript framework, no Tailwind, no build step.
- Composer is allowed for small PHP libraries. The project must also run without it (own autoloader).
- Required PHP extensions: `pdo_pgsql`, `gd`, `mbstring`, `sodium`, `json`.
- Never use Supabase-specific features. Treat every database as plain PostgreSQL.

## 2. Folder map

| Folder | Holds | Web-visible |
| --- | --- | --- |
| `public/` | `index.php` and public assets only | Yes (the document root) |
| `core/` | Framework code: router, config, database, tenancy, auth, services, repositories, migrator | No |
| `extensions/` | Shared optional features, each with an `extension.json` | No |
| `templates/shop`, `pos`, `admin` | Views, partials, JS modules and CSS of the three apps | No (assets are published through `public/`) |
| `orgs/{slug}/` | Overrides, organization-local extensions and seeds for one organization | No |
| `database/` | Migrations and seeds | No |
| `storage/` | Uploads and logs; written at runtime | No |
| `bin/` | Command-line scripts | No |
| `tests/`, `docs/` | Tests and documentation | No |

Only `public/` is served. If a host cannot point its document root at `public/`, deny access to everything else.

## 3. Namespaces and autoloading

- Everything in `core/` lives in the namespace `Gfh\Core\...` and the path matches the namespace:
  `Gfh\Core\Http\Request` is `core/Http/Request.php`. One class per file, file name equals class name.
- `core/Autoloader.php` is a PSR-4 loader. Extensions register their own namespace through
  `Autoloader::addNamespace()` when they load (wired up in Stage 8).
- Folder names inside `core/` are capitalised class folders. Never create two folders whose names differ
  only by letter case, because Windows and macOS treat them as the same folder.
  (This is why core view files live in `core/pages/` and not `core/views/`.)

## 4. PHP style

- Every PHP file starts with `declare(strict_types=1);`.
- Classes are `final` unless they are designed to be extended. Prefer constructor injection and
  `readonly` properties. No static state except pure helpers.
- Type every parameter, property and return value. Use docblocks only for array shapes and generics.
- Names are descriptive: `reserveStock()`, not `rs()`. Classes are `PascalCase`, methods and variables `camelCase`,
  constants `UPPER_SNAKE_CASE`, database objects `snake_case`.
- Comment the reason for a rule, not what the line does.
- User-facing errors are `HttpException`s. Anything else that is thrown is a server fault and is logged.
- No hard-coded organization names anywhere in `core/` or `templates/`. Organizations are data.

## 5. Layers

```
Router -> Controller -> Service -> Repository -> PostgreSQL
```

| Layer | Does | Must not |
| --- | --- | --- |
| Router | Matches method and path, runs middleware | Contain logic |
| Controller | Reads input, calls `can()`, calls services, returns a `Response` | Contain business rules or SQL |
| Service | All business rules: pricing, VAT, discounts, stock, costing, orders, accounts | Read `$_GET`/`$_POST`, send output, contain SQL |
| Repository | All SQL, prepared statements only | Contain business rules |

The Shop, POS and Admin call the same services. A rule exists in exactly one place.

## 6. Routing

- URLs are path-based: `/{org}/shop`, `/{org}/pos`, `/{org}/admin`, `/{org}/api/v1/...`, `/superadmin`, `/media/{org}/{id}`.
- Reserved first path segments, never allowed as organization slugs: `health`, `api`, `media`, `assets`, `superadmin`.
  Slugs are lower-case letters, digits and hyphens, starting with a letter or digit.
- Patterns: `{name}` matches one segment; `{name:regex}` matches a custom pattern without curly braces.
  Read values with `$request->param('name')`.
- Handlers are callables `fn (Request $request): Response`. Middleware are callables
  `fn (Request $request, callable $next): Response` and pass data on with `$request->withAttribute()`.
- A path that exists for other methods answers 405 with an `Allow` header. Unknown paths answer 404.
- HEAD is answered by the matching GET route.

## 7. Requests and responses

- `Request` is built once from the PHP globals. The path is normalised (decoded, duplicate slashes collapsed,
  trailing slash removed, install folder removed). Build every URL with `$request->basePath()` in front.
- `Request::input()` merges query string and body, the body winning. JSON bodies are limited to 1 MiB.
  File uploads arrive through `files()` and have their own 5 MB limit (Stage 3).
- `Response` is immutable. Use `Response::html()`, `text()`, `json()`, `ok()`, `error()`, `redirect()`, `noContent()`.
- Every response carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Cache-Control: no-store`
  and a Content-Security-Policy that allows the same origin only. A route that needs caching
  (the media route) overrides `Cache-Control` explicitly.
- Every response gets an `X-Request-Id` header. The same id is shown on error pages and written to the log.

## 8. JSON API envelope

Success:

```json
{ "ok": true, "data": { } }
```

Failure:

```json
{ "ok": false, "error": { "code": "validation_failed", "message": "Please correct the highlighted fields.", "fields": { "email": "Enter a valid email address." } } }
```

- `fields` is always an object, empty when there are no field errors.
- Error codes: `bad_request` 400, `unauthorized` 401, `forbidden` 403, `not_found` 404, `method_not_allowed` 405,
  `payload_too_large` 413, `validation_failed` 422, `too_many_requests` 429, `server_error` 500.
- Errors are returned as JSON for `/health`, for `/api/...` and `/{org}/api/...` paths, and for clients that accept
  JSON but not HTML. Everything else gets the HTML error page.

## 9. Errors and logging

- Users never see a stack trace, a file path or a SQL message. They see a friendly message and a reference id.
- Technical details go to `storage/logs/app.log`: one line per event with time, level, request id, message and a
  JSON context. Levels: `debug`, `info`, `warning`, `error`. Set the minimum with `LOG_LEVEL`.
- PHP warnings and notices are converted to exceptions. Deprecations are logged and ignored.
- With `APP_ENV=local` and `APP_DEBUG=true`, the error page also shows the exception class, message and file name
  (never a trace). Debug output cannot be enabled when `APP_ENV` is `production` or anything unrecognised.
- Never write passwords, tokens, connection strings or full ID numbers to the log.

## 10. Configuration and secrets

- Settings come from `.env` in the project root (outside `public/`), overridden by real environment variables.
  `.env` is never committed. `.env.example` lists every key.
- Read settings through `Config`: `string()`, `bool()`, `int()`, `required()`. Do not call `getenv()` elsewhere.
- Values containing a space or `#` must be written in double quotes.
- The time zone is fixed to `Asia/Manila` in code (`Config::TIMEZONE`), not configurable.
- Organization connection strings live in the core registry, encrypted with the sodium key `APP_KEY` (Stage 1).

## 11. Data conventions (from the Database Design)

- Primary keys are UUIDs. Tables use `snake_case` plural names and the common columns
  `id`, `created_at`, `updated_at` (and `created_by` on user-originated rows).
- Times are `timestamptz`, stored in UTC and shown in Asia/Manila as `DD Mon YYYY` (for example `11 Nov 2026`).
- Money is `numeric(12,2)` in pesos, unit costs `numeric(12,4)`, quantities `numeric(12,3)`.
  PDO returns these as strings. Never convert money to `float`. All arithmetic goes through `Support\Decimal`:
  `parse($value, $scale)` reads a string, integer or float into whole units (centavos, ten-thousandths, thousandths) and refuses
  extra decimals; `format()` turns units back into a string; `mulDiv()` rounds half away from zero; `mul()` refuses to overflow.
  Prices, VAT, discounts and totals are worked out only in `PricingService`, and costs only in `InventoryService`.
- Prices are stored VAT-inclusive. VAT is 12% and is extracted: `vat = total - total / 1.12`, rounded to the centavo.
- Status columns are text with CHECK constraints, not enums. Business records are never deleted; use a status
  or `archived_at`.
- Stock on hand is never edited. It is the sum of append-only `stock_movements`.
- Users and customers are referenced across databases as plain ids, validated by the service layer.

## 11a. Migrations and database roles

- Migrations are numbered SQL files: `database/core/migrations/`, `database/org/migrations/`, and (Stage 8) an
  extension's own folder. Names look like `0003_sales.sql`; the number is the version and the scope
  (`core`, `org` or an extension key) is recorded with it in `schema_migrations`.
- Never edit a migration that has been applied anywhere. The Migrator stores a SHA-256 checksum of each applied file
  (line endings ignored) and refuses to run if one changed or went missing. Fix a mistake with a new, higher-numbered file.
- Each file runs in its own transaction. Do not write BEGIN or COMMIT. Migrations only go forward.
- Write plain PostgreSQL 13+. No Supabase-specific features, no extensions that need installing.
- Append-only tables (`audit_log`, `stock_movements`) are protected by triggers as well as by role rights.
- Web requests use limited roles (`gfh_core_app`, `gfh_app`); only `bin/` scripts use the migrator role. See `database/roles/README.md`.
- Organization connection strings are stored encrypted in `organizations.connection_encrypted`. Never log or print one.

## 11b. Accounts, sessions and permissions

- Two kinds of people sign in separately: staff (`users`, one account with a role per organization) and customers
  (`customers`, one account across all shops). Their sessions use different cookies, `gfh_staff` and `gfh_customer`.
- A session holds only a user id. The account is reloaded on every request, so deactivating someone ends their access at once.
- Session cookies are HttpOnly and SameSite=Lax, and Secure on HTTPS and in production. The session id is regenerated at sign-in.
  Sessions end after `SESSION_IDLE_MINUTES` of inactivity or `SESSION_ABSOLUTE_HOURS` in total. Nothing is kept in browser storage.
- Every state-changing request carries the session's CSRF token: the `X-CSRF-Token` header, or the `_csrf` form field.
  Password reset from a one-time link is the exception, because the secret in the link already proves the request.
- Sign-in gives one message for every failure and locks further tries after `LOGIN_MAX_ATTEMPTS` failures within
  `LOGIN_LOCKOUT_MINUTES`, for unknown emails as much as known ones.
- Ask `PermissionService::can($user, $organization, $module, $action)` in every controller action. Modules:
  `dashboard`, `inventory`, `products`, `procurement`, `sales`, `reports_admin`, `pos`. Actions: `view`, `edit`, `delete`.
  Pass `null` as the organization for Super Admin pages. Super Admin and Group Viewer never get edit or delete on operations.
- Create and change accounts only through `AccountService`, and reset links only through `PasswordResetService`.
  Never write to `users` or `memberships` from a controller.
- Routes that act on one organization use the middleware in this order: `TenantMiddleware`, `SessionMiddleware`,
  `CsrfMiddleware`, then `RequireStaffMiddleware` or `RequireCustomerMiddleware`.

## 11c. Business services and the API

- One service per business area, built per organization by `TenantServices` (Catalog, Media, Pricing, Inventory, Orders,
  Procurement, POS, Reports, Settings). The Shop, POS and Admin all use them; no business rule lives in a controller.
- Services change data inside `Database::transaction()`, take row locks before changing stock, and write to the organization's
  audit log (`AuditService::organization`) for price changes, stock adjustments, order status changes and payments.
- A service rejects bad input with `HttpException::unprocessable($fields)`; nested names use dots (`lines.0.quantity`).
- Controllers in `core/Controller/Api/` take their services from exactly one of `authorize()` (staff, `can()` checked),
  `customerContext()` or `publicServices()`. `ApiAuthorizationTest` fails the build if an endpoint does not.
- Route ids are checked as UUIDs in the controller (`ApiController::id()`); list filters that are ids or dates are checked by
  `ListQuery` before they reach SQL. SQL text is fixed in repositories, and request values are always bound parameters.
- Extensions change behaviour through hooks (`Hooks`), never by editing these classes.

## 12. Security rules that apply from the first line of code

- Prepared statements only. No SQL built from input. A database name never comes from user input.
- Escape every output with `$this->e()` in views. Validate every field on the server.
- Check `can(user, organization, module, action)` in every controller action (Stage 2 onward).
- Every state-changing request carries a CSRF token (Stage 2 onward).
- No authentication data in `localStorage` or `sessionStorage`.

## 13. Views

- Core pages (error, welcome, and temporary auth pages) live in `core/pages/` and are rendered by `View::render($name, $data)`.
- App views, layouts and partials for `admin`, `shop` and `pos` are rendered by `AppView` (created via `Services::appView()`), which resolves every template through `TemplateLocator`: `orgs/{slug}/{app}/{name}.php` first, then `templates/{app}/{name}.php`. An organization can override a single view, layout or partial without copying the rest.
- Inside `AppView`, `$this->e($value)` escapes output, `$this->partial($name, $data)` renders a partial through the same override lookup, `$this->layout($name, $data)` wraps the view's output (`content` section) in a layout, and `$this->start($section)` / `$this->end()` / `$this->section($name, $default)` pass named HTML sections to the layout.
- Each app has its shell in `templates/{admin,shop,pos}/layout.php` and header navigation partial in `templates/{admin,shop,pos}/partials/nav.php`.

## 14. JavaScript, CSS and branding

- Small ES modules loaded with `<script type="module">` (such as `public/assets/js/shell.js`). No globals, no inline scripts, no `eval`.
- No external hosts: fonts, icons and libraries are vendored under `public/assets/`.
- Templates are not web-visible. `AssetPublisher` (`php bin/publish-assets.php [--dry-run]`) copies static assets (`.css`, `.js`, `.mjs`, `.woff2`, `.woff`, `.svg`, `.png`, `.jpg`, `.jpeg`, `.webp`, `.gif`, `.ico`) from `templates/{app}/assets/` to `public/assets/{app}/` and from `orgs/{slug}/assets/` (`orgs/{slug}/{app}/assets/`) to `public/assets/orgs/{slug}/{app}/`, emptying those generated target folders first while leaving shared `public/assets/{css,js,fonts}/` untouched.
- `AssetUrls` builds asset links for views: `$assets->url($path)` prefers `public/assets/orgs/{slug}/{app}/{$path}` when published and falls back to `public/assets/{app}/{$path}`; `$assets->shared($path)` points to shared assets (`css/tokens.css`, `css/fonts.css`, `css/base.css`, `js/shell.js`); `$assets->branding()` points to `/{org}/branding.css`.
- CSS uses variables from `public/assets/css/tokens.css` for colors, fonts, radii, shadows and spacing (multiples of 4 and 8).
- Organization branding is served at `GET /{org}/branding.css` (`BrandingController` and `BrandingCss::build()`), which turns validated organization branding keys (`primary`, `primary_strong`, `background`, `ink`, `font_body`, `font_heading`, `radius_card`, `radius_control`) into `:root` CSS variables and drops any color that fails the 4.5:1 WCAG contrast check.
- Accessibility basics: a label on every field, visible focus, keyboard operation, sufficient contrast, a skip link.

## 15. Testing

- PHPUnit tests live in `tests/` (Stage 2 onward). Business rules are tested at the service level.
- A test is only reported as passed after it has been run.

## 16. Working agreement

- Build one stage at a time and stop for review before the next.
- State assumptions in writing. Do not add features, libraries or files beyond the current stage.
