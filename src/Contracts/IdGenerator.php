<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Contracts;

/**
 * Contract for generating unique message identifiers.
 *
 * Implementations should produce time-ordered IDs (UUID v7, ULID) for
 * optimal database index performance. Avoid UUID v4 — it fragments B-tree indexes.
 */
interface IdGenerator
{
    /**
     * Generate a unique, time-ordered identifier string.
     */
    public function generate(): string;
}
