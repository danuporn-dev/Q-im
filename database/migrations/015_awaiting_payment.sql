-- D44. Online PromptPay is paid at checkout: the order waits as AWAITING_PAYMENT, unseen by the shop, until Stripe
-- confirms the money; then it becomes PENDING. pay_by is the deadline (PROMPTPAY_QR_MINUTES after checkout); an order
-- still unpaid then is cancelled. placed_at is when the order reached the shop (PENDING): shops only ever see orders
-- that have one, so an online order that was never paid stays out of their board, history, bell and reports.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'chk_orders_status') = 0,
    'DO 0', IF(VERSION() LIKE '%MariaDB%', 'ALTER TABLE orders DROP CONSTRAINT chk_orders_status', 'ALTER TABLE orders DROP CHECK chk_orders_status'));
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'chk_orders_status_v2') = 0,
    'ALTER TABLE orders ADD CONSTRAINT chk_orders_status_v2 CHECK (status IN (''AWAITING_PAYMENT'',''PENDING'',''ACCEPTED'',''PREPARING'',''READY'',''COMPLETED'',''REJECTED'',''CANCELLED''))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'placed_at') = 0,
    'ALTER TABLE orders ADD COLUMN placed_at DATETIME NULL AFTER created_at', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pay_by') = 0,
    'ALTER TABLE orders ADD COLUMN pay_by DATETIME NULL AFTER placed_at', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- Every order made before D44 reached the shop when it was created.
UPDATE orders SET placed_at = created_at WHERE placed_at IS NULL AND status <> 'AWAITING_PAYMENT';

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_pay_by') = 0,
    'ALTER TABLE orders ADD KEY idx_orders_pay_by (status, pay_by)', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
