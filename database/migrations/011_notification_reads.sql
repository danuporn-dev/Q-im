-- D36. Which outbox entries a customer has seen on the website. A table of its own, so that reading a
-- message never changes the outbox rows that the LINE worker claims and retries.

CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_reads FOREIGN KEY (notification_id) REFERENCES notifications (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
