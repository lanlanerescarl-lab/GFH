# Design tokens (Stage 4)

Every stylesheet in GFH uses CSS custom properties (`var(--name)`) rather than raw hex, RGB or pixel values. Raw token values live in two places only:

1. `public/assets/css/tokens.css` — the shared foundation: brand palette, status colors, font stacks, corner radii, shadows, spacing scale (multiples of 4 and 8), motion durations (150 to 250 ms), and semantic defaults.
2. `templates/{admin,shop,pos}/assets/css/tokens.css` (published to `public/assets/{admin,shop,pos}/css/tokens.css`) — each app's semantic token set, defined purely as `:root` CSS variables so that an organization's branding stylesheet (`GET /{org}/branding.css`) loaded after it can re-theme the app without changing a single component rule.

## Loading order in every layout

Each app layout (`templates/{admin,shop,pos}/layout.php`) loads stylesheets in this order:

1. `/assets/css/tokens.css` — shared palette, spacing, motion and semantic defaults
2. `/assets/{app}/css/tokens.css` — app token set (`admin`, `shop` or `pos`)
3. `/assets/css/fonts.css` — self-hosted `@font-face` rules (`Inter` and `Source Serif 4`)
4. `/assets/css/base.css` — reset, skip link, visible keyboard focus, shared shell and form defaults
5. `/assets/{app}/css/app.css` — app shell and component styles
6. `/{org}/branding.css` — organization overrides (`BrandingCss::build()`), loaded last so valid branding always wins

## Shared palette and status tones (`public/assets/css/tokens.css`)

### GFH blue scale

| Token | Value | Purpose |
| --- | --- | --- |
| `--gfh-50` | `#eff3ff` | Admin page background, subtle hover/selected row tint, info badge background |
| `--gfh-100` | `#c6dbef` | Soft accent fill (role pills, active filter chips) |
| `--gfh-200` | `#9ecae1` | Chart series 4, subtle accents |
| `--gfh-300` | `#6baed6` | Chart series 3, secondary accents |
| `--gfh-500` | `#3182bd` | Brand accent, focus ring, active sidebar bar, chart series 1 |
| `--gfh-600` | `#2171b5` | Primary interactive blue for white-text buttons and links (keeps ≥ 4.5:1 contrast with `#ffffff`) |
| `--gfh-800` | `#08519c` | Headings and key KPI numbers only (`--color-heading`), chart series 2 |

### Status tones

Base colors (`--status-*`) are used for status dots, borders and icons. Text inside badges and alerts uses the darker `-ink` token so contrast on the tinted background stays above 4.5:1.

| Tone | Base token | Background token | Text token (`-ink`) |
| --- | --- | --- | --- |
| Green (ok / active / paid) | `--status-ok: #16a34a` | `--status-ok-bg: #f0fdf4` | `--status-ok-ink: #166534` |
| Amber (warning / pending / low stock) | `--status-warn: #d97706` | `--status-warn-bg: #fffbeb` | `--status-warn-ink: #92400e` |
| Red (danger / cancelled / error) | `--status-danger: #dc2626` | `--status-danger-bg: #fef2f2` | `--status-danger-ink: #b91c1c` |
| Blue (info / in progress) | `--status-info: var(--gfh-500)` | `--status-info-bg: var(--gfh-50)` | `--status-info-ink: var(--gfh-800)` |
| Neutral (draft / archived) | `--status-neutral: #64748b` | `--status-neutral-bg: #f1f5f9` | `--status-neutral-ink: #334155` |

### Typography, radii, shadows, spacing and motion

- **Fonts (self-hosted under `public/assets/fonts/`):**
  - `--font-heading`: `"Source Serif 4", Georgia, "Times New Roman", serif`
  - `--font-body`: `Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif`
  - `--font-mono`: `ui-monospace, "Cascadia Mono", Consolas, monospace`
- **Radii:** `--radius-card: 10px`, `--radius-control: 8px`, `--radius-pill: 999px` (fully rounded pills).
- **Shadows:**
  - `--shadow-rest: 0 1px 4px rgba(8, 81, 156, 0.06)`
  - `--shadow-raised: 0 4px 16px rgba(8, 81, 156, 0.12)`
  - `--shadow-overlay: 0 12px 32px rgba(8, 81, 156, 0.18)`
- **Spacing (multiples of 4 and 8):** `--space-1` (`4px`), `--space-2` (`8px`), `--space-3` (`12px`), `--space-4` (`16px`), `--space-5` (`24px`), `--space-6` (`32px`), `--space-7` (`48px`), `--space-8` (`64px`).
- **Motion (150 to 250 ms):** `--motion-fast: 150ms`, `--motion-base: 200ms`, `--motion-slow: 250ms`, `--ease-standard: cubic-bezier(0.2, 0, 0, 1)`. Under `prefers-reduced-motion: reduce`, all three durations drop to `0ms`.

## The three app token sets

| Semantic variable | Admin (`templates/admin/assets/css/tokens.css`) | Online Shop (`templates/shop/assets/css/tokens.css`) | POS (`templates/pos/assets/css/tokens.css`) | Overridable by `/{org}/branding.css` |
| --- | --- | --- | --- | --- |
| `--color-bg` | `var(--gfh-50)` (`#eff3ff`) | `#fbfaf7` (warm sand) | `#f4f6f8` (counter slate) | Yes (`background`) |
| `--color-surface` | `#ffffff` | `#ffffff` | `#ffffff` | No |
| `--color-ink` | `#1a2a3a` | `#1f2328` | `#111827` | Yes (`ink`) |
| `--color-primary` | `var(--gfh-600)` | `#0b6e69` (editorial teal) | `#1d4ed8` (high-contrast blue) | Yes (`primary`) |
| `--color-primary-strong` | `var(--gfh-800)` (`#08519c`) | `#084c48` | `#1e3a8a` | Yes (`primary_strong`) |
| `--color-heading` | `var(--color-primary-strong)` (`#08519c`) | `var(--color-ink)` | `var(--color-ink)` | Follows `primary_strong` / `ink` |
| `--radius-card` | `10px` | `14px` | `12px` | Yes (`radius_card`) |
| `--radius-control` | `8px` | `999px` | `10px` | Yes (`radius_control`) |
| `--control-height` | `40px` | `48px` | `56px` | No |

In the Admin app, `--gfh-800` (`#08519c`) is reserved for headings (`h1`–`h3`) and key KPI numbers (`.kpi-card__value`), while body copy uses `--color-ink` (`#1a2a3a`).

## Online Shop tokens and components (Batch 6.1)

The Shop has its own look: warm, spacious and image-led. Every color, font and radius is a CSS variable, so `/{org}/branding.css` re-themes all of it. Raw values (`#hex`, `rgba()`) exist only in `templates/shop/assets/css/tokens.css`; `app.css` uses variables only (a test enforces both).

New tokens, all in `templates/shop/assets/css/tokens.css`:

| Variable | Value | Used for |
| --- | --- | --- |
| `--shop-sand-100`, `--shop-sand-200`, `--shop-stone-600` | `#f3efe6`, `#e6e0d2`, `#6b6558` | Palette steps behind the hero, tiles and placeholder |
| `--color-hero-bg`, `--color-tile-bg` | `var(--shop-sand-100)` | Hero block, category tiles, the cart note |
| `--color-placeholder-bg`, `--color-placeholder-mark` | `var(--shop-sand-200)`, `var(--shop-stone-600)` | The inline "no photo" picture |
| `--color-chip-bg` | `var(--shop-teal-50)` | Filter chips, price tiers, selected variant |
| `--color-flag` | `var(--shop-coral-600)` | The "Out of stock" flag on cards |
| `--radius-tile`, `--radius-chip` | `var(--radius-card)`, `var(--radius-control)` | Tiles and cards follow the organization's card radius; chips and pagination its control radius |
| `--shadow-card`, `--shadow-card-hover` | two-layer soft shadows | Product card picture, category tile hover |
| `--text-3xl`, `--text-display` | `clamp(2rem, 1.5rem + 2vw, 2.75rem)`, `clamp(2.25rem, 1.5rem + 3.5vw, 3.75rem)` | Page titles, the home hero title (Source Serif 4) |
| `--leading-tight`, `--leading-body`, `--tracking-eyebrow` | `1.15`, `1.65`, `0.08em` | Headings, running text, small capitals labels |
| `--aspect-card` | `4 / 5` | The picture area of every card, gallery photo and thumbnail |
| `--card-gap`, `--filter-width`, `--touch-target` | `var(--space-5)`, `280px`, `48px` | Grid gap, filter column, minimum height of every control |

Components in `templates/shop/assets/css/app.css` (one template per screen or partial, each overridable from `orgs/{slug}/shop/`):

| Component | Template | Notes |
| --- | --- | --- |
| Header (`.shop-header`) | `partials/header.php` | Brand, search box, two empty slots `data-slot="cart"` and `data-slot="account"` for Batch 6.2, menu button below 720 px, navigation |
| Search box (`.shop-search`) | `partials/search-form.php` | GET form, 48 px high |
| Hero (`.shop-hero`), category tiles (`.category-tile`) | `home.php` | Organization name, its own line, top-level categories |
| Product card (`.product-card`) | `partials/product-card.php` | 4:5 picture (`.media-frame`), brand, name, price, out-of-stock flag; the whole card is one link |
| Placeholder (`.shop-placeholder`) | `partials/placeholder.php` | Inline SVG, no file; also copied by the script when a photo fails to load |
| Filters (`.shop-filters`, `.filter-group`, `.choice`) | `partials/filter-form.php` | A GET form; a drawer below 840 px when JavaScript is on |
| Chips (`.chip`) | `partials/chips.php` | One removable link per active filter |
| Pagination (`.pagination`) | `partials/pagination.php` | Keeps the whole state in every link |
| Gallery (`.gallery`) | `partials/gallery.php` | All photos visible without JavaScript; one at a time with it |
| Variant options (`.variant-option`) | `partials/variant-selector.php` | Radio buttons in a GET form; the script updates the page in place |
| Tier prices (`.tier-prices`) | `partials/tier-prices.php` | Only for a signed-in customer |
| Add to cart (`.add-to-cart`) | `partials/add-to-cart.php` | Built, disabled in 6.1, with a visible note |

Breakpoints: 560 px (3 and then 4 category tiles, auto-fill product grid), 720 px (menu button gone, header on one row), 840 px (listing and product pages in two columns, no filter drawer), 1000 px (4 tiles). Pictures carry `width` and `height` and load lazily (the first photo on a product page loads at high priority instead). Focus rings come from `base.css`. Contrast of the default palette, computed with the WCAG formula (every text pair is above 4.5 to 1): `--color-ink` on `--color-bg` 15.1, on the hero 13.8, on the chip background 14.5; `--color-muted` on `--color-bg` 7.1, on white 7.4, on the hero and footer 6.5, on the chip background 6.8; white on `--color-primary` 6.1 and on `--color-primary-strong` 9.8; `--color-primary` links on white 6.1; the "Out of stock" flag (`--color-flag`) on white 5.4 (it only appears on white cards); the disabled cart button 5.8. Non-text parts: control borders on white 3.2 and the placeholder mark 4.4 (both above 3). An organization's own branding colors are its responsibility; these figures describe the shipped palette only.

## Shared chart theme

Admin charts and KPI sparklines read their palette from CSS variables so they always match the active theme:

- `--chart-1`: `var(--gfh-500)` (`#3182bd`)
- `--chart-2`: `var(--gfh-800)` (`#08519c`)
- `--chart-3`: `var(--gfh-300)` (`#6baed6`)
- `--chart-4`: `var(--gfh-200)` (`#9ecae1`)
- `--chart-grid`: `var(--color-border-soft)`

The locally vendored Chart.js module (`public/assets/js/vendor/chart.js`) and the shared helper (`templates/admin/assets/js/chart-theme.js`) consume these variables with zero external network requests.
