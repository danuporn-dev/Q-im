-- Retire rows with active = 0; foreign keys from order history block hard deletes.

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categories_id_shop (id, shop_id),
    KEY idx_categories_shop (shop_id, sort_order),
    CONSTRAINT fk_categories_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- available = 0 means sold out for now; active = 0 means retired.
-- The composite foreign key keeps a menu in a category of its own shop.
CREATE TABLE IF NOT EXISTS menus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    price DECIMAL(10,2) NOT NULL,
    prep_minutes INT UNSIGNED NULL,
    available TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_menus_shop (shop_id, active, available),
    KEY idx_menus_category (category_id, shop_id),
    KEY idx_menus_name (name),
    CONSTRAINT chk_menus_price CHECK (price >= 0),
    CONSTRAINT fk_menus_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_menus_category FOREIGN KEY (category_id, shop_id) REFERENCES categories (id, shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_option_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    menu_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    min_select INT UNSIGNED NOT NULL DEFAULT 0,
    max_select INT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_option_groups_menu (menu_id),
    CONSTRAINT chk_option_groups_select CHECK (max_select >= 1 AND min_select <= max_select),
    CONSTRAINT fk_option_groups_menu FOREIGN KEY (menu_id) REFERENCES menus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_options_group (group_id),
    CONSTRAINT fk_options_group FOREIGN KEY (group_id) REFERENCES menu_option_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
