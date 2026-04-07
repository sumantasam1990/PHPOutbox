<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Relay;

use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\OutboxConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background relay that polls the outbox table and publishes messages.
 *
 * This is the core engine of the outbox pattern. It:
 * 1. Fetches pending/failed messages with row-level locking
 * 2. Publishes each message via the OutboxPublisher
 * 3. Marks as published on success, handles retry/dead-letter on failure
 * 4. Sleeps between cycles (configurable poll interval)
 * 5. Handles graceful shutdown on SIGTERM/SIGINT
 *
 * Usage:
 *   $relay = new OutboxRelay($store, $publisher, $config);
 *   $relay->run(); // Blocks forever (use in a daemon/supervisor)
 *   $relay->runOnce(); // Single pass (use in cron/testing)
 */
final class OutboxRelay
{
    private bool $shouldStop = false;
    private int $cycleNumber = 0;

    public function __construct(
        private readonly OutboxStore $store,
        private readonly OutboxPublisher $publisher,
        private readonly OutboxConfig $config = new OutboxConfig(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Run the relay in an infinite loop until stopped.
     *
     * Register POSIX signal handlers for graceful shutdown.
     * This method blocks — run it in a dedicated process.
     */
    public function run(): void
    {
        $this->registerSignalHandlers();

        $this->logger->info('Outbox relay started', [
            'batch_size' => $this->config->batchSize,
            'poll_interval_ms' => $this->config->pollIntervalMs,
            'max_attempts' => $this->config->maxAttempts,
        ]);

        while (!$this->shouldStop) {
            $metrics = $this->runOnce();

            if ($metrics->hasActivity()) {
                $this->logger->info($metrics->summary());
            }

            if (!$this->shouldStop && $this->config->pollIntervalMs > 0) {
                \usleep($this->config->pollIntervalMs * 1000);
            }

            // Allow signal processing
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
        }

        $this->logger->info('Outbox relay stopped gracefully', [
            'total_cycles' => $this->cycleNumber,
        ]);
    }

    /**
     * Execute a single relay cycle: fetch → publish → update.
     *
     * Returns metrics for the cycle. Use this for cron-based relay
     * or in test environments.
     *
     * @phpstan-impure
     */
    public function runOnce(): RelayMetrics
    {
        $this->cycleNumber++;
        $start = \hrtime(true);

        $published = 0;
        $failed = 0;
        $deadLettered = 0;

        try {
            $this->store->beginTransaction();
            $messages = $this->store->fetchPending($this->config->batchSize);

            if ($messages === []) {
                $this->store->commit();

                return RelayMetrics::empty($this->cycleNumber);
            }

            $this->store->commit();

            foreach ($messages as $message) {
                try {
                    $this->publisher->publish($message);
                    $this->store->markAsPublished($message->id);
                    $published++;

                    $this->logger->debug('Published outbox message', [
                        'id' => $message->id,
                        'event_type' => $message->eventType,
                        'aggregate_type' => $message->aggregateType,
                        'aggregate_id' => $message->aggregateId,
                    ]);
                } catch (PublishException $e) {
                    $totalAttempts = $message->attempts + 1;

                    if ($totalAttempts >= $this->config->maxAttempts) {
                        $this->store->moveToDeadLetter($message->id, $e->getMessage());
                        $deadLettered++;

                        $this->logger->error('Outbox message dead-lettered after max attempts', [
                            'id' => $message->id,
                            'event_type' => $message->eventType,
                            'attempts' => $totalAttempts,
                            'error' => $e->getMessage(),
                        ]);
                    } else {
                        $this->store->markAsFailed($message->id, $e->getMessage());
                        $failed++;

                        $this->logger->warning('Outbox message publish failed, will retry', [
                            'id' => $message->id,
                            'event_type' => $message->eventType,
                            'attempt' => $totalAttempts,
                            'max_attempts' => $this->config->maxAttempts,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Unexpected errors — treat as publish failure
                    $totalAttempts = $message->attempts + 1;

                    if ($totalAttempts >= $this->config->maxAttempts) {
                        $this->store->moveToDeadLetter($message->id, $e->getMessage());
                        $deadLettered++;
                    } else {
                        $this->store->markAsFailed($message->id, $e->getMessage());
                        $failed++;
                    }

                    $this->logger->error('Unexpected error publishing outbox message', [
                        'id' => $message->id,
                        'event_type' => $message->eventType,
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            try {
                $this->store->rollBack();
            } catch (\Throwable) {
                // Swallow rollback failure — the original error is more important
            }

            $this->logger->error('Outbox relay cycle failed', [
                'cycle' => $this->cycleNumber,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        $durationMs = (\hrtime(true) - $start) / 1_000_000;

        return new RelayMetrics(
            processed: $published + $failed + $deadLettered,
            published: $published,
            failed: $failed,
            deadLettered: $deadLettered,
            durationMs: $durationMs,
            cycleNumber: $this->cycleNumber,
        );
    }

    /**
     * Signal the relay to stop after the current cycle.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->logger->info('Outbox relay stop requested');
    }

    /**
     * Check if the relay is scheduled to stop.
     */
    public function isStopping(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Get the current cycle number.
     */
    public function getCycleNumber(): int
    {
        return $this->cycleNumber;
    }

    /**
     * Register POSIX signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->logger->warning('pcntl extension not available — graceful shutdown via signals disabled');

            return;
        }

        \pcntl_signal(\SIGTERM, function (): void {
            $this->stop();
        });

        \pcntl_signal(\SIGINT, function (): void {
            $this->stop();
        });

        $this->logger->debug('Signal handlers registered (SIGTERM, SIGINT)');
    }
}
