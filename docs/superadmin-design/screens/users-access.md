# Users and access (`/superadmin/users`)

Built in: SA.2 (not in SA.1).

Header: "Users and Access", subtitle "Group-level roles are held on the account; organization roles are held per membership."
Filter bar: Search (name or email), Account type (All, Super Admin, Group Viewer, Organization staff only), Organization membership (All, each organization, None), Organization role (All, Admin, Manager, Staff, Cashier), Account status (All, Active, Inactive, Pending).
Columns: Name and email (sortable), Group role, Organization memberships (role and status per organization), Account status (badge), Last sign-in (sortable), Actions menu.
Row actions: view details (drawer), add a membership, change role, deactivate or reactivate a membership, deactivate or reactivate an account, issue a one-time reset link (shown once, with an explanation that GFH sends no email).
Create: staff accounts (Admin, Manager, Staff, Cashier) in any organization through `AccountService`; group accounts through `createGroupAccount`.
Rules: the account status and each membership status are separate things and are shown separately; the last active Admin of an organization cannot be deactivated or changed; every write is audited; no hash or token is ever shown except the reset link, once.
Group Viewer: sees the list and drawers, no actions.
