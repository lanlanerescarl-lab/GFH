# GFH Super Admin (`/superadmin`)

Group-level oversight console for GFH. The Super Admin reads only from the core database (and in later batches, read-only `org_contract` views through `gfh_reader`). It never writes to an organization database and never displays connection strings, passwords, tokens, hashes, or ciphertext.

## What exists

- **Batch SA.1a (foundation, sign-in, access guard, and shell):**
  - Route group `/superadmin` in `core/routes.php`, registered ahead of `/{org}` with `superadmin` excluded from the organization slug pattern so `/superadmin/...` is never routed to `TenantMiddleware`.
  - Controllers in `core/Controller/SuperAdmin/`: `AuthController`, `SuperAdminPageController`, `OverviewController`, `OrganizationsController`, `ReportsController`, `UsersController`, `AuditController`, `SettingsController`.
  - Middleware: `Gfh\Core\Http\Middleware\RequireGroupMiddleware`.
  - Services and helpers: `SuperAdminAuthService`, `SuperAdminNavigationService`, `SuperAdminRedirect`, `SuperAdminView`, `SuperAdminAssetUrls`.
  - Templates and assets in `templates/superadmin/`, published to `public/assets/superadmin/` by `php bin/publish-assets.php`. Reuses the shared and Admin design tokens and component styles (`docs/design-tokens.md`) with small ES modules (`assets/js/shell.js`, `assets/js/audit-drawer.js`).
- **Batch SA.1b (read-only Overview, Organizations, and Audit log — core database only):**
  - Read-only core repositories (`SuperAdminOrganizationRepository`, `SuperAdminAuditRepository`) using parameterized `SELECT` queries only and never selecting `connection_encrypted` or `password_hash`.
  - Read-only services (`SuperAdminOverviewService`, `SuperAdminOrganizationQueryService`, `SuperAdminAuditQueryService`), each asserting `PermissionService::can($actor, null, $module, 'view')` at the service level.
  - **Group Overview (`/superadmin`):** status count cards (total, active, suspended, archived), registered organizations list with migration and template versions and detail links, empty state when none are registered, and the 10 newest core audit events with a link to the audit log.
  - **Organizations (`/superadmin/organizations`, `/superadmin/organizations/{slug}`):** search and status filter bar, enabled feature tags, version info, both empty states (`No organizations registered yet` and `No organizations match`), documented empty `actionsHtml` slot reserved for the separate registration module, and read-only detail page showing general information, Asia/Manila timestamps, enabled features, and `BrandingCss::validated()` branding values with color swatches. Unknown or malformed slugs return 404.
  - **Audit Log (`/superadmin/audit`):** filter bar (search over action, actor and entity ID; Asia/Manila date range; organization including Core group; actor including System; action; entity; reset), sortable time column (`aria-sort`), 25 rows per page with labelled pagination, and an accessible **Audit Event** Inspect drawer (`assets/js/audit-drawer.js`, focus trap, Escape/Close/backdrop dismiss, focus return, no `innerHTML`, plus `?inspect={id}` server fallback). Detail keys matching `password`, `token`, `hash`, `secret`, or `connection` (case-insensitive, recursively) are omitted and long values are truncated.
  - `Consolidated Reports`, `Users and Access`, and `System Settings` remain placeholder pages until later batches.

## Routes

| Method | Path | Module | Middleware | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/superadmin/sign-in` | — | `SessionMiddleware('staff')` | Group sign-in form (redirects signed-in group users to safe `next` or `/superadmin`) |
| `POST` | `/superadmin/sign-in` | — | `SessionMiddleware('staff')`, `CsrfMiddleware` | Authenticates via `AuthService::authenticateStaff`, requires a group role |
| `POST` | `/superadmin/sign-out` | — | `SessionMiddleware('staff')`, `CsrfMiddleware` | Destroys staff session and redirects to `/superadmin/sign-in` |
| `GET` | `/superadmin` | `overview` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Group Overview (registry counts, org list, 10 newest audit events) |
| `GET` | `/superadmin/organizations` | `organizations` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Organizations list (documented `actionsHtml` header slot reserved for the separate registration module) |
| `GET` | `/superadmin/organizations/{slug}` | `organizations` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Read-only Organization registry detail (general info, features, validated branding) |
| `GET` | `/superadmin/reports` | `consolidated_reports` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Consolidated Reports (placeholder) |
| `GET` | `/superadmin/users` | `users_access` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Users and Access (placeholder) |
| `GET` | `/superadmin/audit` | `audit_log` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Core Audit Log (filters, time sort, 25/page pagination, Inspect drawer) |
| `GET` | `/superadmin/settings` | `system_settings` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | System Settings (placeholder) |

No other `POST`, `PUT`, `PATCH`, or `DELETE` routes exist under `/superadmin` in SA.1a/SA.1b.

## Access rules

- **Session:** Uses the existing staff session scope and cookie (`gfh_staff`). Session ID is regenerated and CSRF token rotated on sign-in. Every response sends `Cache-Control: no-store`.
- **Roles:** Only active accounts with `users.org_role` set to `super_admin` or `group_viewer` may sign in or open pages.
- **Sign-in failures:** Wrong password, organization-only accounts (even with a valid password), inactive/pending accounts, customer emails, and unknown emails all return HTTP 401 with `AuthService::INVALID_MESSAGE`. The shared staff lockout applies unchanged (HTTP 429 with `AuthService::LOCKED_MESSAGE` and `Retry-After`).
- **Route and service checks:** `RequireGroupMiddleware` redirects guests (and customer-only sessions) to `/superadmin/sign-in?next=...` (safe `/superadmin` paths only via `SuperAdminRedirect::safeNext`) and returns HTTP 403 to signed-in organization staff without a group role. Every controller action, `SuperAdminNavigationService::forPage`, and every read-only query service assert `PermissionService::can($user, null, $module, 'view')`.
- **Sidebar & Group Viewer:** Built from `Modules::groupAllows` / `PermissionService::can` in the approved order. Group Viewers see the "View only" banner and the `Group Viewer` badge.
- **Core grants:** `database/roles/03_grant_core.sql` already grants `SELECT ON ALL TABLES IN SCHEMA public` to `gfh_core_app`, covering `users`, `organizations`, `organization_features`, and `audit_log`.

## Design pack

Full visual specifications, layout brief, screen specs, and sample data live in `docs/superadmin-design/`:

- `docs/superadmin-design/README.md`
- `docs/superadmin-design/design-brief.md`
- `docs/superadmin-design/screens/` (`sign-in.md`, `overview.md`, `organizations.md`, `consolidated-reports.md`, `users-access.md`, `audit-log.md`, `system-settings.md`)
