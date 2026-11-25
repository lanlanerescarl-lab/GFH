# Setup

Initial setup for the GFH server (local development or staging/production). Database role details live in [database/roles/README.md](../database/roles/README.md), and test database setup lives in [testing.md](testing.md).

## 1. Environment file and `APP_KEY`

1. Copy `.env.example` to `.env` in the project root. Never commit `.env`.
2. Generate `APP_KEY` and set it in `.env`:

   ```powershell
   php -r "echo 'base64:' . base64_encode(random_bytes(32)), PHP_EOL;"
   ```

3. Set the core database credentials (`CORE_DB_HOST`, `CORE_DB_PORT`, `CORE_DB_NAME`, `CORE_DB_USER`, `CORE_DB_PASSWORD`, and for CLI migrations `CORE_DB_MIGRATOR_USER` and `CORE_DB_MIGRATOR_PASSWORD`).

## 2. Core and organization databases

1. Create the empty core and organization databases and run `database/roles/01_create_roles.sql` and `02_prepare_database.sql` as described in [database/roles/README.md](../database/roles/README.md).
2. Migrate the core database:

   ```powershell
   php bin/migrate.php --core
   ```

3. Register each organization with its **limited web-request role** (`gfh_app`) URL. Pass the connection via `GFH_ORG_CONNECTION` in the shell session or leave it unset to enter it at the hidden interactive prompt (never pass a connection string as a command-line argument):

   ```powershell
   php bin/make-org.php --slug=demo --name="Demo Organization" --short-name=Demo
   ```

   To also copy `orgs/_skeleton` to `orgs/<slug>/` and run the organization migrations when the connection works, add `--from-skeleton` (see `docs/extension-guide.md`, section 8). The database and roles must exist first; the command does not create them.

4. Migrate the organization databases (`MIGRATOR_URL_<SLUG>` in `.env` overrides the stored connection for migrations and seeds only):

   ```powershell
   php bin/migrate.php --org=demo
   ```

5. Run `database/roles/03_grant_core.sql` on the core database and `database/roles/04_grant_org.sql` on each organization database, then publish assets:

   ```powershell
   php bin/publish-assets.php
   ```

## `APP_KEY` and stored connection strings

Organization database URLs are stored encrypted in `gfh_core` (`organizations.connection_encrypted`) using libsodium secretbox keyed by `APP_KEY`. `APP_KEY` is also used as the HMAC key for `login_attempts.identifier_hash`.

> **Warning:** Keep a safe backup copy of `APP_KEY` in a password manager or secret store. Changing `APP_KEY` in `.env` immediately invalidates every stored `organizations.connection_encrypted` value (organization requests fail with `SecretBoxException`). Losing `APP_KEY` means you must re-encrypt and update the stored connection string for every registered organization.

To re-register an organization's stored connection under the current `APP_KEY` (or after rotating the `gfh_app` database password) without altering its `db_schema`, `branding`, `features`, `memberships`, `migration_version`, or organization database data:

```powershell
php bin/make-org.php --slug=demo --update-connection
```

Supply the organization's `gfh_app` connection URL via `GFH_ORG_CONNECTION` in the environment (clear it afterwards) or type it at the hidden interactive prompt. Pass `--schema=<name>` only if you also intend to change the organization's stored `db_schema`, and `--no-test` only if the target database is temporarily unreachable from this machine.
