-- D41. Uploaded pictures of menus and shops, and the drawn picture used when a menu has none.
-- menus.image_url and shops.image_url (since 002/003) keep holding "image:<id>" or an https address.
-- images records who may use each uploaded file: a shop can only put its own uploads on its menus,
-- so it can never delete or show another shop's file. Files live in storage/uploads, outside the web root.

CREATE TABLE IF NOT EXISTS images (
    -- 32 lowercase hex characters; the table collation, so it compares with image_url without conversion.
    id CHAR(32) NOT NULL PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_images_shop (shop_id, created_at),
    CONSTRAINT fk_images_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_images_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The drawn picture chosen by the shop; NULL means it is guessed from the menu and category names.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus' AND COLUMN_NAME = 'art') = 0,
    'ALTER TABLE menus ADD COLUMN art VARCHAR(10) NULL AFTER image_url', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'menus' AND CONSTRAINT_NAME = 'chk_menus_art') = 0,
    'ALTER TABLE menus ADD CONSTRAINT chk_menus_art CHECK (art IS NULL OR art IN (''rice'', ''noodle'', ''fried'', ''drink'', ''dessert'', ''other''))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- Finding whether a picture is still used before its file is deleted.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus' AND INDEX_NAME = 'idx_menus_image') = 0,
    'ALTER TABLE menus ADD KEY idx_menus_image (image_url(38))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops' AND INDEX_NAME = 'idx_shops_image') = 0,
    'ALTER TABLE shops ADD KEY idx_shops_image (image_url(38))', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
