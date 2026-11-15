# GFH: rules for the coding agent

Antigravity loads this file on every turn. It holds the rules that never change. The long detail lives in `docs/`, so read those files when a task needs them.

## What this project is

GFH is one shared PHP + PostgreSQL codebase that serves several organizations. A central core database holds accounts and the organization registry. Each organization has its own database. Every organization gets three apps (Shop, POS, Staff/Admin), and there is one Super Admin app. It is a class project that must look and behave like a real business system, and it is due **before 1 December 2026**. Three teammates will later build product lines on top of this foundation, so its conventions, templates and docs must stay clear and stable.

## Status (update this section at the end of every stage)

- The master plan is section 12 of the Reusable Template Development Prompt. The foundation has 10 stages, numbered 0 to 9. Stages 0, 1, 2 and 3 are finished and verified (251 tests passing after Stage 4 work).
- Stage 4, Shared UI kit and Admin shell: template lookup, asset publishing, branding, the three layout shells and the extension loader are built. The Admin component library, the UI kit page, the token sets with docs/design-tokens.md and the permission and hook based sidebar are being checked.
- Stage 5 Staff/Admin template: dashboard, inventory, products, procurement, sales and customers, reports and admin (team, settings for branches, terminals, discount rules, order steps).
- Stage 6 Online Shop template. Stage 7 POS template with offline sync. Stage 8 demonstration extension, orgs/_skeleton, orgs/demo and demo seed data. Stage 9 documentation and hardening.
- Complete one stage (or one named batch), then stop and wait for the owner. Never start the next one on your own.
- The OLAP module is built separately. Do not build it here.

## Read first

@docs/conventions.md
@docs/testing.md
@docs/api.md
@database/roles/README.md

`docs/conventions.md` is authoritative for style, layers, security and database rules. If a rule here and a document disagree, tell the owner and fix the document.

## Rules that never change

- **Stack:** PHP 8.2 or newer, HTML, CSS, vanilla JavaScript (ES modules), plain PostgreSQL 13+ through PDO. No framework, no Tailwind, no build step, no external hosts for fonts, icons or libraries. The code must run without deprecation notices on PHP 8.5.
- **Layers:** Router, Controller, Service, Repository, PostgreSQL. Business rules live only in services. Every PHP file starts with `declare(strict_types=1);`, and one class per file.
- **Security:** prepared statements only; check `PermissionService::can()` in every controller action; every state-changing request carries the CSRF token; escape all view output with `$this->e()`; nothing about authentication in browser storage; never log or print a connection string.
- **Database:** never edit a migration that has been applied. Fix a mistake with a new, higher-numbered file. Migrations hold no BEGIN or COMMIT. Web requests use the limited roles; only `bin/` scripts use the migrator role. After any new migration or database view, tell the owner to rerun `database/roles/03_grant_core.sql` and `04_grant_org.sql`.
- **Money and stock:** prices are VAT-inclusive with 12% VAT extracted. Prices and totals are worked out only in `PricingService`, costs only in `InventoryService` (weighted average). Stock on hand is the sum of append-only movements and is never edited. Business records are never deleted.
- **Roles:** Super Admin and Group Viewer never get edit or delete on operations.
- **Organizations are data:** no organization names hard-coded in `core/` or `templates/`. An organization changes behavior through `orgs/{slug}/` overrides and through extensions and hooks, never by editing core classes.

## Environment (the owner's machine)

- Windows with **PowerShell**. Project folder: `C:\Users\laner\OneDrive\Documents\Phoenix Code\gfh`. PHP 8.5 is in `C:\PHP`, PostgreSQL 18 is in `D:\Tools\PostGreSQL`, Git is in `D:\Tools\Git`. The terminal needs these on the PATH for the session: `$env:Path = "C:\PHP;D:\Tools\PostGreSQL\bin;" + $env:Path`
- Write commands for PowerShell on one line. No `&&`, no backslash line breaks, no `export`.
- Databases: `gfh_core` and `gfh_org_demo` (organization slug `demo`) hold real local data. `gfh_core_test` and `gfh_org_test` are throwaway test databases.
- Start the site: `php -S localhost:8000 -t public`. Apply database changes: `php bin/migrate.php --all`. Publish app assets: `php bin/publish-assets.php`.
- Run the tests: first set the test variables in the terminal with this line, which reads the password from `.env` without printing it, then run `php phpunit.phar`:

```powershell
$p = ((Get-Content .env | Where-Object { $_ -match '^CORE_DB_MIGRATOR_PASSWORD=' }) -replace '^CORE_DB_MIGRATOR_PASSWORD=','').Trim('"'); $env:GFH_TEST_CORE_URL = "postgresql://gfh_migrator:$p@127.0.0.1:5432/gfh_core_test?sslmode=prefer"; $env:GFH_TEST_ORG_URL = "postgresql://gfh_migrator:$p@127.0.0.1:5432/gfh_org_test?sslmode=prefer"
```

- Tests may only ever touch the `_test` databases. Without those two variables the database tests are skipped, and a skipped test is not a pass.

## Safety, because you have terminal access

- Never print, copy into a file, commit or paste `.env`, a password, `APP_KEY` or a connection string, in the chat or anywhere else.
- Ask the owner first, and explain what would be lost, before any command that deletes or resets data: `DROP`, `TRUNCATE`, `dropdb`, re-registering an organization, deleting `storage/` content, or rewriting many files.
- Do not install software, change `php.ini`, the PATH or system settings, or touch files outside the project folder without asking.
- Do not run commands against the `postgres` superuser. Database changes go through the `bin/` scripts and migration files.

## How to work

- For work that touches several files, give a short plan first (about 15 lines): the files you will add or change and any assumption. Then build in small batches.
- Edit files in place. Do not rewrite a file you do not need to change.
- Add or update tests with every change, and **run the full test suite yourself** before you say a batch is done. Report the exact result (tests, assertions, failures, skipped, deprecations). If you could not run something, say so.
- Comment the reason for a rule, not what the line does. Keep `docs/` in step with the code: a new feature is not done until its document is updated.
- End every batch with: the files you changed, the test result, and exactly what the owner must do next (migrate, grants, publish assets, restart the server).
- Two local fixes are part of the baseline. Keep them. `PermissionService::activeMembership()` caches only active memberships, so a miss is looked up again. `MediaService` no longer calls `imagedestroy()`, which PHP 8.5 deprecates.
- Git: the repository is lanlanerescarl-lab/GFH on the main branch. After the tests pass you may run git add and git commit when the owner asks. Never push, never force-push, never run Git commands outside this folder, and never commit .env or phpunit.phar.