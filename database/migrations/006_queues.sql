-- business_date is the Asia/Bangkok calendar date, computed by PHP; assigned_at is UTC.

-- Lock the counter row with SELECT ... FOR UPDATE when assigning a number.
CREATE TABLE IF NOT EXISTS queue_counters (
    shop_id BIGINT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (shop_id, business_date),
    CONSTRAINT fk_queue_counters_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No status column: queue state is derived from orders.status.
-- The composite foreign key guarantees the queue belongs to the shop of its order.
CREATE TABLE IF NOT EXISTS queues (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    queue_number INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_queues_order (order_id),
    UNIQUE KEY uq_queues_number (shop_id, business_date, queue_number),
    KEY idx_queues_order_shop (order_id, shop_id),
    CONSTRAINT chk_queues_number CHECK (queue_number >= 1),
    CONSTRAINT fk_queues_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_queues_order FOREIGN KEY (order_id, shop_id) REFERENCES orders (id, shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
