-- Grants for the CORE database. Run as a superuser while connected to the core database,
-- after bin/migrate.php --core, and again after every migration that adds a table:
--
--   psql -h localhost -U postgres -d gfh_core -f database/roles/03_grant_core.sql
--
-- Assumes the default schema (public). Safe to run repeatedly.

\set ON_ERROR_STOP on

DO $$
BEGIN
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO gfh_core_app', current_database());
END
$$;

GRANT USAGE ON SCHEMA public TO gfh_core_app;

-- Start from nothing, then grant exactly what web requests need.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM gfh_core_app;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO gfh_core_app;
GRANT INSERT, UPDATE ON users, memberships, customers, customer_addresses, password_reset_tokens TO gfh_core_app;
GRANT DELETE ON customer_addresses TO gfh_core_app;        -- a customer may remove a saved address
GRANT INSERT ON audit_log, login_attempts TO gfh_core_app;                  -- insert only: the audit log and sign-in log are append-only

-- Managed hosting: Supabase's own API roles must not reach these tables.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON ALL TABLES IN SCHEMA public FROM authenticated;
  END IF;
END
$$;
