CREATE TABLE IF NOT EXISTS food_courts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    food_court_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    accepting_orders TINYINT(1) NOT NULL DEFAULT 0,
    capacity INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_shops_court (food_court_id, active),
    CONSTRAINT chk_shops_capacity CHECK (capacity IS NULL OR capacity > 0),
    CONSTRAINT fk_shops_court FOREIGN KEY (food_court_id) REFERENCES food_courts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    permissions TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shop_members (shop_id, user_id),
    KEY idx_shop_members_user (user_id),
    CONSTRAINT fk_shop_members_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_shop_members_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- day_of_week: ISO-8601, 1 = Monday ... 7 = Sunday. Times are Asia/Bangkok wall-clock.
CREATE TABLE IF NOT EXISTS shop_hours (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    open_time TIME NOT NULL,
    close_time TIME NOT NULL,
    UNIQUE KEY uq_shop_hours (shop_id, day_of_week, open_time),
    CONSTRAINT chk_shop_hours_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT fk_shop_hours_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per shop, so shop_id is the primary key. settings: JSON text handled by PHP.
CREATE TABLE IF NOT EXISTS shop_settings (
    shop_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    avg_prep_minutes INT UNSIGNED NULL,
    max_active_orders INT UNSIGNED NULL,
    settings TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_settings_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
