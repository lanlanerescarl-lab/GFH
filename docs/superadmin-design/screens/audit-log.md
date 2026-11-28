# Audit log (`/superadmin/audit`)

Built in: SA.1b. Source: the core `audit_log` table (append-only). Organization audit events through `org_contract.audit_events` may be added in SA.3; SA.1 is core only.

Header: "Audit Log", subtitle "Append-only history across group governance and organization registry actions."
Filter bar: Search (action, actor, entity ID), From, To, Organization, Action, Actor, Entity, Reset.
Table columns: Time (sortable, newest first, Asia/Manila), Acting user, Organization (or "Core group"), Action, Entity, Summary, Inspect.
Pagination: 25 per page, labelled page buttons, "Showing x to y of z".
Inspect opens a drawer titled "Audit Event": summary, then the fields (time in UTC and Asia/Manila, actor, organization, action, entity, entity ID) and the detail as escaped key and value pairs.

Redaction: a detail key matching password, token, hash, secret or connection is never rendered; long values are truncated; all output is escaped.
Empty state: "No audit events match" with the text "No events match the current search, date range and filters."
Roles: Super Admin and Group Viewer (view). All SQL is parameterized.
