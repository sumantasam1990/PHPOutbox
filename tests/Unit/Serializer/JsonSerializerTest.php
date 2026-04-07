<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\Serializer;

use PhpOutbox\Outbox\Exception\SerializationException;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonSerializer::class)]
final class JsonSerializerTest extends TestCase
{
    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    #[Test]
    public function it_serializes_arrays(): void
    {
        $data = ['name' => 'John', 'age' => 30, 'scores' => [1, 2, 3]];
        $json = $this->serializer->serialize($data);

        self::assertJson($json);
        self::assertSame('{"name":"John","age":30,"scores":[1,2,3]}', $json);
    }

    #[Test]
    public function it_preserves_unicode(): void
    {
        $data = ['name' => '日本語テスト', 'emoji' => '🚀'];
        $json = $this->serializer->serialize($data);

        self::assertStringContainsString('日本語テスト', $json);
        self::assertStringContainsString('🚀', $json);
    }

    #[Test]
    public function it_preserves_zero_fractions(): void
    {
        $data = ['amount' => 10.0];
        $json = $this->serializer->serialize($data);

        self::assertSame('{"amount":10.0}', $json);
    }

    #[Test]
    public function it_passes_through_valid_json_strings(): void
    {
        $original = '{"already":"serialized"}';
        $result = $this->serializer->serialize($original);

        self::assertSame($original, $result);
    }

    #[Test]
    public function it_rejects_invalid_json_strings(): void
    {
        $this->expectException(\JsonException::class);
        $this->serializer->serialize('not valid json');
    }

    #[Test]
    public function it_deserializes_json_to_array(): void
    {
        $json = '{"name":"John","age":30}';
        $data = $this->serializer->deserialize($json);

        self::assertSame(['name' => 'John', 'age' => 30], $data);
    }

    #[Test]
    public function it_deserializes_nested_json(): void
    {
        $json = '{"order":{"id":"123","items":[{"sku":"A","qty":2}]}}';
        $data = $this->serializer->deserialize($json);

        self::assertArrayHasKey('order', $data);
        self::assertSame('123', $data['order']['id']);
        self::assertCount(1, $data['order']['items']);
    }

    #[Test]
    public function it_throws_on_invalid_json_deserialization(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Failed to deserialize');
        $this->serializer->deserialize('{{invalid}}');
    }

    #[Test]
    public function it_throws_on_non_object_json(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Expected JSON object/array');
        $this->serializer->deserialize('"just a string"');
    }

    #[Test]
    public function it_returns_correct_content_type(): void
    {
        self::assertSame('application/json', $this->serializer->contentType());
    }

    #[Test]
    public function it_supports_pretty_print(): void
    {
        $serializer = new JsonSerializer(prettyPrint: true);
        $data = ['key' => 'value'];
        $json = $serializer->serialize($data);

        self::assertStringContainsString("\n", $json);
        self::assertStringContainsString('    ', $json);
    }

    #[Test]
    public function round_trip_preserves_data(): void
    {
        $original = [
            'id' => 'test-123',
            'amount' => 99.99,
            'tags' => ['a', 'b', 'c'],
            'metadata' => ['key' => 'value'],
            'nullable' => null,
        ];

        $json = $this->serializer->serialize($original);
        $decoded = $this->serializer->deserialize($json);

        self::assertSame($original, $decoded);
    }
}
