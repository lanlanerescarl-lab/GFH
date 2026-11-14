-- GFH organization database, migration 0002: suppliers, procurement and the stock ledger.

CREATE TABLE suppliers (                    -- may include contract manufacturers
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  contact_name text,
  contact_email text,
  contact_phone text,
  address text,
  payment_terms text,
  lead_time_days integer CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  created_by uuid,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX suppliers_name_lower ON suppliers (lower(name));
CREATE TRIGGER suppliers_set_updated_at BEFORE UPDATE ON suppliers
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

ALTER TABLE products
  ADD CONSTRAINT products_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers (id);
CREATE INDEX products_supplier ON products (supplier_id);

CREATE TABLE purchase_orders (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  po_number text NOT NULL UNIQUE,
  supplier_id uuid NOT NULL REFERENCES suppliers (id),
  branch_id uuid NOT NULL REFERENCES branches (id),   -- the branch that receives the goods
  status text NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft', 'sent', 'partially_received', 'received', 'cancelled')),
  expected_on date,
  ordered_by uuid,                          -- core user id
  notes text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX purchase_orders_supplier ON purchase_orders (supplier_id);
CREATE INDEX purchase_orders_branch ON purchase_orders (branch_id);
CREATE INDEX purchase_orders_status ON purchase_orders (status, expected_on);
CREATE TRIGGER purchase_orders_set_updated_at BEFORE UPDATE ON purchase_orders
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Receiving a line writes a stock movement and updates the variant's avg_cost.
CREATE TABLE purchase_order_lines (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  purchase_order_id uuid NOT NULL REFERENCES purchase_orders (id),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  qty_ordered numeric(12,3) NOT NULL CHECK (qty_ordered > 0),
  qty_received numeric(12,3) NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
  qty_rejected numeric(12,3) NOT NULL DEFAULT 0 CHECK (qty_rejected >= 0),
  unit_cost numeric(12,4) NOT NULL CHECK (unit_cost >= 0),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX purchase_order_lines_order ON purchase_order_lines (purchase_order_id);
CREATE INDEX purchase_order_lines_variant ON purchase_order_lines (variant_id);

-- Used only where the variant has track_batches = true.
CREATE TABLE stock_batches (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  branch_id uuid NOT NULL REFERENCES branches (id),
  batch_code text NOT NULL,
  bin text,                                 -- storage bin or shelf location
  received_on date NOT NULL DEFAULT ((now() AT TIME ZONE 'Asia/Manila')::date),
  expires_on date,
  qty_received numeric(12,3) NOT NULL CHECK (qty_received > 0),
  unit_cost numeric(12,4),
  purchase_order_line_id uuid REFERENCES purchase_order_lines (id),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (variant_id, branch_id, batch_code)
);
CREATE INDEX stock_batches_expiry ON stock_batches (expires_on) WHERE expires_on IS NOT NULL;

-- The ledger. Stock on hand is never edited; it is the sum of these rows. Append-only.
-- Conversions (convert_out, convert_in) and transfers are not purchases: only 'receipt' rows are.
CREATE TABLE stock_movements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  occurred_at timestamptz NOT NULL DEFAULT now(),
  branch_id uuid NOT NULL REFERENCES branches (id),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  batch_id uuid REFERENCES stock_batches (id),
  movement_type text NOT NULL CHECK (movement_type IN
    ('receipt', 'sale_pos', 'sale_online', 'adjustment', 'write_off',
     'return_in', 'return_out', 'transfer_out', 'transfer_in', 'convert_out', 'convert_in')),
  quantity_delta numeric(12,3) NOT NULL CHECK (quantity_delta <> 0),   -- + in, - out
  unit_cost numeric(12,4),                  -- cost snapshot at the time of the movement
  reference_type text,                      -- purchase_order, order, pos_sale, conversion ...
  reference_id uuid,
  reason text,                              -- required for adjustments and write-offs (enforced by the service)
  created_by uuid,                          -- core user id
  client_ref uuid UNIQUE                    -- makes offline retries safe
);
CREATE INDEX stock_movements_lookup ON stock_movements (branch_id, variant_id);
CREATE INDEX stock_movements_batch ON stock_movements (batch_id) WHERE batch_id IS NOT NULL;
CREATE INDEX stock_movements_reference ON stock_movements (reference_type, reference_id);
CREATE INDEX stock_movements_time ON stock_movements (occurred_at);
CREATE TRIGGER stock_movements_no_change BEFORE UPDATE OR DELETE ON stock_movements
  FOR EACH ROW EXECUTE FUNCTION forbid_modification();
CREATE TRIGGER stock_movements_no_truncate BEFORE TRUNCATE ON stock_movements
  FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification();

-- Holds stock for online orders. The foreign key to orders is added in 0003.
CREATE TABLE stock_reservations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL,
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  branch_id uuid NOT NULL REFERENCES branches (id),
  batch_id uuid REFERENCES stock_batches (id),
  quantity numeric(12,3) NOT NULL CHECK (quantity > 0),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released', 'consumed')),
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stock_reservations_order ON stock_reservations (order_id);
CREATE INDEX stock_reservations_active ON stock_reservations (branch_id, variant_id) WHERE status = 'active';
CREATE INDEX stock_reservations_expiry ON stock_reservations (status, expires_at);
CREATE TRIGGER stock_reservations_set_updated_at BEFORE UPDATE ON stock_reservations
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE VIEW stock_levels AS
  SELECT branch_id, variant_id, batch_id, SUM(quantity_delta) AS on_hand
  FROM stock_movements
  GROUP BY branch_id, variant_id, batch_id;

CREATE VIEW available_stock AS
  SELECT l.branch_id, l.variant_id,
         SUM(l.on_hand) - COALESCE(r.reserved, 0) AS available
  FROM stock_levels l
  LEFT JOIN (SELECT branch_id, variant_id, SUM(quantity) AS reserved
             FROM stock_reservations
             WHERE status = 'active' AND expires_at > now()
             GROUP BY branch_id, variant_id) r
    ON r.branch_id = l.branch_id AND r.variant_id = l.variant_id
  GROUP BY l.branch_id, l.variant_id, r.reserved;
