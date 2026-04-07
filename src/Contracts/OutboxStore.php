<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Contracts;

use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\Exception\StoreException;
use PhpOutbox\Outbox\Message\OutboxMessage;

/**
 * Contract for outbox message persistence.
 *
 * Implementations MUST use row-level locking (e.g. SELECT ... FOR UPDATE SKIP LOCKED)
 * to ensure safe concurrent access from multiple relay processes.
 */
interface OutboxStore
{
    /**
     * Persist a single outbox message.
     *
     * This MUST be called within the same database transaction as the
     * business data write to guarantee atomicity.
     *
     * @throws StoreException
     */
    public function store(OutboxMessage $message): void;

    /**
     * Persist multiple outbox messages in a single batch.
     *
     * @param OutboxMessage[] $messages
     * @throws StoreException
     */
    public function storeMany(array $messages): void;

    /**
     * Fetch pending messages and lock them for processing.
     *
     * Must use SELECT ... FOR UPDATE SKIP LOCKED (or equivalent) to allow
     * safe concurrent relay workers.
     *
     * @return OutboxMessage[]
     * @throws StoreException
     */
    public function fetchPending(int $batchSize): array;

    /**
     * Mark a message as successfully published.
     *
     * @throws StoreException
     */
    public function markAsPublished(string $messageId): void;

    /**
     * Mark a message as failed with the error reason.
     * Increments the attempt counter.
     *
     * @throws StoreException
     */
    public function markAsFailed(string $messageId, string $error): void;

    /**
     * Move a message to the dead-letter status after exhausting retries.
     *
     * @throws StoreException
     */
    public function moveToDeadLetter(string $messageId, string $error): void;

    /**
     * Delete old processed messages for housekeeping.
     *
     * @param OutboxMessageStatus $status Status to prune (typically Published or DeadLetter)
     * @param \DateTimeImmutable  $before Delete messages processed before this timestamp
     * @return int Number of pruned rows
     * @throws StoreException
     */
    public function prune(OutboxMessageStatus $status, \DateTimeImmutable $before): int;

    /**
     * Get the current count of messages by status.
     *
     * @return array<string, int> Map of status => count
     * @throws StoreException
     */
    public function getCounts(): array;

    /**
     * Begin a database transaction.
     *
     * @throws StoreException
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     *
     * @throws StoreException
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     *
     * @throws StoreException
     */
    public function rollBack(): void;
}
