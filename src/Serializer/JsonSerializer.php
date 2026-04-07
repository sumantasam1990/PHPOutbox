<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Serializer;

use PhpOutbox\Outbox\Contracts\OutboxSerializer;
use PhpOutbox\Outbox\Exception\SerializationException;

/**
 * Default JSON serializer for outbox message payloads.
 *
 * Uses JSON_THROW_ON_ERROR for fail-fast behavior — no silent data corruption.
 */
final class JsonSerializer implements OutboxSerializer
{
    private readonly int $encodeFlags;

    public function __construct(
        bool $prettyPrint = false,
    ) {
        $this->encodeFlags = \JSON_THROW_ON_ERROR
            | \JSON_UNESCAPED_UNICODE
            | \JSON_UNESCAPED_SLASHES
            | \JSON_PRESERVE_ZERO_FRACTION
            | ($prettyPrint ? \JSON_PRETTY_PRINT : 0);
    }

    public function serialize(mixed $payload): string
    {
        if (\is_string($payload)) {
            // Already serialized — validate it's valid JSON
            \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

            return $payload;
        }

        try {
            return \json_encode($payload, $this->encodeFlags, 512);
        } catch (\JsonException $e) {
            throw SerializationException::serializeFailed($e->getMessage(), $e);
        }
    }

    public function deserialize(string $data): array
    {
        try {
            $decoded = \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded)) {
                throw SerializationException::deserializeFailed(
                    \sprintf('Expected JSON object/array, got %s', \get_debug_type($decoded)),
                );
            }

            return $decoded;
        } catch (\JsonException $e) {
            throw SerializationException::deserializeFailed($e->getMessage(), $e);
        }
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
