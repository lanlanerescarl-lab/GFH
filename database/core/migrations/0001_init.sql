-- GFH core database, migration 0001: accounts, organization registry, permissions, customers, audit.
-- Requires PostgreSQL 13 or newer (gen_random_uuid() is built in).
-- The Migrator wraps this file in a transaction: never put BEGIN or COMMIT in a migration.

-- Keeps updated_at honest without every query having to remember it.
CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;

-- Used by append-only tables: even a role that holds UPDATE or DELETE cannot change history.
CREATE FUNCTION forbid_modification() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'The % table is append-only; % is not allowed.', TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'integrity_constraint_violation';
  RETURN NULL;
END;
$$;

CREATE TABLE users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name text NOT NULL,
  email text NOT NULL,
  password_hash text,                       -- null while an invitation is pending
  org_role text CHECK (org_role IN ('super_admin', 'group_viewer')),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('active', 'inactive', 'pending')),
  failed_logins int NOT NULL DEFAULT 0,
  locked_until timestamptz,
  last_login_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_email_lower ON users (lower(email));
CREATE TRIGGER users_set_updated_at BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE organizations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  slug text NOT NULL UNIQUE,                -- used in URLs: /{slug}/shop
  name text NOT NULL,
  short_name text NOT NULL,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'archived')),
  connection_encrypted bytea NOT NULL,      -- sodium secretbox; the key lives in .env (APP_KEY)
  db_schema text NOT NULL DEFAULT 'public',
  branding jsonb NOT NULL DEFAULT '{}',     -- colors, fonts, logo media path
  settings jsonb NOT NULL DEFAULT '{}',
  migration_version int NOT NULL DEFAULT 0,
  template_version text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER organizations_set_updated_at BEFORE UPDATE ON organizations
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE organization_features (
  organization_id uuid NOT NULL REFERENCES organizations (id),
  feature_key text NOT NULL,                -- batches, cold_chain, made_to_order, ...
  enabled boolean NOT NULL DEFAULT true,
  PRIMARY KEY (organization_id, feature_key)
);

CREATE TABLE memberships (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES users (id),
  organization_id uuid NOT NULL REFERENCES organizations (id),
  role text NOT NULL CHECK (role IN ('admin', 'manager', 'staff', 'cashier')),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'pending')),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (user_id, organization_id)
);
CREATE INDEX memberships_organization ON memberships (organization_id, role);

CREATE TABLE role_permission_defaults (
  role text NOT NULL CHECK (role IN ('admin', 'manager', 'staff', 'cashier')),
  module text NOT NULL,
  can_view boolean NOT NULL DEFAULT false,
  can_edit boolean NOT NULL DEFAULT false,
  can_delete boolean NOT NULL DEFAULT false,
  PRIMARY KEY (role, module)
);

CREATE TABLE organization_role_permissions (  -- per-organization overrides of the defaults
  organization_id uuid NOT NULL REFERENCES organizations (id),
  role text NOT NULL CHECK (role IN ('admin', 'manager', 'staff', 'cashier')),
  module text NOT NULL,
  can_view boolean NOT NULL,
  can_edit boolean NOT NULL,
  can_delete boolean NOT NULL,
  PRIMARY KEY (organization_id, role, module)
);

CREATE TABLE customers (                    -- one account across all three shops
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name text NOT NULL,
  email text NOT NULL,
  phone text,
  password_hash text NOT NULL,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX customers_email_lower ON customers (lower(email));
CREATE TRIGGER customers_set_updated_at BEFORE UPDATE ON customers
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE customer_addresses (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id uuid NOT NULL REFERENCES customers (id),
  label text,
  line1 text NOT NULL,
  barangay text,
  city text NOT NULL,
  province text NOT NULL,
  postal_code text,
  is_default boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX customer_addresses_customer ON customer_addresses (customer_id);
CREATE UNIQUE INDEX customer_addresses_one_default ON customer_addresses (customer_id) WHERE is_default;
CREATE TRIGGER customer_addresses_set_updated_at BEFORE UPDATE ON customer_addresses
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE password_reset_tokens (        -- shown to an admin, because there is no email service
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subject_type text NOT NULL CHECK (subject_type IN ('user', 'customer')),
  subject_id uuid NOT NULL,
  token_hash text NOT NULL,                 -- a hash is stored, never the token itself
  expires_at timestamptz NOT NULL,
  used_at timestamptz,
  issued_by uuid,                           -- the admin who generated it
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX password_reset_tokens_hash ON password_reset_tokens (token_hash);
CREATE INDEX password_reset_tokens_subject ON password_reset_tokens (subject_type, subject_id);

CREATE TABLE audit_log (                    -- append-only
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  occurred_at timestamptz NOT NULL DEFAULT now(),
  organization_id uuid,
  actor_user_id uuid,
  action text NOT NULL,
  entity text,
  entity_id text,
  detail jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX audit_log_occurred ON audit_log (occurred_at);
CREATE INDEX audit_log_organization ON audit_log (organization_id, occurred_at);
CREATE INDEX audit_log_entity ON audit_log (entity, entity_id);
CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION forbid_modification();
CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
  FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification();
