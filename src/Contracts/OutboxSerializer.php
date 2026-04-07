<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Contracts;

use PhpOutbox\Outbox\Exception\SerializationException;

/**
 * Contract for serializing and deserializing outbox message payloads.
 *
 * The default JsonSerializer handles most use cases. Implement this interface
 * for Avro, Protobuf, MessagePack, or any custom serialization format.
 */
interface OutboxSerializer
{
    /**
     * Serialize a payload (array or object) to a string.
     *
     * @param mixed $payload The data to serialize
     * @return string The serialized representation
     * @throws SerializationException
     */
    public function serialize(mixed $payload): string;

    /**
     * Deserialize a string back to an associative array.
     *
     * @param string $data The serialized data
     * @return array<string, mixed> The deserialized payload
     * @throws SerializationException
     */
    public function deserialize(string $data): array;

    /**
     * Return the content type / format identifier (e.g., "application/json").
     */
    public function contentType(): string;
}
