-- GFH core database, migration 0002: sign-in attempt log and the default permission matrix.

-- One row per sign-in try. Lockout decisions use this table, not the users table, so a wrong email
-- is limited exactly like a wrong password and sign-in never reveals which account exists.
-- identifier_hash is an HMAC of the lower-cased email, never the email itself.
CREATE TABLE login_attempts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  scope text NOT NULL CHECK (scope IN ('staff', 'customer')),
  identifier_hash text NOT NULL,
  ip text NOT NULL,
  succeeded boolean NOT NULL,
  attempted_at timestamptz NOT NULL DEFAULT clock_timestamp()  -- real time, even inside a long transaction
);
CREATE INDEX login_attempts_identifier ON login_attempts (scope, identifier_hash, attempted_at);
CREATE INDEX login_attempts_ip ON login_attempts (ip, attempted_at);

-- Default permissions per organization role. An organization's own rows in organization_role_permissions
-- override these (changed by Super Admin only). Modules: dashboard, inventory, products, procurement,
-- sales (Sales & Customers), reports_admin (Reports & Admin, which includes the team screen and settings),
-- pos (using the POS app). A missing row means no access.
INSERT INTO role_permission_defaults (role, module, can_view, can_edit, can_delete) VALUES
  ('admin',   'dashboard',     true,  true,  true),
  ('admin',   'inventory',     true,  true,  true),
  ('admin',   'products',      true,  true,  true),
  ('admin',   'procurement',   true,  true,  true),
  ('admin',   'sales',         true,  true,  true),
  ('admin',   'reports_admin', true,  true,  true),
  ('admin',   'pos',           true,  true,  true),
  ('manager', 'dashboard',     true,  false, false),
  ('manager', 'inventory',     true,  true,  false),
  ('manager', 'products',      true,  true,  false),
  ('manager', 'procurement',   true,  true,  false),
  ('manager', 'sales',         true,  true,  false),
  ('manager', 'reports_admin', true,  false, false),
  ('manager', 'pos',           true,  true,  false),
  ('staff',   'dashboard',     true,  false, false),
  ('staff',   'inventory',     true,  true,  false),
  ('staff',   'products',      true,  false, false),
  ('staff',   'procurement',   true,  false, false),
  ('staff',   'sales',         true,  false, false),
  ('staff',   'reports_admin', false, false, false),
  ('staff',   'pos',           false, false, false),
  ('cashier', 'dashboard',     false, false, false),
  ('cashier', 'inventory',     false, false, false),
  ('cashier', 'products',      false, false, false),
  ('cashier', 'procurement',   false, false, false),
  ('cashier', 'sales',         false, false, false),
  ('cashier', 'reports_admin', false, false, false),
  ('cashier', 'pos',           true,  true,  false);
