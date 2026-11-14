-- GFH organization database, migration 0001: catalog, media, settings and audit log.
-- Runs in the schema chosen by the connection's search_path (normally public).
-- The Migrator creates schema_migrations itself before the first migration.
-- Requires PostgreSQL 13 or newer. Never put BEGIN or COMMIT in a migration.

CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;

CREATE FUNCTION forbid_modification() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'The % table is append-only; % is not allowed.', TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'integrity_constraint_violation';
  RETURN NULL;
END;
$$;

CREATE TABLE branches (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL UNIQUE,
  name text NOT NULL,
  address text,
  kind text NOT NULL DEFAULT 'store' CHECK (kind IN ('store', 'warehouse')),
  is_pickup_point boolean NOT NULL DEFAULT false,
  is_delivery_origin boolean NOT NULL DEFAULT false,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
-- Only one branch may be the delivery origin.
CREATE UNIQUE INDEX branches_one_delivery_origin ON branches (is_delivery_origin) WHERE is_delivery_origin;
CREATE TRIGGER branches_set_updated_at BEFORE UPDATE ON branches
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE media (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  path text NOT NULL,                       -- relative to storage/uploads/{org}/
  thumb_path text,                          -- relative thumbnail path, served by /media/{org}/{id}?thumb=1
  original_name text NOT NULL,
  mime text NOT NULL,
  bytes integer NOT NULL CHECK (bytes > 0),
  width integer,
  height integer,
  alt_text text,
  uploaded_by uuid,                         -- core user id
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE brands (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  manufacturer text NOT NULL DEFAULT 'GFH',
  logo_media_id uuid REFERENCES media (id),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX brands_name_lower ON brands (lower(name));
CREATE TRIGGER brands_set_updated_at BEFORE UPDATE ON brands
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE categories (                   -- two levels: a category and its sub-categories
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  parent_id uuid REFERENCES categories (id),
  name text NOT NULL,
  slug text NOT NULL UNIQUE,
  sort_order integer NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE INDEX categories_parent ON categories (parent_id);
CREATE TRIGGER categories_set_updated_at BEFORE UPDATE ON categories
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE products (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  slug text NOT NULL UNIQUE,
  description text,
  brand_id uuid REFERENCES brands (id),
  category_id uuid REFERENCES categories (id),
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'discontinued')),
  discontinued_on date,
  supplier_id uuid,                         -- foreign key added in 0002, once suppliers exist
  attributes jsonb NOT NULL DEFAULT '{}',   -- spec data such as wattage or material, validated per organization
  created_by uuid,                          -- core user id
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX products_brand ON products (brand_id);
CREATE INDEX products_category ON products (category_id);
CREATE INDEX products_status ON products (status);
CREATE INDEX products_attributes ON products USING gin (attributes jsonb_path_ops);
CREATE TRIGGER products_set_updated_at BEFORE UPDATE ON products
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- The sellable unit. A product without real variants gets one default variant.
-- avg_cost is the weighted-average cost. cost_updated_at stays null until a first cost is recorded;
-- sale lines snapshot a null unit_cost while the cost is unknown, which keeps them out of gross profit.
CREATE TABLE product_variants (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES products (id),
  sku text NOT NULL UNIQUE,
  barcode text UNIQUE,
  name text NOT NULL,                       -- 1 L, 500 mL, Silver
  unit text NOT NULL DEFAULT 'pc',          -- pc, L, mL, kg, g, pack
  unit_size numeric(12,3),
  allow_decimal_qty boolean NOT NULL DEFAULT false,
  qty_step numeric(12,3) NOT NULL DEFAULT 1 CHECK (qty_step > 0),   -- smallest increment when decimals are allowed
  min_qty numeric(12,3) NOT NULL DEFAULT 1 CHECK (min_qty > 0),     -- smallest quantity that can be bought
  track_batches boolean NOT NULL DEFAULT false,
  retail_price numeric(12,2) NOT NULL CHECK (retail_price >= 0),    -- VAT-inclusive
  trade_price numeric(12,2),
  avg_cost numeric(12,4) NOT NULL DEFAULT 0,
  cost_updated_at timestamptz,
  reorder_level numeric(12,3) NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'discontinued')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX product_variants_product ON product_variants (product_id);
CREATE INDEX product_variants_status ON product_variants (status);
CREATE TRIGGER product_variants_set_updated_at BEFORE UPDATE ON product_variants
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- A tier applies when its basis matches; the lowest matching price wins.
--   quantity:      applies when the quantity reaches min_qty (tier_key is empty)
--   customer_type: applies when tier_key equals the customer's type (for example Dealer)
--   customer_tier: applies when tier_key equals the customer's tier (for example Platinum)
CREATE TABLE price_tiers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  basis text NOT NULL CHECK (basis IN ('quantity', 'customer_type', 'customer_tier')),
  tier_key text,
  label text NOT NULL,
  min_qty numeric(12,3) NOT NULL DEFAULT 1 CHECK (min_qty > 0),
  price numeric(12,2) NOT NULL CHECK (price >= 0),  -- VAT-inclusive
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK (basis = 'quantity' OR tier_key IS NOT NULL)
);
CREATE INDEX price_tiers_variant ON price_tiers (variant_id);

CREATE TABLE price_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  effective_on date NOT NULL,
  retail_price numeric(12,2) NOT NULL,
  trade_price numeric(12,2),
  cost numeric(12,4),
  created_by uuid,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX price_history_variant ON price_history (variant_id, effective_on);

CREATE TABLE product_images (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES products (id),
  variant_id uuid REFERENCES product_variants (id),
  media_id uuid NOT NULL REFERENCES media (id),
  sort_order integer NOT NULL DEFAULT 0,
  is_primary boolean NOT NULL DEFAULT false
);
CREATE INDEX product_images_product ON product_images (product_id, sort_order);
-- One primary image per product.
CREATE UNIQUE INDEX product_images_one_primary ON product_images (product_id) WHERE is_primary;

CREATE TABLE settings (
  key text PRIMARY KEY,
  value jsonb NOT NULL,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid                           -- core user id
);

CREATE TABLE audit_log (                    -- append-only
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  occurred_at timestamptz NOT NULL DEFAULT now(),
  actor_user_id uuid,
  action text NOT NULL,
  entity text,
  entity_id text,
  detail jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX audit_log_occurred ON audit_log (occurred_at);
CREATE INDEX audit_log_entity ON audit_log (entity, entity_id);
CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION forbid_modification();
CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
  FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification();
