-- Columns and tables needed at runtime by the API, the rate limiter and the LINE integration.

ALTER TABLE orders ADD COLUMN IF NOT EXISTS preparing_at DATETIME NULL AFTER accepted_at;

-- line_friend: NULL = unknown, 1 = follows the OA, 0 = blocked. line_friend_at: LINE event time in ms.
ALTER TABLE users ADD COLUMN IF NOT EXISTS line_friend TINYINT(1) NULL AFTER avatar_url;

ALTER TABLE users ADD COLUMN IF NOT EXISTS line_friend_at BIGINT UNSIGNED NULL AFTER line_friend;

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
