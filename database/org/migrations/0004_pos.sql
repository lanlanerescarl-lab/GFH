-- GFH organization database, migration 0004: terminals, shifts, POS sales and discount rules.

CREATE TABLE terminals (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  branch_id uuid NOT NULL REFERENCES branches (id),
  code text NOT NULL UNIQUE,                -- prefix of receipt numbers, for example T3
  name text NOT NULL,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  last_seen_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX terminals_branch ON terminals (branch_id);
CREATE TRIGGER terminals_set_updated_at BEFORE UPDATE ON terminals
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE pos_shifts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  terminal_id uuid NOT NULL REFERENCES terminals (id),
  cashier_user_id uuid NOT NULL,            -- core user id
  opened_at timestamptz NOT NULL DEFAULT now(),
  opening_cash numeric(12,2) NOT NULL CHECK (opening_cash >= 0),
  closed_at timestamptz,
  counted_cash numeric(12,2),
  expected_cash numeric(12,2),
  variance numeric(12,2),
  status text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
  CHECK ((status = 'open' AND closed_at IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL))
);
-- A terminal can have only one open shift.
CREATE UNIQUE INDEX pos_shifts_one_open_per_terminal ON pos_shifts (terminal_id) WHERE status = 'open';
CREATE INDEX pos_shifts_cashier ON pos_shifts (cashier_user_id);

-- The id comes from the terminal (generated offline), which makes uploading the same sale twice harmless.
-- Money convention (checked by the Stage 3 tests):
--   total = subtotal - discount_total, where discount_total includes any VAT exemption for senior/PWD sales.
--   subtotal is the sum of line_total, VAT-inclusive; vat_amount is the VAT contained in total.
CREATE TABLE pos_sales (
  id uuid PRIMARY KEY,
  receipt_number text NOT NULL UNIQUE,      -- T3-000123
  shift_id uuid NOT NULL REFERENCES pos_shifts (id),
  terminal_id uuid NOT NULL REFERENCES terminals (id),
  branch_id uuid NOT NULL REFERENCES branches (id),
  cashier_user_id uuid NOT NULL,            -- core user id
  customer_id uuid REFERENCES customers (id),
  sold_at timestamptz NOT NULL,             -- time on the terminal when the sale was made
  synced_at timestamptz NOT NULL DEFAULT now(),
  subtotal numeric(12,2) NOT NULL,
  discount_total numeric(12,2) NOT NULL DEFAULT 0,
  vat_amount numeric(12,2) NOT NULL,
  total numeric(12,2) NOT NULL,
  status text NOT NULL DEFAULT 'completed' CHECK (status IN ('completed', 'voided', 'needs_review')),
  review_reason text                        -- for example: sold more than was in stock
);
CREATE INDEX pos_sales_shift ON pos_sales (shift_id);
CREATE INDEX pos_sales_branch_time ON pos_sales (branch_id, sold_at);
CREATE INDEX pos_sales_review ON pos_sales (id) WHERE status = 'needs_review';

-- unit_cost is the weighted-average cost snapshotted at the sale; null when the variant has no known cost yet.
CREATE TABLE pos_sale_lines (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  pos_sale_id uuid NOT NULL REFERENCES pos_sales (id),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  batch_id uuid REFERENCES stock_batches (id),
  sku_snapshot text NOT NULL,
  name_snapshot text NOT NULL,
  qty numeric(12,3) NOT NULL CHECK (qty > 0),
  unit_price numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  discount numeric(12,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
  unit_cost numeric(12,4),
  line_total numeric(12,2) NOT NULL
);
CREATE INDEX pos_sale_lines_sale ON pos_sale_lines (pos_sale_id);
CREATE INDEX pos_sale_lines_variant ON pos_sale_lines (variant_id);

CREATE TABLE discount_rules (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]*$'),   -- senior, pwd, promo, ...
  label text NOT NULL,
  percent numeric(5,2) NOT NULL CHECK (percent >= 0 AND percent <= 100),
  vat_exempt boolean NOT NULL DEFAULT false,
  active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER discount_rules_set_updated_at BEFORE UPDATE ON discount_rules
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE pos_sale_discounts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  pos_sale_id uuid NOT NULL REFERENCES pos_sales (id),
  rule_id uuid NOT NULL REFERENCES discount_rules (id),
  amount numeric(12,2) NOT NULL CHECK (amount >= 0),
  id_ref_last4 text CHECK (id_ref_last4 IS NULL OR char_length(id_ref_last4) <= 4)  -- last digits of the senior/PWD ID only
);
CREATE INDEX pos_sale_discounts_sale ON pos_sale_discounts (pos_sale_id);

-- Payments can now point at POS sales.
ALTER TABLE payments
  ADD CONSTRAINT payments_pos_sale_fk FOREIGN KEY (pos_sale_id) REFERENCES pos_sales (id);
