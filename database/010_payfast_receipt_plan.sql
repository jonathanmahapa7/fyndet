-- Each receipt retains the subscription plan applicable to that payment.
ALTER TABLE payfast_invoices ADD COLUMN plan VARCHAR(20) NULL AFTER order_id;
UPDATE payfast_invoices i JOIN payfast_orders o ON o.id=i.order_id SET i.plan=o.plan WHERE i.plan IS NULL;
