-- D42. Online PromptPay through Stripe (PAYMENT_MODE=gateway), confirmed by Stripe's webhook, not by the shop.
-- payments.method / requested_method gain STRIPE_PROMPTPAY. An online payment has no person who confirmed it,
-- so the PAID check accepts it without confirmed_by. Both CHECKs are replaced in a way that suits the server.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_methods') = 0,
    'DO 0', IF(VERSION() LIKE '%MariaDB%', 'ALTER TABLE payments DROP CONSTRAINT chk_payments_methods', 'ALTER TABLE payments DROP CHECK chk_payments_methods'));
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_methods_v2') = 0,
    'ALTER TABLE payments ADD CONSTRAINT chk_payments_methods_v2 CHECK (method IN (''PAY_AT_STORE'', ''PROMPTPAY'', ''STRIPE_PROMPTPAY'') AND requested_method IN (''PAY_AT_STORE'', ''PROMPTPAY'', ''STRIPE_PROMPTPAY''))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_paid') = 0,
    'DO 0', IF(VERSION() LIKE '%MariaDB%', 'ALTER TABLE payments DROP CONSTRAINT chk_payments_paid', 'ALTER TABLE payments DROP CHECK chk_payments_paid'));
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'chk_payments_paid_v2') = 0,
    'ALTER TABLE payments ADD CONSTRAINT chk_payments_paid_v2 CHECK (status = ''UNPAID'' OR (paid_at IS NOT NULL AND (confirmed_by IS NOT NULL OR method = ''STRIPE_PROMPTPAY'')))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- One row per PaymentIntent. active_order_id = order_id while it is the order's current intent, NULL after it
-- was cancelled or replaced: the unique key allows one active intent per order.
-- refund_due_at: money arrived that the shop must return by hand in the Stripe Dashboard (no refund API, D42).
CREATE TABLE IF NOT EXISTS payment_intents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    active_order_id BIGINT UNSIGNED NULL,
    provider VARCHAR(20) NOT NULL,
    intent_id VARCHAR(100) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(40) NOT NULL,
    attempt SMALLINT UNSIGNED NOT NULL,
    qr_data VARCHAR(1000) NULL,
    qr_image_url VARCHAR(500) NULL,
    hosted_url VARCHAR(500) NULL,
    qr_expires_at DATETIME NULL,
    succeeded_at DATETIME NULL,
    problem VARCHAR(300) NULL,
    refund_due_at DATETIME NULL,
    refund_reason VARCHAR(300) NULL,
    refunded_at DATETIME NULL,
    refunded_by BIGINT UNSIGNED NULL,
    refund_note VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_intents_intent (provider, intent_id),
    UNIQUE KEY uq_payment_intents_active (active_order_id),
    UNIQUE KEY uq_payment_intents_attempt (order_id, attempt),
    KEY idx_payment_intents_refund (refund_due_at, refunded_at),
    CONSTRAINT chk_payment_intents_active CHECK (active_order_id IS NULL OR active_order_id = order_id),
    CONSTRAINT fk_payment_intents_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_payment_intents_refunded_by FOREIGN KEY (refunded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every webhook event once: a repeated delivery finds its row and changes nothing.
CREATE TABLE IF NOT EXISTS gateway_events (
    event_id VARCHAR(100) NOT NULL PRIMARY KEY,
    provider VARCHAR(20) NOT NULL,
    type VARCHAR(80) NOT NULL,
    intent_id VARCHAR(100) NULL,
    order_id BIGINT UNSIGNED NULL,
    result VARCHAR(30) NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    KEY idx_gateway_events_result (result, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
