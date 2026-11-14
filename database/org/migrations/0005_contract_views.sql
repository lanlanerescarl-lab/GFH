-- GFH organization database, migration 0005: reporting helpers and the read-only org_contract views.
-- The Super Admin app reads only org_contract, through a role that has SELECT on nothing else.
-- Dates are calendar days in Asia/Manila.
--
-- Definitions used by every report (ReportService in Stage 3 uses the same two helper views):
--   net sales      = total - vat_amount of every completed or needs_review POS sale and every
--                    online or staff order that is not cancelled (orders count on the day they are placed)
--   cost           = quantity * the unit_cost snapshotted on each line, for lines that have a cost
--   gross profit   = net sales of the lines that have a cost, minus their cost (never revenue)
--   cost completeness = share of line net sales that comes from lines with a cost

-- ---------------------------------------------------------------- internal helpers (not part of the contract)

CREATE VIEW sales_facts AS
  SELECT 'pos'::text AS channel,
         s.id AS sale_id,
         (s.sold_at AT TIME ZONE 'Asia/Manila')::date AS sale_date,
         s.branch_id,
         s.total,
         s.vat_amount,
         s.total - s.vat_amount AS net_revenue
  FROM pos_sales s
  WHERE s.status IN ('completed', 'needs_review')
  UNION ALL
  SELECT o.channel,
         o.id,
         (o.placed_at AT TIME ZONE 'Asia/Manila')::date,
         COALESCE(o.pickup_branch_id, o.ship_from_branch_id),
         o.total,
         o.vat_amount,
         o.total - o.vat_amount
  FROM orders o
  WHERE o.cancelled_at IS NULL;

-- Net sales of each line: the sale's VAT-free amount shared out by each line's share of the subtotal.
-- Fees on online orders are left out of the line share, so they never inflate gross profit.
CREATE VIEW sales_line_facts AS
  SELECT 'pos'::text AS channel,
         s.id AS sale_id,
         l.id AS line_id,
         (s.sold_at AT TIME ZONE 'Asia/Manila')::date AS sale_date,
         l.variant_id,
         l.qty,
         CASE WHEN s.subtotal = 0 THEN 0::numeric
              ELSE (s.total - s.vat_amount) * l.line_total / s.subtotal
         END AS line_net,
         l.unit_cost,
         (l.unit_cost IS NOT NULL) AS has_cost,
         l.qty * COALESCE(l.unit_cost, 0) AS line_cost
  FROM pos_sale_lines l
  JOIN pos_sales s ON s.id = l.pos_sale_id
  WHERE s.status IN ('completed', 'needs_review')
  UNION ALL
  SELECT o.channel,
         o.id,
         l.id,
         (o.placed_at AT TIME ZONE 'Asia/Manila')::date,
         l.variant_id,
         l.qty,
         CASE WHEN o.subtotal = 0 OR o.total = 0 THEN 0::numeric
              ELSE (o.total - o.vat_amount) * (o.subtotal - o.discount_total) / o.total
                   * l.line_total / o.subtotal
         END,
         l.unit_cost,
         (l.unit_cost IS NOT NULL),
         l.qty * COALESCE(l.unit_cost, 0)
  FROM order_lines l
  JOIN orders o ON o.id = l.order_id
  WHERE o.cancelled_at IS NULL;

CREATE VIEW variant_on_hand AS
  SELECT v.id AS variant_id,
         COALESCE(SUM(m.quantity_delta), 0)::numeric(14,3) AS on_hand
  FROM product_variants v
  LEFT JOIN stock_movements m ON m.variant_id = v.id
  GROUP BY v.id;

-- ---------------------------------------------------------------- the contract

CREATE SCHEMA org_contract;

CREATE VIEW org_contract.contract_info AS
  SELECT 1 AS contract_version,
         now() AS as_of,
         COALESCE((SELECT MAX(version) FROM schema_migrations WHERE scope = 'org'), 0) AS org_migration_version;

CREATE VIEW org_contract.daily_sales AS
  WITH h AS (
    SELECT sale_date, SUM(net_revenue) AS net_revenue, COUNT(*) AS orders
    FROM sales_facts
    GROUP BY sale_date
  ), l AS (
    SELECT sale_date,
           SUM(qty) AS units,
           SUM(line_net) AS all_net,
           SUM(line_net) FILTER (WHERE has_cost) AS costed_net,
           SUM(line_cost) FILTER (WHERE has_cost) AS cost
    FROM sales_line_facts
    GROUP BY sale_date
  )
  SELECT h.sale_date AS "date",
         ROUND(h.net_revenue, 2) AS net_revenue,
         ROUND(COALESCE(l.cost, 0), 2) AS cost,
         ROUND(COALESCE(l.costed_net, 0) - COALESCE(l.cost, 0), 2) AS gross_profit,
         COALESCE(l.units, 0) AS units,
         h.orders AS orders,
         CASE WHEN COALESCE(l.all_net, 0) = 0 THEN NULL
              ELSE ROUND(100 * COALESCE(l.costed_net, 0) / l.all_net, 2)
         END AS cost_complete_pct
  FROM h
  LEFT JOIN l ON l.sale_date = h.sale_date;

CREATE VIEW org_contract.kpis AS
  SELECT 'open_purchase_orders'::text AS kpi_key,
         'Open purchase orders'::text AS label,
         COUNT(*)::numeric AS value,
         now() AS as_of
  FROM purchase_orders
  WHERE status IN ('draft', 'sent', 'partially_received')
  UNION ALL
  SELECT 'overdue_purchase_orders', 'Overdue purchase orders', COUNT(*)::numeric, now()
  FROM purchase_orders
  WHERE status IN ('sent', 'partially_received')
    AND expected_on < (now() AT TIME ZONE 'Asia/Manila')::date
  UNION ALL
  SELECT 'low_stock_variants', 'Low-stock variants', COUNT(*)::numeric, now()
  FROM product_variants v
  JOIN variant_on_hand h ON h.variant_id = v.id
  WHERE v.status = 'active' AND v.reorder_level > 0 AND h.on_hand > 0 AND h.on_hand <= v.reorder_level
  UNION ALL
  SELECT 'out_of_stock_variants', 'Out-of-stock variants', COUNT(*)::numeric, now()
  FROM product_variants v
  JOIN variant_on_hand h ON h.variant_id = v.id
  WHERE v.status = 'active' AND h.on_hand <= 0
  UNION ALL
  SELECT 'active_orders', 'Active orders', COUNT(*)::numeric, now()
  FROM orders o
  JOIN order_progress_steps s ON s.key = o.progress_key
  WHERE o.cancelled_at IS NULL AND s.maps_to <> 'complete';

-- One row per progress step, including steps with no orders, so the Super Admin app
-- can show each organization's own step names next to the shared open / in progress / complete groups.
CREATE VIEW org_contract.order_stage_counts AS
  SELECT s.key AS stage_key,
         s.label AS stage_label,
         s.maps_to AS mapped_group,
         s.sort_order,
         COUNT(o.id) AS order_count
  FROM order_progress_steps s
  LEFT JOIN orders o ON o.progress_key = s.key AND o.cancelled_at IS NULL
  GROUP BY s.key, s.label, s.maps_to, s.sort_order;

-- entity_id is the plain record id; the reading app adds the organization slug to make it unique across organizations.
CREATE VIEW org_contract.alerts AS
  SELECT 'low_stock'::text AS alert_type,
         'warning'::text AS severity,
         'product_variant'::text AS entity,
         v.id::text AS entity_id,
         ('Low stock: ' || v.sku || ' has ' || trim_scale(h.on_hand)::text
           || ' left (reorder at ' || trim_scale(v.reorder_level)::text || ')')::text AS message,
         now() AS raised_at
  FROM product_variants v
  JOIN variant_on_hand h ON h.variant_id = v.id
  WHERE v.status = 'active' AND v.reorder_level > 0 AND h.on_hand > 0 AND h.on_hand <= v.reorder_level
  UNION ALL
  SELECT 'out_of_stock', 'critical', 'product_variant', v.id::text,
         'Out of stock: ' || v.sku, now()
  FROM product_variants v
  JOIN variant_on_hand h ON h.variant_id = v.id
  WHERE v.status = 'active' AND h.on_hand <= 0
  UNION ALL
  SELECT 'expiring_batch',
         CASE WHEN b.expires_on < (now() AT TIME ZONE 'Asia/Manila')::date THEN 'critical' ELSE 'warning' END,
         'stock_batch', b.id::text,
         'Batch ' || b.batch_code || ' of ' || v.sku
           || CASE WHEN b.expires_on < (now() AT TIME ZONE 'Asia/Manila')::date THEN ' expired on ' ELSE ' expires on ' END
           || to_char(b.expires_on, 'DD Mon YYYY'),
         now()
  FROM stock_batches b
  JOIN product_variants v ON v.id = b.variant_id
  JOIN (SELECT batch_id, SUM(quantity_delta) AS on_hand
        FROM stock_movements
        WHERE batch_id IS NOT NULL
        GROUP BY batch_id) s ON s.batch_id = b.id
  WHERE s.on_hand > 0
    AND b.expires_on IS NOT NULL
    AND b.expires_on <= (now() AT TIME ZONE 'Asia/Manila')::date
        + COALESCE((SELECT (value #>> '{}')::int FROM settings WHERE key = 'near_expiry_days'), 7)
  UNION ALL
  SELECT 'overdue_delivery', 'warning', 'order', o.id::text,
         'Delivery for order ' || o.order_number || ' was due on ' || to_char(d.scheduled_date, 'DD Mon YYYY'),
         now()
  FROM deliveries d
  JOIN orders o ON o.id = d.order_id
  WHERE d.status NOT IN ('delivered', 'cancelled')
    AND o.cancelled_at IS NULL
    AND d.scheduled_date < (now() AT TIME ZONE 'Asia/Manila')::date
  UNION ALL
  SELECT 'pos_needs_review', 'warning', 'pos_sale', s.id::text,
         'POS sale ' || s.receipt_number || ' needs review: ' || COALESCE(s.review_reason, 'no reason recorded'),
         s.synced_at
  FROM pos_sales s
  WHERE s.status = 'needs_review';

CREATE VIEW org_contract.audit_events AS
  SELECT id, occurred_at, actor_user_id, action, entity, entity_id, detail
  FROM audit_log;

-- Starts empty. Migrator::rebuildMeasuresView() replaces it with a union of the enabled
-- extensions' ext_{key}_measures views, keeping exactly these column names and types.
CREATE VIEW org_contract.measures AS
  SELECT NULL::text AS measure_key,
         NULL::text AS label,
         NULL::numeric AS value,
         NULL::text AS unit,
         NULL::timestamptz AS as_of
  WHERE false;
