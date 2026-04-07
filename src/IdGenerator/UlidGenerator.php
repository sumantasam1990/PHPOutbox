<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\IdGenerator;

use PhpOutbox\Outbox\Contracts\IdGenerator;

/**
 * ULID (Universally Unique Lexicographically Sortable Identifier) generator.
 *
 * ULIDs are 128-bit identifiers that are:
 * - Time-ordered (first 48 bits = millisecond timestamp)
 * - Lexicographically sortable
 * - Crockford Base32 encoded (26 characters)
 * - Case-insensitive
 *
 * Spec: https://github.com/ulid/spec
 * Format: 01ARZ3NDEKTSV4RRFFQ69G5FAV (26 chars)
 */
final class UlidGenerator implements IdGenerator
{
    private const ENCODING_CHARS = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $timeMs = (int) (\microtime(true) * 1000);
        $randomBytes = \random_bytes(10);

        // Encode timestamp (10 chars, Crockford Base32)
        $timestamp = '';
        for ($i = 9; $i >= 0; $i--) {
            $timestamp = self::ENCODING_CHARS[$timeMs & 0x1F] . $timestamp;
            $timeMs >>= 5;
        }

        // Encode randomness (16 chars, Crockford Base32)
        $random = '';
        $randomInt = 0;
        $bitsAvailable = 0;

        $randomOffset = 0;
        for ($i = 0; $i < 16; $i++) {
            if ($bitsAvailable < 5) {
                if ($randomOffset < 10) {
                    $randomInt = ($randomInt << 8) | \ord($randomBytes[$randomOffset]);
                    $randomOffset++;
                    $bitsAvailable += 8;
                } else {
                    // Pad with zeros for remaining chars
                    $randomInt <<= (5 - $bitsAvailable);
                    $bitsAvailable = 5;
                }
            }
            $bitsAvailable -= 5;
            $random .= self::ENCODING_CHARS[($randomInt >> $bitsAvailable) & 0x1F];
        }

        return $timestamp . $random;
    }
}
