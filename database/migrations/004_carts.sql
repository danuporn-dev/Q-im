-- One cart per user, holding items of one shop. Carts are not history, so their children cascade.

CREATE TABLE IF NOT EXISTS carts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_carts_user (user_id),
    KEY idx_carts_shop (shop_id),
    CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_carts_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cart_id BIGINT UNSIGNED NOT NULL,
    menu_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    KEY idx_cart_items_cart (cart_id),
    KEY idx_cart_items_menu (menu_id),
    CONSTRAINT chk_cart_items_quantity CHECK (quantity >= 1),
    CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_menu FOREIGN KEY (menu_id) REFERENCES menus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_item_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cart_item_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_cart_item_options (cart_item_id, option_id),
    KEY idx_cart_item_options_option (option_id),
    CONSTRAINT fk_cart_item_options_item FOREIGN KEY (cart_item_id) REFERENCES cart_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_item_options_option FOREIGN KEY (option_id) REFERENCES menu_options (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
