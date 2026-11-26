-- D32. cooking_started_at: when the order got a kitchen slot (capacity). Accepting sets PREPARING at once (D31),
-- so preparing_at includes the time spent waiting for a slot and cannot be used for ETAs or average cooking times.
-- pickup_failures: wrong QR / order numbers typed at hand-over; scanning is locked after 5.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cooking_started_at') = 0,
    'ALTER TABLE orders ADD COLUMN cooking_started_at DATETIME NULL AFTER preparing_at', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pickup_failures') = 0,
    'ALTER TABLE orders ADD COLUMN pickup_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_at', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- Orders from before this migration: the best known start is preparing_at.
UPDATE orders SET cooking_started_at = preparing_at WHERE cooking_started_at IS NULL AND preparing_at IS NOT NULL;
