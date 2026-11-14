-- Creates (or updates the passwords of) the four database roles GFH uses.
-- Run once per PostgreSQL server, as a superuser, from the project root:
--
--   psql -h localhost -U postgres \
--     -v migrator_password='...' -v app_password='...' \
--     -v reader_password='...'   -v core_app_password='...' \
--     -f database/roles/01_create_roles.sql
--
-- On managed hosting (one Supabase project per organization plus one core project)
-- run it once in each project's database. Use long random passwords and put them in .env or the
-- encrypted organization connection strings, never in this repository.
--
--   gfh_migrator  creates and alters tables; used only by bin/ scripts; owns every object it creates
--   gfh_app       used by web requests to an organization database (limited rights)
--   gfh_reader    read-only access to the org_contract views; used by the Super Admin app
--   gfh_core_app  used by web requests to the core database (limited rights)

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', r.name, r.pw)
FROM (VALUES
  ('gfh_migrator', :'migrator_password'),
  ('gfh_app', :'app_password'),
  ('gfh_reader', :'reader_password'),
  ('gfh_core_app', :'core_app_password')
) AS r(name, pw)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r.name)
\gexec

SELECT format('ALTER ROLE %I PASSWORD %L', r.name, r.pw)
FROM (VALUES
  ('gfh_migrator', :'migrator_password'),
  ('gfh_app', :'app_password'),
  ('gfh_reader', :'reader_password'),
  ('gfh_core_app', :'core_app_password')
) AS r(name, pw)
\gexec
