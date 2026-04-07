<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Store;

use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\Exception\StoreException;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\OutboxConfig;

/**
 * PDO-based outbox store supporting MySQL, PostgreSQL, and SQLite.
 *
 * Key design decisions:
 * - Uses SELECT ... FOR UPDATE SKIP LOCKED on MySQL/PostgreSQL for safe concurrent polling
 * - Falls back to simple SELECT on SQLite (no row-level locking — single-writer only)
 * - Batch inserts via multi-value INSERT for performance
 * - All timestamps stored as UTC
 */
final class PdoOutboxStore implements OutboxStore
{
    private readonly string $driver;
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboxConfig $config = new OutboxConfig(),
    ) {
        $this->driver = \strtolower($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->table = $this->config->tableName;

        // Enforce strict error mode
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function store(OutboxMessage $message): void
    {
        $sql = \sprintf(
            'INSERT INTO %s (id, aggregate_type, aggregate_id, event_type, payload, headers, status, attempts, last_error, created_at, processed_at)
             VALUES (:id, :aggregate_type, :aggregate_id, :event_type, :payload, :headers, :status, :attempts, :last_error, :created_at, :processed_at)',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindMessageParams($stmt, $message);
            $stmt->execute();
        } catch (PDOException $e) {
            throw StoreException::storeFailed($message->id, $e);
        }
    }

    public function storeMany(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $columns = '(id, aggregate_type, aggregate_id, event_type, payload, headers, status, attempts, last_error, created_at, processed_at)';
        $placeholders = [];
        $params = [];

        foreach ($messages as $index => $message) {
            $placeholders[] = \sprintf(
                '(:id_%d, :aggregate_type_%d, :aggregate_id_%d, :event_type_%d, :payload_%d, :headers_%d, :status_%d, :attempts_%d, :last_error_%d, :created_at_%d, :processed_at_%d)',
                $index, $index, $index, $index, $index, $index, $index, $index, $index, $index, $index,
            );

            $params["id_{$index}"] = $message->id;
            $params["aggregate_type_{$index}"] = $message->aggregateType;
            $params["aggregate_id_{$index}"] = $message->aggregateId;
            $params["event_type_{$index}"] = $message->eventType;
            $params["payload_{$index}"] = $message->payload;
            $params["headers_{$index}"] = $message->headers !== [] ? \json_encode($message->headers, \JSON_THROW_ON_ERROR) : null;
            $params["status_{$index}"] = $message->status->value;
            $params["attempts_{$index}"] = $message->attempts;
            $params["last_error_{$index}"] = $message->lastError;
            $params["created_at_{$index}"] = $message->createdAt->format('Y-m-d H:i:s.u');
            $params["processed_at_{$index}"] = $message->processedAt?->format('Y-m-d H:i:s.u');
        }

        $sql = \sprintf(
            'INSERT INTO %s %s VALUES %s',
            $this->quoteTable(),
            $columns,
            \implode(', ', $placeholders),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            throw StoreException::storeFailed('batch', $e);
        }
    }

    public function fetchPending(int $batchSize): array
    {
        $sql = match ($this->driver) {
            'mysql' => \sprintf(
                'SELECT * FROM %s WHERE status IN (:pending, :failed) ORDER BY created_at ASC LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->quoteTable(),
                $batchSize,
            ),
            'pgsql' => \sprintf(
                'SELECT * FROM %s WHERE status IN (:pending, :failed) ORDER BY created_at ASC LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->quoteTable(),
                $batchSize,
            ),
            // SQLite: no row-level locking
            default => \sprintf(
                'SELECT * FROM %s WHERE status IN (:pending, :failed) ORDER BY created_at ASC LIMIT %d',
                $this->quoteTable(),
                $batchSize,
            ),
        };

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'pending' => OutboxMessageStatus::Pending->value,
                'failed' => OutboxMessageStatus::Failed->value,
            ]);

            $rows = $stmt->fetchAll();
            $messages = [];

            foreach ($rows as $row) {
                $messages[] = $this->hydrate($row);
            }

            // Mark fetched messages as processing
            if ($messages !== []) {
                $ids = \array_map(static fn (OutboxMessage $m): string => $m->id, $messages);
                $this->updateStatus($ids, OutboxMessageStatus::Processing);
            }

            return $messages;
        } catch (PDOException $e) {
            throw StoreException::fetchFailed($e);
        }
    }

    public function markAsPublished(string $messageId): void
    {
        if ($this->config->deleteOnPublish) {
            $this->deleteMessage($messageId);

            return;
        }

        $sql = \sprintf(
            'UPDATE %s SET status = :status, processed_at = :processed_at WHERE id = :id',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => OutboxMessageStatus::Published->value,
                'processed_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u'),
                'id' => $messageId,
            ]);
        } catch (PDOException $e) {
            throw StoreException::updateFailed($messageId, 'mark as published', $e);
        }
    }

    public function markAsFailed(string $messageId, string $error): void
    {
        $sql = \sprintf(
            'UPDATE %s SET status = :status, attempts = attempts + 1, last_error = :error WHERE id = :id',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => OutboxMessageStatus::Failed->value,
                'error' => \mb_substr($error, 0, 2000),
                'id' => $messageId,
            ]);
        } catch (PDOException $e) {
            throw StoreException::updateFailed($messageId, 'mark as failed', $e);
        }
    }

    public function moveToDeadLetter(string $messageId, string $error): void
    {
        $sql = \sprintf(
            'UPDATE %s SET status = :status, last_error = :error, processed_at = :processed_at WHERE id = :id',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => OutboxMessageStatus::DeadLetter->value,
                'error' => \mb_substr($error, 0, 2000),
                'processed_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u'),
                'id' => $messageId,
            ]);
        } catch (PDOException $e) {
            throw StoreException::updateFailed($messageId, 'move to dead letter', $e);
        }
    }

    public function prune(OutboxMessageStatus $status, DateTimeImmutable $before): int
    {
        $sql = \sprintf(
            'DELETE FROM %s WHERE status = :status AND processed_at < :before',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => $status->value,
                'before' => $before->format('Y-m-d H:i:s.u'),
            ]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw StoreException::wrap('Failed to prune outbox messages', $e);
        }
    }

    public function getCounts(): array
    {
        $sql = \sprintf(
            'SELECT status, COUNT(*) as count FROM %s GROUP BY status',
            $this->quoteTable(),
        );

        try {
            $stmt = $this->pdo->query($sql);

            if ($stmt === false) {
                return [];
            }

            $counts = [];

            foreach ($stmt->fetchAll() as $row) {
                $counts[$row['status']] = (int) $row['count'];
            }

            return $counts;
        } catch (PDOException $e) {
            throw StoreException::wrap('Failed to get outbox counts', $e);
        }
    }

    public function beginTransaction(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw StoreException::wrap('Failed to begin transaction', $e);
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo->commit();
        } catch (PDOException $e) {
            throw StoreException::wrap('Failed to commit transaction', $e);
        }
    }

    public function rollBack(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw StoreException::wrap('Failed to roll back transaction', $e);
        }
    }

    /**
     * Get the underlying PDO connection (for users who need to run business
     * queries in the same transaction).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    private function bindMessageParams(PDOStatement $stmt, OutboxMessage $message): void
    {
        $stmt->bindValue('id', $message->id);
        $stmt->bindValue('aggregate_type', $message->aggregateType);
        $stmt->bindValue('aggregate_id', $message->aggregateId);
        $stmt->bindValue('event_type', $message->eventType);
        $stmt->bindValue('payload', $message->payload);
        $stmt->bindValue('headers', $message->headers !== [] ? \json_encode($message->headers, \JSON_THROW_ON_ERROR) : null);
        $stmt->bindValue('status', $message->status->value);
        $stmt->bindValue('attempts', $message->attempts, PDO::PARAM_INT);
        $stmt->bindValue('last_error', $message->lastError);
        $stmt->bindValue('created_at', $message->createdAt->format('Y-m-d H:i:s.u'));
        $stmt->bindValue('processed_at', $message->processedAt?->format('Y-m-d H:i:s.u'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxMessage
    {
        $headers = [];
        $rawHeaders = $row['headers'] ?? null;
        if (\is_string($rawHeaders) && $rawHeaders !== '') {
            $decoded = \json_decode($rawHeaders, true, 512, \JSON_THROW_ON_ERROR);
            $headers = \is_array($decoded) ? $decoded : [];
        }

        return new OutboxMessage(
            id: (string) $row['id'],
            aggregateType: (string) $row['aggregate_type'],
            aggregateId: (string) $row['aggregate_id'],
            eventType: (string) $row['event_type'],
            payload: (string) $row['payload'],
            headers: $headers,
            status: OutboxMessageStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            processedAt: isset($row['processed_at']) ? new DateTimeImmutable((string) $row['processed_at']) : null,
        );
    }

    /**
     * @param string[] $ids
     */
    private function updateStatus(array $ids, OutboxMessageStatus $status): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = \implode(', ', \array_fill(0, \count($ids), '?'));
        $sql = \sprintf(
            'UPDATE %s SET status = ? WHERE id IN (%s)',
            $this->quoteTable(),
            $placeholders,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$status->value, ...$ids]);
    }

    private function deleteMessage(string $messageId): void
    {
        $sql = \sprintf('DELETE FROM %s WHERE id = :id', $this->quoteTable());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $messageId]);
    }

    private function quoteTable(): string
    {
        return match ($this->driver) {
            'mysql' => "`{$this->table}`",
            default => "\"{$this->table}\"",
        };
    }
}
