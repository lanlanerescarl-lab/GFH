# Organization skeleton

This folder is the starting point for a new organization. `php bin/make-org.php --slug=<slug> --name="<Full name>" --from-skeleton` copies it to `orgs/<slug>/`, registers the organization and runs its database migrations when the connection works. The command never overwrites: it stops if `orgs/<slug>/` already exists.

Nothing in this folder is live. A file here changes no page until it has the same name and place as a core template and sits in an organization's own folder. The skeleton itself belongs to no organization, so it holds no organization's name and no template override.

## What goes where

| Folder or file | Holds |
| --- | --- |
| `shop/` | Shop template overrides (customer pages) |
| `admin/` | Admin template overrides (staff pages) |
| `pos/` | POS template overrides (till pages) |
| `extensions/` | Extensions that only this organization uses |
| `database/seeds/` | Seed data for this organization only (sample or starting data, never passwords or secrets) |
| `branding.example.json` | A sample of the branding keys. Branding is kept in the registry, not read from this file |

## Rules in one place

- An organization file wins over `templates/`. The path under the app folder must match: `orgs/<slug>/shop/partials/home-intro.php` replaces `templates/shop/partials/home-intro.php` for this organization only.
- Override the smallest thing that does the job: a partial before a page. A whole-page override stops following core changes to that page.
- Keep organization names and organization-specific wording out of `core/` and `templates/`; they belong here.
- No external hosts, no secrets and no costs or staff ids in anything a customer can see.
- Escape every value you print.

More in `docs/extension-guide.md`.
