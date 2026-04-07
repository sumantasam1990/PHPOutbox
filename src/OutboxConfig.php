<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox;

/**
 * Configuration value object for the outbox system.
 *
 * All values have sensible production defaults. Override only what you need.
 */
readonly class OutboxConfig
{
    /**
     * @param string $tableName          Database table name for outbox messages
     * @param int    $batchSize          Number of messages to fetch per relay cycle
     * @param int    $pollIntervalMs     Milliseconds between relay polls (0 = no sleep, for single-pass mode)
     * @param int    $maxAttempts        Maximum publish attempts before dead-lettering
     * @param int    $retryBackoffMs     Base backoff in ms between retries (multiplied by attempt number)
     * @param int    $pruneAfterDays     Days to keep published/dead-letter messages before pruning
     * @param bool   $deleteOnPublish    If true, DELETE the row on publish; if false, mark as Published
     * @param int    $lockTimeoutSeconds Timeout in seconds for row-level locks
     */
    public function __construct(
        public string $tableName = 'outbox_messages',
        public int $batchSize = 100,
        public int $pollIntervalMs = 1000,
        public int $maxAttempts = 5,
        public int $retryBackoffMs = 2000,
        public int $pruneAfterDays = 30,
        public bool $deleteOnPublish = false,
        public int $lockTimeoutSeconds = 30,
    ) {
    }

    /**
     * Create a config from an associative array (e.g., from a config file).
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            tableName: (string) ($values['table_name'] ?? 'outbox_messages'),
            batchSize: (int) ($values['batch_size'] ?? 100),
            pollIntervalMs: (int) ($values['poll_interval_ms'] ?? 1000),
            maxAttempts: (int) ($values['max_attempts'] ?? 5),
            retryBackoffMs: (int) ($values['retry_backoff_ms'] ?? 2000),
            pruneAfterDays: (int) ($values['prune_after_days'] ?? 30),
            deleteOnPublish: (bool) ($values['delete_on_publish'] ?? false),
            lockTimeoutSeconds: (int) ($values['lock_timeout_seconds'] ?? 30),
        );
    }
}
