-- Columns and tables needed at runtime by the API, the rate limiter and the LINE integration.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so each ALTER is prepared only when the column is missing.

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'preparing_at') = 0,
    'ALTER TABLE orders ADD COLUMN preparing_at DATETIME NULL AFTER accepted_at', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- line_friend: NULL = unknown, 1 = follows the OA, 0 = blocked. line_friend_at: LINE event time in ms.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'line_friend') = 0,
    'ALTER TABLE users ADD COLUMN line_friend TINYINT(1) NULL AFTER avatar_url', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'line_friend_at') = 0,
    'ALTER TABLE users ADD COLUMN line_friend_at BIGINT UNSIGNED NULL AFTER line_friend', 'DO 0');
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (bucket, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS line_webhook_events (
    event_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
