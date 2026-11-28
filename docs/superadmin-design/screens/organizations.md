# Organizations (`/superadmin/organizations`, `/superadmin/organizations/{slug}`)

## List (SA.1b)

Header: "Organizations", subtitle "Registered organizations, reporting contracts and data freshness. Existing records are read-only."
Filter bar: Search (name or slug), Status (All, Active, Suspended, Archived).
Columns: Organization (name and short name), Slug (monospace), Status (badge), Enabled features (tags), Versions (migration and template), Data freshness (SA.3; in SA.1 shows "Not connected" or nothing).
Empty states: "No organizations match" (with Reset), "No organizations registered yet".

Extension point for the registration module: the page header has a named, documented place for a primary action ("New organization"). SA.1 renders nothing there. The registration module fills it later, only for people holding `organizations:create`.

## Detail (SA.1b)

Header: organization name, read-only banner.
"General information" card: name, short name, slug, status badge, template version, migration version, created and updated times, enabled feature keys (tags), branding values (only the keys `BrandingCss` accepts, as small color swatches with their values and text). Never a connection string, host, database name, ciphertext or reader URL; nothing is decrypted.

## SA.3 adds

Contract health (`contract_info`: contract version, organization migration version, refreshed time), organization financial KPIs, operational indicators, order progress (`order_stage_counts`), extension measures (`measures`) and active alerts, each with its own empty state ("No active alerts", "No order-stage rows yet", "Reporting contract pending"). If the organization cannot be reached: one notice at the top and the registry facts still shown.

## Not here

The creation wizard from the React reference. Registration is a separate module: it takes name, slug, short name, features, branding, and two prepared connection URLs (`gfh_app`, `gfh_reader`), stores them encrypted, and shows the migration command to run. It never opens an organization database to write.
