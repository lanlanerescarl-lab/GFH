# Consolidated reports (`/superadmin/reports`)

Built in: SA.3. Source: `org_contract.daily_sales`, `order_stage_counts`, `measures`, read as `gfh_reader` with a per-organization timeout.

Header: "Consolidated Reports", subtitle "Group reporting built only from documented organization reporting views."
Controls: Period type (day, month, year), the period (or start and end date), an organization filter (toggle chips), a compare switch.
Tabs ("Report views"):
1. Period breakdown: trend chart (Net Sales and Gross Profit) and a table per period: Net Sales, Gross Profit, Cost, Units, Orders, Cost completeness, with a total row. No Gross Margin column.
2. Organization comparison: Organization, Gross Profit, Net Sales, Sales share, Orders, Cost completeness, and Net Sales variance when compare is on.
3. Order stages: Organization, native stage, stage key, mapped group (open, in progress, complete), order count.
4. Extension measures: Organization, measure, value, unit, as of.
Rules: exact decimal strings; profit only with its cost completeness; an organization that is down is listed with a notice and the totals say which organizations they include.
Empty states: "No sales in period", "No extension measures".
Not approved yet: CSV export (it exists in the React reference; ask before adding it).
