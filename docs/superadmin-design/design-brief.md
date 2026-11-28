# Super Admin design brief (approved 28 Nov 2026)

The Super Admin looks like part of the Admin app: same tokens, same components, same sidebar pattern. The React reference only supplies layouts and wording. The reference's design tokens are identical to `docs/design-tokens.md`, so no new palette, font or shadow is needed.

## Layout

- Shell: sidebar on the left (248 px wide at desktop) with the six pages in this order: Overview, Organizations, Consolidated reports, Users and access, Audit log, System settings. Built from `Modules::groupAllows`: a page the person may not view is not listed.
- Top bar: page context on the left; on the right the signed-in person's name, a role badge (Super Admin or Group Viewer) and a sign-out button (POST with CSRF).
- Content: one centered column, at most 1280 px wide. Each page starts with a page header (h1 in Source Serif 4 using `--color-heading`, a one-sentence subtitle at most about 650 px wide), then optional filter bar, then cards, grids and tables.
- Group Viewer sees the same pages with a "View only" banner and no write controls (not disabled controls; no controls).

## Palette, type, shape

- Tokens only (`var(--...)`); no raw hex in Super Admin CSS. Page background `--color-bg`, cards `--color-surface`, ink `--color-ink`, primary `--color-primary`.
- Source Serif 4 for headings and key KPI numbers, Inter for everything else, both self-hosted. Monospace only for slugs, IDs and JSON.
- Radii: cards 10 px, controls 8 px, badges fully rounded. Shadows: rest for cards, raised on hover or focus within, overlay for drawers and modals. Spacing in multiples of 4 and 8.

## Density

Comfortable: 16 to 24 px card padding, 44 px minimum touch target, tables with 12 px cell padding. A "section kicker" (small uppercase label) may sit above a section's h2.

## Components (reuse the Admin ones; add only what is missing)

- KPI card: label, large value, one note line, optional 7-day sparkline.
- Status badge: a dot plus text, tones ok (green), warn (amber), danger (red), info (blue), neutral (grey). Color is never the only signal.
- Filter bar: labelled fields and a Reset button. Tables: inside a container with `overflow-x: auto`; sortable headers carry `aria-sort`; 25 rows per page; pagination with labelled page buttons.
- Drawer (inspect an audit event or an account) and modal (confirm, one-time reset link): focus moves in, Tab stays inside, Escape closes, focus returns to the control that opened it.
- Empty state: a short title, one sentence, and the next step.
- Snapshot note: where a figure is a current snapshot rather than period data, say so ("Current snapshot, not filtered by the period").

## Data display rules

- Money: pesos with thousands separators and two decimals, built from exact decimal strings (never floats). Profit is always shown with its cost completeness (percent, one decimal); no completeness means "No sales in period".
- Times: stored in UTC, shown in Asia/Manila and labelled so. Show an "as of" time on any figure read from an organization.
- An organization that is unreachable, paused or has no reader connection shows a plain notice on its row or card; the page still renders with the others. Never an error page.
- Never display: connection strings, hosts, passwords, tokens, hashes, ciphertext.

## Charts

Vendored Chart.js with the shared Admin chart theme. Series colors `--gfh-500` then `--gfh-800`, `--gfh-300`, `--gfh-200`. Sparklines have no axes. Every chart has a text alternative (a label that states the trend, and a table or list with the same figures).

## Phone width

Everything works at 320 px: the sidebar collapses to a menu button that opens it as a drawer; grids stack to one column; filter fields stack; tables scroll sideways inside their container; drawers and modals use the full width. Breakpoints in the reference: 1200, 1023, 767 and 480 px.

## Limits that never bend

No external hosts (fonts and images are self-hosted); no JS framework and no build step (small ES modules); no `innerHTML` with data; labels on every field, visible focus, full keyboard use, contrast of at least 4.5:1; respect `prefers-reduced-motion`; no secret ever reaches a page, a log or a test.
