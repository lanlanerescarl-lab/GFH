# Shop overrides

Put a file here to replace the core Shop template of the same name for this organization only. The path must match the one under `templates/shop/`: `partials/home-intro.php` here replaces `templates/shop/partials/home-intro.php`; `home.php` replaces the whole home page.

Images, styles and scripts for these pages go in `shop/assets/` and are published to `public/assets/orgs/<slug>/shop/` by `php bin/publish-assets.php`.

Prefer a partial to a whole page. Escape every value (`$this->e(...)`). Nothing a customer can see may show costs, staff ids or external hosts.

This folder is empty on purpose: a file here is a live override.
