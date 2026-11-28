# Overview (`/superadmin`)

Page header: "Group Overview", subtitle "Consolidated performance and operational health across N GFH organizations."

## SA.1b (registry only)

- Cards: Organizations (total), Active, Suspended, Archived.
- "Organizations" list: name, slug, status badge, migration version, template version, link to its detail page.
- "Recent system activity": the 10 newest core audit events (time, actor, action, summary) and a "View audit log" link.
- No sales figures, no charts, no organization database is opened.

## SA.3 adds (from the `org_contract` views, read as `gfh_reader`)

- Period selector (Today, Month to date, Last 30 days).
- Section "Consolidated financial performance": KPI cards Gross Profit, Net Sales (VAT-excl.), Total Orders, Units Sold, Cost Completeness, each with the change against the prior period. In Today mode the sparklines show the trailing 7 days while the headline figures stay on today.
- Section "Current cross-organization indicators": open and overdue purchase orders, low-stock and out-of-stock variants, active orders (`org_contract.kpis`), with a snapshot note that the period selector does not filter them.
- Charts: "Gross Profit and Net Sales Trend" and "Organization Performance".
- "Important organization alerts" from `org_contract.alerts`, grouped by organization.
- An unreachable organization shows a notice, and the group totals say how many organizations they include.

Roles: Super Admin and Group Viewer (view). Empty state: "No organizations registered yet" with the next step.
