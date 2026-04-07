<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Store;

/**
 * SQL schema helper for creating and managing the outbox table.
 *
 * Provides DDL statements for MySQL and PostgreSQL.
 */
final class Schema
{
    public static function mysql(string $tableName = 'outbox_messages'): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
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
            SQL;
    }

    public static function postgresql(string $tableName = 'outbox_messages'): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS "{$tableName}" (
                "id"             VARCHAR(36) NOT NULL,
                "aggregate_type" VARCHAR(255) NOT NULL,
                "aggregate_id"   VARCHAR(255) NOT NULL,
                "event_type"     VARCHAR(255) NOT NULL,
                "payload"        JSONB NOT NULL,
                "headers"        JSONB DEFAULT NULL,
                "status"         VARCHAR(20) NOT NULL DEFAULT 'pending',
                "attempts"       INTEGER NOT NULL DEFAULT 0,
                "last_error"     TEXT DEFAULT NULL,
                "created_at"     TIMESTAMPTZ(6) NOT NULL DEFAULT NOW(),
                "processed_at"   TIMESTAMPTZ(6) NULL DEFAULT NULL,

                PRIMARY KEY ("id")
            );

            CREATE INDEX IF NOT EXISTS "idx_outbox_pending" ON "{$tableName}" ("status", "created_at");
            CREATE INDEX IF NOT EXISTS "idx_outbox_aggregate" ON "{$tableName}" ("aggregate_type", "aggregate_id");
            CREATE INDEX IF NOT EXISTS "idx_outbox_prune" ON "{$tableName}" ("status", "processed_at");
            SQL;
    }

    /**
     * SQLite schema — for testing only.
     */
    public static function sqlite(string $tableName = 'outbox_messages'): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS "{$tableName}" (
                "id"             VARCHAR(36) NOT NULL PRIMARY KEY,
                "aggregate_type" VARCHAR(255) NOT NULL,
                "aggregate_id"   VARCHAR(255) NOT NULL,
                "event_type"     VARCHAR(255) NOT NULL,
                "payload"        TEXT NOT NULL,
                "headers"        TEXT DEFAULT NULL,
                "status"         VARCHAR(20) NOT NULL DEFAULT 'pending',
                "attempts"       INTEGER NOT NULL DEFAULT 0,
                "last_error"     TEXT DEFAULT NULL,
                "created_at"     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f', 'now')),
                "processed_at"   TEXT NULL DEFAULT NULL
            );

            CREATE INDEX IF NOT EXISTS "idx_outbox_pending" ON "{$tableName}" ("status", "created_at");
            CREATE INDEX IF NOT EXISTS "idx_outbox_aggregate" ON "{$tableName}" ("aggregate_type", "aggregate_id");
            CREATE INDEX IF NOT EXISTS "idx_outbox_prune" ON "{$tableName}" ("status", "processed_at");
            SQL;
    }

    /**
     * Detect the database driver and return appropriate schema.
     */
    public static function forDriver(string $driver, string $tableName = 'outbox_messages'): string
    {
        return match (\strtolower($driver)) {
            'mysql' => self::mysql($tableName),
            'pgsql', 'postgres', 'postgresql' => self::postgresql($tableName),
            'sqlite' => self::sqlite($tableName),
            default => throw new \InvalidArgumentException(
                \sprintf('Unsupported database driver "%s". Supported: mysql, pgsql, sqlite.', $driver),
            ),
        };
    }

    /**
     * Drop table statement (use with caution).
     */
    public static function drop(string $tableName = 'outbox_messages'): string
    {
        return \sprintf('DROP TABLE IF EXISTS "%s";', $tableName);
    }
}
