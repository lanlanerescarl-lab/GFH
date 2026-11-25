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

## 5. Escaping and what is trusted

`bodyHtml` and `contentHtml` are printed exactly as you wrote them, with no escaping, the same trust the Admin gives order panels. So an extension is trusted code: it must pass every value that came from a product, a customer or a request through `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Section and tab `title` and `label` are plain text and the page escapes them. No external hosts, no inline scripts that build HTML from data, no costs or staff ids in anything a customer can see. A CSRF token for a form comes from the hook context (`csrf`); never make one up.

A callback may refuse a save on purpose by throwing `HttpException`. A **registration** that throws (bad manifest, parse error, undeclared hook) is isolated: the extension is skipped, logged once, and the page works without it.

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

An organization may replace any template file without touching `templates/`: put the file at the same relative path under `orgs/{slug}/` (`orgs/demo/shop/home.php` replaces `templates/shop/home.php` for `demo` only). The organization's file wins, then `templates/`. Prefer replacing a small partial to replacing a whole page, so core changes to the page still reach the organization. Removing the file restores the default.

*(Making a new organization from `orgs/_skeleton` with `bin/make-org.php` is Batch 8.2b and is documented there.)*
