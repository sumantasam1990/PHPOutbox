<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Relay;

/**
 * Immutable metrics from a single relay run cycle.
 */
readonly class RelayMetrics
{
    public function __construct(
        public int $processed,
        public int $published,
        public int $failed,
        public int $deadLettered,
        public float $durationMs,
        public int $cycleNumber,
    ) {
    }

    /**
     * Create an empty metrics object (no messages processed).
     */
    public static function empty(int $cycleNumber): self
    {
        return new self(
            processed: 0,
            published: 0,
            failed: 0,
            deadLettered: 0,
            durationMs: 0.0,
            cycleNumber: $cycleNumber,
        );
    }

    /**
     * Check if any messages were processed in this cycle.
     */
    public function hasActivity(): bool
    {
        return $this->processed > 0;
    }

    /**
     * Get a human-readable summary string.
     */
    public function summary(): string
    {
        return \sprintf(
            'Cycle #%d: %d processed (%d published, %d failed, %d dead-lettered) in %.1fms',
            $this->cycleNumber,
            $this->processed,
            $this->published,
            $this->failed,
            $this->deadLettered,
            $this->durationMs,
        );
    }
}
