-- GFH organization database, migration 0007: remember when a purchase order was sent to the supplier.
-- Until now the Send action changed the status only, so the order page could never show a sent date.
ALTER TABLE purchase_orders ADD COLUMN sent_at timestamptz;

-- Orders already sent keep the real date: it is in the audit log (po.sent). The trigger is paused so that the
-- backfill does not move updated_at on those orders.
ALTER TABLE purchase_orders DISABLE TRIGGER purchase_orders_set_updated_at;
UPDATE purchase_orders p
   SET sent_at = a.sent_at
  FROM (SELECT entity_id, MAX(occurred_at) AS sent_at
          FROM audit_log
         WHERE action = 'po.sent' AND entity = 'purchase_order'
         GROUP BY entity_id) a
 WHERE a.entity_id = p.id::text AND p.sent_at IS NULL;
ALTER TABLE purchase_orders ENABLE TRIGGER purchase_orders_set_updated_at;
