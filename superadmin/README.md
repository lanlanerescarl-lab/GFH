# GFH Super Admin (`/superadmin`)

Group-level oversight console for GFH. The Super Admin reads only from the core database (and in later batches, read-only `org_contract` views through `gfh_reader`). It never writes to an organization database and never displays connection strings, passwords, tokens, hashes, or ciphertext.

## What exists (Batch SA.1a)

- **Foundation, sign-in, access guard, and shell:**
  - Route group `/superadmin` in `core/routes.php`, registered ahead of `/{org}` with `superadmin` excluded from the organization slug pattern so `/superadmin/...` is never routed to `TenantMiddleware`.
  - Controllers in `core/Controller/SuperAdmin/`: `AuthController`, `SuperAdminPageController`, `OverviewController`, `OrganizationsController`, `ReportsController`, `UsersController`, `AuditController`, `SettingsController`.
  - Middleware: `Gfh\Core\Http\Middleware\RequireGroupMiddleware`.
  - Services and helpers: `SuperAdminAuthService`, `SuperAdminNavigationService`, `SuperAdminRedirect`, `SuperAdminView`, `SuperAdminAssetUrls`.
  - Templates and assets in `templates/superadmin/`, published to `public/assets/superadmin/` by `php bin/publish-assets.php`. Reuses the shared and Admin design tokens and component styles (`docs/design-tokens.md`) with one small ES module (`assets/js/shell.js`) for the mobile sidebar drawer.
  - All six pages are wired and reachable from the sidebar with their approved headers and a placeholder notice until their respective batches arrive.

## Routes

| Method | Path | Module | Middleware | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/superadmin/sign-in` | — | `SessionMiddleware('staff')` | Group sign-in form (redirects signed-in group users to safe `next` or `/superadmin`) |
| `POST` | `/superadmin/sign-in` | — | `SessionMiddleware('staff')`, `CsrfMiddleware` | Authenticates via `AuthService::authenticateStaff`, requires a group role |
| `POST` | `/superadmin/sign-out` | — | `SessionMiddleware('staff')`, `CsrfMiddleware` | Destroys staff session and redirects to `/superadmin/sign-in` |
| `GET` | `/superadmin` | `overview` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Group Overview |
| `GET` | `/superadmin/organizations` | `organizations` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Organizations (documented `actionsHtml` header slot reserved for the separate registration module) |
| `GET` | `/superadmin/reports` | `consolidated_reports` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Consolidated Reports |
| `GET` | `/superadmin/users` | `users_access` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Users and Access |
| `GET` | `/superadmin/audit` | `audit_log` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | Audit Log |
| `GET` | `/superadmin/settings` | `system_settings` | `SessionMiddleware('staff')`, `RequireGroupMiddleware` | System Settings |

No other `POST`, `PUT`, `PATCH`, or `DELETE` routes exist under `/superadmin` in SA.1a.

## Access rules

- **Session:** Uses the existing staff session scope and cookie (`gfh_staff`). Session ID is regenerated and CSRF token rotated on sign-in. Every response sends `Cache-Control: no-store`.
- **Roles:** Only active accounts with `users.org_role` set to `super_admin` or `group_viewer` may sign in or open pages.
- **Sign-in failures:** Wrong password, organization-only accounts (even with a valid password), inactive/pending accounts, customer emails, and unknown emails all return HTTP 401 with `AuthService::INVALID_MESSAGE`. The shared staff lockout applies unchanged (HTTP 429 with `AuthService::LOCKED_MESSAGE` and `Retry-After`).
- **Route and service checks:** `RequireGroupMiddleware` redirects guests (and customer-only sessions) to `/superadmin/sign-in?next=...` (safe `/superadmin` paths only via `SuperAdminRedirect::safeNext`) and returns HTTP 403 to signed-in organization staff without a group role. Every controller action and `SuperAdminNavigationService::forPage` assert `PermissionService::can($user, null, $module, 'view')`.
- **Sidebar & Group Viewer:** Built from `Modules::groupAllows` / `PermissionService::can` in the approved order. Group Viewers see the "View only" banner and the `Group Viewer` badge.

## Design pack

Full visual specifications, layout brief, screen specs, and sample data live in `docs/superadmin-design/`:

- `docs/superadmin-design/README.md`
- `docs/superadmin-design/design-brief.md`
- `docs/superadmin-design/screens/` (`sign-in.md`, `overview.md`, `organizations.md`, `consolidated-reports.md`, `users-access.md`, `audit-log.md`, `system-settings.md`)
