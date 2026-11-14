# Database roles

GFH uses four PostgreSQL roles so that a bug or a stolen web credential cannot change the schema or rewrite history.

| Role | Used by | Rights |
| --- | --- | --- |
| `gfh_migrator` | `bin/` scripts only | Creates and alters tables, owns them |
| `gfh_app` | Web requests to an organization database | SELECT, INSERT, UPDATE on tables. Only SELECT and INSERT on `stock_movements` and `audit_log`. DELETE only on `product_images` and `price_tiers`. Cannot change `schema_migrations` |
| `gfh_reader` | Super Admin app | SELECT on the `org_contract` views only |
| `gfh_core_app` | Web requests to the core database | SELECT on everything; INSERT and UPDATE on accounts, memberships, customers, addresses and reset tokens; INSERT only on `audit_log` |

The append-only tables (`audit_log` in both kinds of database, and `stock_movements`) are also protected by triggers,
so even a role that was granted UPDATE or DELETE by mistake cannot change them.

## Scripts and when to run them

| Script | Run | Where |
| --- | --- | --- |
| `01_create_roles.sql` | once per server, before anything else | any database, as superuser |
| `02_prepare_database.sql` | once per database, before its first migration | each database, as superuser |
| `03_grant_core.sql` | after migrating the core database, and after any migration that adds a table | core database, as superuser |
| `04_grant_org.sql` | after migrating an organization database, and after any migration that adds a table | each organization database, as superuser |

Every script is safe to run again. After pulling a new stage, run the migrations and then `03_grant_core.sql` / `04_grant_org.sql` again, so new tables and views get their grants (Stage 3 needs `04_grant_org.sql` to let the web role read the `org_contract` views). The scripts assume the default schema `public`.

## Order of steps on an empty server

1. Create the empty databases: `createdb -h localhost -U postgres gfh_core` and `createdb -h localhost -U postgres gfh_org_demo`
2. `01_create_roles.sql`, then `02_prepare_database.sql` in both databases.
3. Put the migrator credentials in `.env` (`CORE_DB_MIGRATOR_USER`, `CORE_DB_MIGRATOR_PASSWORD`, and `MIGRATOR_URL_DEMO` for the organization).
4. `php bin/migrate.php --core`, register the organization with `php bin/make-org.php`, then `php bin/migrate.php --org=demo`.
5. `03_grant_core.sql` in the core database and `04_grant_org.sql` in the organization database.

The full commands, with example values, are repeated in `docs/setup.md` in Stage 9.

Managed hosting: confirm in the host's current documentation how its own API roles (for example `anon` and `authenticated`
on Supabase) are restricted. The grant scripts revoke their table rights but do not replace that check.
