-- VARCHAR + CHECK instead of ENUM: a non-strict sql_mode silently stores an empty string in an ENUM.

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_order_no VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    total DECIMAL(10,2) NOT NULL,
    idempotency_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    ready_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_orders_public_no (public_order_no),
    UNIQUE KEY uq_orders_idempotency (user_id, idempotency_key),
    UNIQUE KEY uq_orders_id_shop (id, shop_id),
    KEY idx_orders_user (user_id, created_at),
    KEY idx_orders_shop_status (shop_id, status, accepted_at),
    KEY idx_orders_shop_created (shop_id, created_at),
    CONSTRAINT chk_orders_status CHECK (status IN ('PENDING','ACCEPTED','PREPARING','READY','COMPLETED','REJECTED','CANCELLED')),
    CONSTRAINT chk_orders_total CHECK (total >= 0),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_orders_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    menu_id BIGINT UNSIGNED NOT NULL,
    name_snapshot VARCHAR(150) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_menu (menu_id),
    CONSTRAINT chk_order_items CHECK (quantity >= 1 AND unit_price >= 0),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_order_items_menu FOREIGN KEY (menu_id) REFERENCES menus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_item_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    name_snapshot VARCHAR(100) NOT NULL,
    price_delta_snapshot DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_order_item_options (order_item_id, option_id),
    KEY idx_order_item_options_option (option_id),
    CONSTRAINT fk_order_item_options_item FOREIGN KEY (order_item_id) REFERENCES order_items (id),
    CONSTRAINT fk_order_item_options_option FOREIGN KEY (option_id) REFERENCES menu_options (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- actor_user_id NULL = System.
CREATE TABLE IF NOT EXISTS order_status_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_logs_order (order_id, id),
    KEY idx_status_logs_actor (actor_user_id),
    CONSTRAINT fk_status_logs_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_status_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per order, so a second payment for the same order is impossible.
CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    method VARCHAR(20) NOT NULL DEFAULT 'PAY_AT_STORE',
    status VARCHAR(20) NOT NULL DEFAULT 'UNPAID',
    amount DECIMAL(10,2) NOT NULL,
    paid_at DATETIME NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_order (order_id),
    KEY idx_payments_confirmed_by (confirmed_by),
    KEY idx_payments_status (status, paid_at),
    CONSTRAINT chk_payments_method CHECK (method IN ('PAY_AT_STORE')),
    CONSTRAINT chk_payments_status CHECK (status IN ('UNPAID','PAID','REFUNDED')),
    CONSTRAINT chk_payments_paid CHECK (status = 'UNPAID' OR (paid_at IS NOT NULL AND confirmed_by IS NOT NULL)),
    CONSTRAINT chk_payments_amount CHECK (amount >= 0),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_payments_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
