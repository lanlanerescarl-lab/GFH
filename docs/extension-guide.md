# How to write an extension (Batch 8.2)

This guide is the short path. `docs/extensions.md` is the reference (manifest fields, setting types, every hook name and what the loader does with a broken extension). The working example is `extensions/care-notes/`: about 100 lines, four hooks, one setting.

## 1. Shared or organization-local

| Where | Who gets it | Turned on by |
| --- | --- | --- |
| `extensions/{key}/` | Every organization that has the feature | The organization's feature `{key with hyphens as underscores}` (`care-notes` is switched by `care_notes`) |
| `orgs/{slug}/extensions/{key}/` | Only that organization | Nothing: it always loads for `{slug}`. Same `key` as a shared one: the local one replaces it |

Write an extension shared when more than one organization could want it, local when it holds one organization's own rules.

## 2. The three files

```
extensions/care-notes/
  extension.json   manifest: key, name, version, entry, the hooks you will use, your settings
  register.php     entry file: returns a Closure that takes an ExtensionContext
```

`extension.json` must name every hook you register under `hooks`, and every setting key must start with the key (hyphens as underscores) and a dot: `care_notes.section_title`. Register hooks with `$x->hook('name', fn (mixed $value, array $context): mixed => ...)` and read a setting with `$x->setting('care_notes.section_title')` **inside** a callback.

Write the entry file with closures only: no `function name()` and no `class` at the top level. The file is loaded once per request and per organization, so a named function would be declared twice in a process that serves two organizations.

## 3. Hooks you can use for a product

| Hook | What `care-notes` does with it |
| --- | --- |
| `navigation.admin` | Adds an item. Give it `module` and `action` and only people who pass `can()` see it. Extension pages do not exist yet, so link to an existing Admin page |
| `product.form.tabs` | Adds the "Care notes" tab. The context holds `product`, `can_edit`, `admin_base`, and (new in 8.2) `csrf`, `errors` and `input` so the tab can post its own form and show a refused value again |
| `product.form.save` | Validates the posted field and puts the value in the product's attributes. Throw `HttpException::unprocessable(['field' => 'message'])` to refuse the save; the Admin shows the message and keeps what was typed |
| `product.detail.sections` | Adds the section on the Shop product page. Return the list unchanged to print nothing |

A tab's form posts to `{admin_base}/products/{id}` with `_csrf`, a hidden `tab` with the tab's id (the page returns to that tab), and its own field name. A form that posts only some product fields leaves the others unchanged.

## 4. Storing data

The Migrator does not run an extension's `migrations/` folder yet. For a per-product value use an **extension-owned attribute**: a product attribute whose key starts with `ext_` (`ext_care_notes`). Core never shows it in the Admin attribute list or the Shop attribute table or filters, keeps it when the attribute list is saved, and never gives it to the public API. Your hooks get it in `product.attributes` (Shop) and in `current_attributes` (save). Details and limits are in `docs/extensions.md`. **Nothing secret goes in an `ext_` attribute**: it travels with the public product to your Shop hooks, and a value is at most 200 characters.

## 4b. Checkout fields

To ask the customer for something at checkout (a gift note, a company name, a time slot, an "I agree" box), return field definitions from the hook `checkout.fields` and, if a value changes a price, read it in `checkout.adjustments` from `$context['checkout']`. The shape of a definition, the checks the server makes and a working example are in `docs/extensions.md` under "Checkout fields". Three things to know: the server refuses unknown ids and bad values with a 422 on `checkout.{id}`, so you never have to re-check them; a bad definition is ignored and logged, not an error; and **the values are not stored with the order** (the schema has no place for them and Batch 8.3 adds no migration), so a value is only available to the hook that prices that order.

## 4c. POS receipt lines

To print something under a product on the POS receipt (a warranty period, say), keep the value in an `ext_` product attribute and return lines from the hook `pos.receipt.lines`; the lines travel in the terminal snapshot, so they print offline too and a reprint shows what was printed the first time. The shape, the limits (3 lines per product, 120 characters, plain text), a warranty example and what happens when a terminal's snapshot is older than your data are in `docs/extensions.md` under "POS receipt lines". Extra per-line data (serial numbers) and extra payment methods are **not provided**: see "Not provided yet" there.

## 5. Escaping and what is trusted

`bodyHtml` and `contentHtml` are printed exactly as you wrote them, with no escaping, the same trust the Admin gives order panels. So an extension is trusted code: it must pass every value that came from a product, a customer or a request through `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Section and tab `title` and `label` are plain text and the page escapes them. No external hosts, no inline scripts that build HTML from data, no costs or staff ids in anything a customer can see. A CSRF token for a form comes from the hook context (`csrf`); never make one up.

A callback may refuse a save or a sale on purpose by throwing `HttpException`; that is the only exception that reaches the visitor, with its status and field messages. Two kinds of failure are isolated, and the page works without the broken part:

- A **registration** that throws (bad manifest, parse error, undeclared hook): the whole extension is skipped and logged once.
- A **callback** that throws anything else when the hook runs, or returns another type than it received (a string or `null` where the hook passes a list): that one callback is skipped, the value from before it is kept, the next callbacks still run, and one line is written to `storage/logs/app.log` (once per request and callback) with the hook, your extension key, the exception class and its message. The visitor sees nothing.

So a rule you depend on must refuse with `HttpException`: any other exception is skipped and the action goes ahead. Keep secrets and customer input out of exception messages (the log keeps them, cut to 300 characters), and do not write to the database from a callback. Details and limits are in `docs/extensions.md` under "When a hook callback fails".

## 6. Turn an extension on or off

There is no Super Admin screen yet (`superadmin/` is a README). Features live in the core database table `organization_features`. A new organization gets them at creation with `php bin/make-org.php ... --features=care_notes`. For an organization that already exists, use `psql` as the migrator role, which asks for its password and never needs it in a file:

On:

```
psql -h localhost -U gfh_migrator -d gfh_core -c "INSERT INTO organization_features (organization_id, feature_key, enabled) SELECT id, 'care_notes', true FROM organizations WHERE slug = 'demo' ON CONFLICT (organization_id, feature_key) DO UPDATE SET enabled = true;"
```

Off (the row stays, the notes stay in the products):

```
psql -h localhost -U gfh_migrator -d gfh_core -c "UPDATE organization_features SET enabled = false WHERE feature_key = 'care_notes' AND organization_id = (SELECT id FROM organizations WHERE slug = 'demo');"
```

Change `demo` to the organization's slug. The change shows on the next request; nothing to restart or publish. Turning it off hides the Admin item, the tab and the Shop section and stops the hooks; turning it on again brings every saved note back.

## 7. Override rules (templates)

An organization may replace any template file without touching `templates/`: put the file at the same relative path under `orgs/{slug}/` (`orgs/demo/shop/partials/home-intro.php` replaces the empty `templates/shop/partials/home-intro.php` for `demo` only, which is how the demonstration organization gets its short introduction on the Shop home page). The organization's file wins, then `templates/`. Prefer replacing a small partial to replacing a whole page, so core changes to the page still reach the organization. Removing the file restores the default.

## 8. Make a new organization

`orgs/_skeleton/` is the starting point: a README in its root and in each folder, `branding.example.json`, and nothing that is a live override. `bin/make-org.php --from-skeleton` copies it to `orgs/{slug}/` and registers the organization.

Before you start, the PostgreSQL database and roles for the organization must exist: the command does **not** create them (that needs a superuser; `docs/setup.md` has the steps). Then:

```
php bin/make-org.php --slug=my-shop --name="My Shop Ltd" --short-name=MyShop --from-skeleton --branding="{...}"
```

- The slug must pass the same check as every organization slug (3 to 32 lower-case letters, digits and hyphens, starting with a letter). If `orgs/my-shop/` already exists, the command stops **before registering anything** and never overwrites or merges into it; remove or rename the folder yourself if you really want a fresh copy.
- The connection is never typed on the command line. Set `GFH_ORG_CONNECTION` in the shell session (the limited `gfh_app` URL), or leave it unset and type it at the hidden prompt.
- The organization is registered as usual. If the connection was tested and works (that is, you did not pass `--no-test`), the command then runs `php bin/migrate.php --org=my-shop` for you. It needs the migrator role: set `MIGRATOR_URL_MY_SHOP` in `.env` first, or the stored connection must be allowed to create tables.
- It always ends with the steps that are left, with this organization's slug filled in: the database and roles, migrating (only when that did not run), `database/roles/04_grant_org.sql` as a superuser, the first staff account with `bin/create-user.php`, and switching extensions on.
- Exit code 1 means a step after registration failed (the folder could not be copied, or the migration did not finish). The organization **stays registered**; fix the cause and run the printed step.
- Without `--from-skeleton` the command works as before: it registers the organization and creates no folder.
- Branding is a JSON object kept in the registry, set with `--branding` at registration. `orgs/my-shop/branding.example.json` lists the keys it accepts (`primary`, `primary_strong`, `background`, `ink`, `radius_card`, `radius_control`; colors need enough contrast). There is no command yet to change it later.

Then read `orgs/my-shop/README.md`, and add an override only where the organization really differs (section 7).
