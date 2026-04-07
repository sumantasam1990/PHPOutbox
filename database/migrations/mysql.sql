-- ============================================================
-- Outbox Messages Table — MySQL 8.0+
-- ============================================================
-- This table stores domain events atomically alongside business
-- data within the same database transaction.
--
-- Key indexes:
--   idx_outbox_pending   — Fast lookup for the relay poller
--   idx_outbox_aggregate — Query events by domain aggregate
--   idx_outbox_prune     — Fast cleanup of old processed messages
--
-- Requires: MySQL 8.0+ for SELECT ... FOR UPDATE SKIP LOCKED
-- ============================================================

CREATE TABLE IF NOT EXISTS `outbox_messages` (
    `id`             VARCHAR(36) NOT NULL,
    `aggregate_type` VARCHAR(255) NOT NULL,
    `aggregate_id`   VARCHAR(255) NOT NULL,
    `event_type`     VARCHAR(255) NOT NULL,
    `payload`        JSON NOT NULL,
    `headers`        JSON DEFAULT NULL,
    `status`         VARCHAR(20) NOT NULL DEFAULT 'pending',
    `attempts`       INT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`     TEXT DEFAULT NULL,
    `created_at`     TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `processed_at`   TIMESTAMP(6) NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    INDEX `idx_outbox_pending` (`status`, `created_at`),
    INDEX `idx_outbox_aggregate` (`aggregate_type`, `aggregate_id`),
    INDEX `idx_outbox_prune` (`status`, `processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
