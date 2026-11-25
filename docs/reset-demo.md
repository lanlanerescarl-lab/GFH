# Reset the demo organization

Use this when the demo data has drifted (test orders, edited products, odd stock) and you want the clean development seed back.

**What this erases:** the whole `gfh_org_demo` database, which means every product, variant, price, stock movement, purchase order, order, payment, POS sale, customer record and organization setting of the demo organization.

**What it keeps:** the core database `gfh_core`. Staff accounts, their roles in the demo organization, the organization registration and the encrypted connection string stay as they are, so you do not create users again and you do not run `bin/make-org.php` again. The development seed reads the first active member of `demo` from the core database, so at least one staff account must already exist (`php bin/create-user.php`, see `docs/testing.md`).

Run the steps in order, one line at a time, in PowerShell. `dropdb`, `createdb` and `psql` ask for the password of the `postgres` superuser each time; type it at the prompt. Stop at the first line that prints an error and do not carry on.

These steps follow `docs/setup.md` (sections 2.4 and 2.5) and `database/roles/README.md` ("Order of steps on an empty server"). The roles (`01_create_roles.sql`) and the core grants (`03_grant_core.sql`) already exist and are not repeated.

## Steps

1. Stop the web server if it is running (press `Ctrl+C` in its window). An open connection would block the drop.

2. Go to the project folder:

   ```powershell
   Set-Location "C:\Users\laner\OneDrive\Documents\Phoenix Code\gfh"
   ```

3. Put PHP and PostgreSQL on the PATH for this window:

   ```powershell
   $env:Path = "C:\PHP;D:\Tools\PostGreSQL\bin;" + $env:Path
   ```

4. Check that `.env` has the migrator URL for the demo organization. This prints only a message, never the value:

   ```powershell
   if (Select-String -Path ".env" -Pattern '^MIGRATOR_URL_DEMO=' -Quiet) { "MIGRATOR_URL_DEMO is set" } else { "MIGRATOR_URL_DEMO is missing: add it to .env first (database/roles/README.md, step 3)" }
   ```

5. Drop the demo organization database (this is the line that erases the demo data; `--force` closes any connection still open):

   ```powershell
   dropdb -h localhost -U postgres --if-exists --force gfh_org_demo
   ```

6. Create it again, empty:

   ```powershell
   createdb -h localhost -U postgres gfh_org_demo
   ```

7. Prepare it so the migrator role can create tables (`02_prepare_database.sql`):

   ```powershell
   psql -h localhost -U postgres -d gfh_org_demo -f database/roles/02_prepare_database.sql
   ```

8. Apply every organization migration:

   ```powershell
   php bin/migrate.php --org=demo
   ```

9. Give the limited roles their rights on the new tables and views (`04_grant_org.sql`):

   ```powershell
   psql -h localhost -U postgres -d gfh_org_demo -f database/roles/04_grant_org.sql
   ```

10. Load the development seed:

    ```powershell
    php bin/seed.php --demo
    ```

11. Publish the app assets (only needed if you also pulled new template or CSS files):

    ```powershell
    php bin/publish-assets.php
    ```

12. Start the site again:

    ```powershell
    php -S localhost:8000 -t public
    ```

## Check that it worked

Step 8 should print `applied` for each migration file, and step 10 should end with `3 seed file(s) run` (the whole run takes under half a minute). This prints how many branches the seed created (expected: `3`); the same check on `products` should give `24`, and `orders` and `pos_sales` should hold a few hundred and about a thousand rows:

```powershell
psql -h localhost -U postgres -d gfh_org_demo -t -c "SELECT COUNT(*) FROM branches;"
```

Then sign in at `http://localhost:8000/demo/admin/login`. The dashboard, Inventory, Products, Procurement, Orders, Customers and POS sales pages should all show data again.

## Good to know

- The seed is repeatable but does nothing when its anchor already exists: the branch `BCD-MAIN` for the first seed file and the variant `IC-RCK-18W` for the second. The history seed's anchor is the customer `ana.bautista.01@example.test`. That is why the database is dropped first.
- **The history seed writes past the append-only guard.** `stock_movements` is append-only: nothing can be updated or deleted afterwards, and every service stamps a new movement with the moment it is written. To date about four months of sales, restocking and orders in the past, seed `0003_demo_history.php` creates one temporary `BEFORE INSERT` trigger on `stock_movements` (`gfh_seed_clock`) that replaces the time of each new movement with the time the seed is simulating. The trigger is created and dropped inside the seed's own transaction, so it never exists outside the seed (a test checks this), it works only for the migrator role that owns the table, and the guard against UPDATE, DELETE and TRUNCATE is not touched. Everything else on the dated rows (orders, payments, shifts, purchase orders, batches) is corrected with ordinary updates. Run this seed only on the demo database, never on one that holds real stock history.
- The history is generated from a fixed random seed, so every reset gives the same shape of data. Dates are relative to the day you run it (the last 119 days, ending today), and the generated identifiers differ from run to run.
- Seed 0001's own three sales happen today; after the history they get the receipt numbers that follow it, so each terminal's numbers rise with time.
- `php bin/seed.php --demo` only works for the organization `demo`; with any other `--org` it stops before it opens a database. The catalog seed refuses any other slug as well. It stores no password or secret.
- Product photos uploaded earlier stay as files in `storage/uploads/demo/`. After a reset nothing points to them. They do no harm; delete that folder's contents by hand only if you want the space back. **A reset does not delete orphaned files under `storage/uploads`**, and the seed draws and stores a fresh set of 30 photos (60 files with their thumbnails, about 1 MB) under new random names every time it runs on an empty database, so each reset adds one more set. A seed that fails halfway also leaves the photos it had already written, because files are not part of the database transaction.
- Shop customers who registered online live in `gfh_core`. After a reset their account remains, but the organization's customer records are new, so they appear as customers without past orders.
- Never run these steps against `gfh_core` or against the `_test` databases.
