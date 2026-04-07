<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\IdGenerator;

use PhpOutbox\Outbox\Contracts\IdGenerator;

/**
 * UUID v7 generator — time-ordered UUIDs optimal for database primary keys.
 *
 * UUID v7 embeds a Unix timestamp in the first 48 bits, making inserts
 * B-tree friendly (always appending, no index fragmentation). This is the
 * recommended ID strategy for outbox messages.
 *
 * Spec: RFC 9562 (Section 5.7)
 * Format: tttttttt-tttt-7xxx-yxxx-xxxxxxxxxxxx
 */
final class UuidV7Generator implements IdGenerator
{
    public function generate(): string
    {
        // Current time in milliseconds
        $timeMs = (int) (\microtime(true) * 1000);

        // 6 bytes of timestamp (48 bits)
        $timeBin = \pack('J', $timeMs);
        $timeBin = \substr($timeBin, 2, 6); // Take last 6 bytes

        // 10 bytes of cryptographic randomness
        $randomBytes = \random_bytes(10);

        // Combine: 6 bytes time + 10 bytes random = 16 bytes total
        $uuid = $timeBin . $randomBytes;

        // Set version 7 (bits 48-51)
        $uuid[6] = \chr((\ord($uuid[6]) & 0x0F) | 0x70);

        // Set variant 10xx (bits 64-65)
        $uuid[8] = \chr((\ord($uuid[8]) & 0x3F) | 0x80);

        // Format as canonical UUID string
        $hex = \bin2hex($uuid);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20, 12),
        );
    }
}
