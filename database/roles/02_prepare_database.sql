-- Lets the migrator create tables in one database. Run as a superuser while connected to that
-- database (the core database and each organization database), BEFORE the first migration:
--
--   psql -h localhost -U postgres -d gfh_core     -f database/roles/02_prepare_database.sql
--   psql -h localhost -U postgres -d gfh_org_demo -f database/roles/02_prepare_database.sql
--
-- Assumes the default schema (public). Safe to run repeatedly.

\set ON_ERROR_STOP on

DO $$
BEGIN
  EXECUTE format('GRANT CREATE, CONNECT ON DATABASE %I TO gfh_migrator', current_database());
END
$$;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO gfh_migrator;
