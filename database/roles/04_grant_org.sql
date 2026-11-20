-- Grants for an ORGANIZATION database. Run as a superuser while connected to that organization's
-- database, after bin/migrate.php --org=slug, and again after every migration that adds a table:
--
--   psql -h localhost -U postgres -d gfh_org_demo -f database/roles/04_grant_org.sql
--
-- Assumes the default schema (public). Safe to run repeatedly.

\set ON_ERROR_STOP on

DO $$
BEGIN
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO gfh_app, gfh_reader', current_database());
END
$$;

GRANT USAGE ON SCHEMA public TO gfh_app;
REVOKE ALL ON SCHEMA public FROM gfh_reader;

-- Web requests: read and write operations data, never change history, never touch the migration log.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM gfh_app;
GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO gfh_app;
REVOKE UPDATE ON stock_movements, audit_log FROM gfh_app;    -- append-only: SELECT and INSERT only
REVOKE INSERT, UPDATE ON schema_migrations FROM gfh_app;
-- Business records are never deleted. The only deletable rows are product photo links, price tiers, and order steps
-- that no order or history entry points at (the Settings page checks that before it deletes; the foreign keys back it up).
GRANT DELETE ON product_images, price_tiers, order_progress_steps TO gfh_app;

-- The Admin dashboard reads the same contract views, through the web-request role.
GRANT USAGE ON SCHEMA org_contract TO gfh_app;
GRANT SELECT ON ALL TABLES IN SCHEMA org_contract TO gfh_app;

-- Super Admin: the contract views only. The views read the tables with their owner's rights,
-- so the reader needs no access to the tables themselves.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM gfh_reader;
GRANT USAGE ON SCHEMA org_contract TO gfh_reader;
REVOKE ALL ON ALL TABLES IN SCHEMA org_contract FROM gfh_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA org_contract TO gfh_reader;

-- Managed hosting: Supabase's own API roles must not reach these tables.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon;
    REVOKE ALL ON ALL TABLES IN SCHEMA org_contract FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON ALL TABLES IN SCHEMA public FROM authenticated;
    REVOKE ALL ON ALL TABLES IN SCHEMA org_contract FROM authenticated;
  END IF;
END
$$;
