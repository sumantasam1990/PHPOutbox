-- ============================================================
-- Outbox Messages Table — PostgreSQL 9.5+
-- ============================================================
-- This table stores domain events atomically alongside business
-- data within the same database transaction.
--
-- Uses JSONB for efficient payload querying and indexing.
-- Uses TIMESTAMPTZ for proper timezone-aware timestamps.
--
-- Requires: PostgreSQL 9.5+ for SELECT ... FOR UPDATE SKIP LOCKED
-- ============================================================

CREATE TABLE IF NOT EXISTS "outbox_messages" (
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

CREATE INDEX IF NOT EXISTS "idx_outbox_pending" ON "outbox_messages" ("status", "created_at");
CREATE INDEX IF NOT EXISTS "idx_outbox_aggregate" ON "outbox_messages" ("aggregate_type", "aggregate_id");
CREATE INDEX IF NOT EXISTS "idx_outbox_prune" ON "outbox_messages" ("status", "processed_at");
