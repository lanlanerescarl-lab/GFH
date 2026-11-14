-- GFH organization database, migration 0003: organization customers, orders, payments, deliveries.

-- The organization's own record of a customer. core_customer_id links to the shared account in the core database.
CREATE TABLE customers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  core_customer_id uuid UNIQUE,             -- null for walk-in customers created by staff
  full_name text NOT NULL,
  email text,
  phone text,
  customer_type text,                       -- matched by price tiers with basis customer_type (for example Dealer)
  tier text,                                -- matched by price tiers with basis customer_tier (for example Platinum)
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX customers_email_lower ON customers (lower(email));
CREATE TRIGGER customers_set_updated_at BEFORE UPDATE ON customers
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Order progress as data. An order's path contains only the steps whose applies_when condition
-- matches its contents ('always', or a condition key supplied by an extension, such as has_made_to_order).
CREATE TABLE order_progress_steps (
  key text PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]*$'),
  label text NOT NULL,
  sort_order integer NOT NULL,
  is_terminal boolean NOT NULL DEFAULT false,
  maps_to text NOT NULL CHECK (maps_to IN ('open', 'in_progress', 'complete')),  -- group used by the contract views
  applies_when text NOT NULL DEFAULT 'always' CHECK (applies_when ~ '^[a-z][a-z0-9_]*$'),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER order_progress_steps_set_updated_at BEFORE UPDATE ON order_progress_steps
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Money convention for orders (checked by the Stage 3 tests):
--   total = subtotal - discount_total + delivery_fee + assembly_fee + installation_fee
--   subtotal and total are VAT-inclusive; vat_amount is the 12% VAT contained in total.
-- Order progress and payment status are separate columns and change independently.
CREATE TABLE orders (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_number text NOT NULL UNIQUE,
  customer_id uuid NOT NULL REFERENCES customers (id),
  channel text NOT NULL DEFAULT 'online' CHECK (channel IN ('online', 'staff')),
  progress_key text NOT NULL REFERENCES order_progress_steps (key),
  payment_status text NOT NULL DEFAULT 'unpaid'
    CHECK (payment_status IN ('unpaid', 'deposit_paid', 'paid', 'refunded')),
  fulfillment_method text NOT NULL CHECK (fulfillment_method IN ('pickup', 'delivery')),
  pickup_branch_id uuid REFERENCES branches (id),
  ship_from_branch_id uuid REFERENCES branches (id),   -- the designated delivery branch, stored on the order
  ship_to jsonb,                            -- delivery address snapshot
  subtotal numeric(12,2) NOT NULL,
  discount_total numeric(12,2) NOT NULL DEFAULT 0,
  delivery_fee numeric(12,2) NOT NULL DEFAULT 0,
  assembly_fee numeric(12,2) NOT NULL DEFAULT 0,
  installation_fee numeric(12,2) NOT NULL DEFAULT 0,
  vat_amount numeric(12,2) NOT NULL,
  total numeric(12,2) NOT NULL,
  deposit_due numeric(12,2) NOT NULL DEFAULT 0,
  promised_date date,
  cancelled_at timestamptz,
  cancellation_reason text,
  refund_review boolean NOT NULL DEFAULT false,         -- a cancelled order that holds payments needs a manual refund check
  placed_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,                          -- core user id when staff placed it
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK ((fulfillment_method = 'pickup' AND pickup_branch_id IS NOT NULL)
      OR (fulfillment_method = 'delivery' AND ship_from_branch_id IS NOT NULL)),
  CHECK (NOT refund_review OR cancelled_at IS NOT NULL)
);
CREATE INDEX orders_customer ON orders (customer_id);
CREATE INDEX orders_progress ON orders (progress_key);
CREATE INDEX orders_payment_status ON orders (payment_status);
CREATE INDEX orders_placed ON orders (placed_at);
CREATE INDEX orders_refund_review ON orders (id) WHERE refund_review;
CREATE TRIGGER orders_set_updated_at BEFORE UPDATE ON orders
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- line_total = qty * unit_price - discount (VAT-inclusive).
-- unit_cost is the weighted-average cost snapshotted at fulfillment; null until then, and null lines are left out of gross profit.
CREATE TABLE order_lines (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders (id),
  variant_id uuid NOT NULL REFERENCES product_variants (id),
  sku_snapshot text NOT NULL,
  name_snapshot text NOT NULL,
  qty numeric(12,3) NOT NULL CHECK (qty > 0),
  unit_price numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  discount numeric(12,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
  line_total numeric(12,2) NOT NULL,
  unit_cost numeric(12,4),
  is_made_to_order boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_lines_order ON order_lines (order_id);
CREATE INDEX order_lines_variant ON order_lines (variant_id);

CREATE TABLE order_status_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders (id),
  progress_key text NOT NULL REFERENCES order_progress_steps (key),
  changed_at timestamptz NOT NULL DEFAULT now(),
  changed_by uuid                           -- core user id
);
CREATE INDEX order_status_history_order ON order_status_history (order_id, changed_at);

-- One table for online orders and POS sales. pos_sale_id gets its foreign key in 0004.
CREATE TABLE payments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid REFERENCES orders (id),
  pos_sale_id uuid,
  method text NOT NULL CHECK (method IN ('cash', 'gcash', 'maya', 'card', 'bank_transfer', 'cod')),
  amount numeric(12,2) NOT NULL CHECK (amount > 0),
  reference text,
  status text NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded', 'voided')),
  paid_at timestamptz NOT NULL DEFAULT now(),
  recorded_by uuid,                         -- core user id
  CHECK (num_nonnulls(order_id, pos_sale_id) = 1)
);
CREATE INDEX payments_order ON payments (order_id);
CREATE INDEX payments_pos_sale ON payments (pos_sale_id);
CREATE INDEX payments_paid_at ON payments (paid_at);

CREATE TABLE deliveries (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders (id),
  method_key text,
  zone_key text,
  scheduled_date date,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'scheduled', 'dispatched', 'delivered', 'failed', 'cancelled')),
  note text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX deliveries_order ON deliveries (order_id);
CREATE INDEX deliveries_due ON deliveries (status, scheduled_date);
CREATE TRIGGER deliveries_set_updated_at BEFORE UPDATE ON deliveries
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Reservations now point at real orders.
ALTER TABLE stock_reservations
  ADD CONSTRAINT stock_reservations_order_fk FOREIGN KEY (order_id) REFERENCES orders (id);
