# System settings (`/superadmin/settings`)

Built in: SA.2 (role permissions) and SA.4 (organization status, features, branding, settings, platform reference).

## Role permissions (SA.2)

Header: "System Settings"; section "Organization role-permission matrix".
Scope selector: Group defaults (reference) or one organization.
Table: Module (dashboard, inventory, products, procurement, sales, reports_admin, pos) by View, Edit, Delete, Source (Default or Override), and a Reset to default action for the role in that organization.
Rules: the Admin role is locked and not editable; Manager, Staff and Cashier are editable; edit needs view and delete needs edit (the page refuses other combinations and says why); defaults are read-only (they change only by migration); every change is audited with old and new values; the Group Viewer only looks.

## Organization status, features, branding, settings (SA.4)

Status (active, suspended, archived; suspended is already enforced by the tenant middleware), feature keys on or off, branding keys validated by `BrandingCss`, and settings, each with a confirm step and an audit entry. A "Platform reference" card lists the documented platform constants.
