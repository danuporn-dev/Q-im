-- D38. PromptPay confirmed by the shop, and a token inside the pickup QR.
-- payments.requested_method: what the customer chose at checkout; payments.method: what the shop says it received.
-- Replacing the method CHECK differs between MariaDB and MySQL, so it is prepared for the server in use.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'requested_method') = 0,
    'ALTER TABLE payments ADD COLUMN requested_method VARCHAR(20) NOT NULL DEFAULT ''PAY_AT_STORE'' AFTER method', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_method') = 0,
    'DO 0', IF(VERSION() LIKE '%MariaDB%', 'ALTER TABLE payments DROP CONSTRAINT chk_payments_method', 'ALTER TABLE payments DROP CHECK chk_payments_method'));
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_methods') = 0,
    'ALTER TABLE payments ADD CONSTRAINT chk_payments_methods CHECK (method IN (''PAY_AT_STORE'', ''PROMPTPAY'') AND requested_method IN (''PAY_AT_STORE'', ''PROMPTPAY''))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- Mobile numbers only; a national ID number must never be stored.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_settings' AND COLUMN_NAME = 'promptpay_phone') = 0,
    'ALTER TABLE shop_settings ADD COLUMN promptpay_phone CHAR(10) NULL', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_settings' AND COLUMN_NAME = 'promptpay_enabled') = 0,
    'ALTER TABLE shop_settings ADD COLUMN promptpay_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- One token per order, created when it becomes READY. Only the hash is stored; the token itself is
-- derived from the nonce and a server key that is not in the database.
CREATE TABLE IF NOT EXISTS pickup_tokens (
    order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_tokens_hash (token_hash),
    CONSTRAINT fk_pickup_tokens_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
