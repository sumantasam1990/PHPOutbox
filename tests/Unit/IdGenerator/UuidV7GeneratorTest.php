<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\IdGenerator;

use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidV7Generator::class)]
final class UuidV7GeneratorTest extends TestCase
{
    private UuidV7Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new UuidV7Generator();
    }

    #[Test]
    public function it_generates_valid_uuid_format(): void
    {
        $uuid = $this->generator->generate();

        // UUID format: 8-4-4-4-12 hex chars
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    #[Test]
    public function it_has_version_7(): void
    {
        $uuid = $this->generator->generate();
        $parts = explode('-', $uuid);

        // Version is the first nibble of the 3rd group
        self::assertSame('7', $parts[2][0]);
    }

    #[Test]
    public function it_has_correct_variant(): void
    {
        $uuid = $this->generator->generate();
        $parts = explode('-', $uuid);

        // Variant is the first nibble of the 4th group (8, 9, a, or b)
        $variantNibble = $parts[3][0];
        self::assertContains($variantNibble, ['8', '9', 'a', 'b']);
    }

    #[Test]
    public function it_generates_unique_ids(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $this->generator->generate();
        }

        $unique = array_unique($ids);
        self::assertCount(1000, $unique, 'All generated UUIDs should be unique');
    }

    #[Test]
    public function it_generates_time_ordered_ids(): void
    {
        $first = $this->generator->generate();
        usleep(1000); // 1ms
        $second = $this->generator->generate();

        // When comparing as strings, time-ordered UUIDs should sort correctly
        // The first 12 hex chars (before second dash) contain the timestamp
        $firstTimePart = str_replace('-', '', substr($first, 0, 13));
        $secondTimePart = str_replace('-', '', substr($second, 0, 13));

        self::assertLessThanOrEqual($secondTimePart, $firstTimePart);
    }

    #[Test]
    public function it_returns_36_character_string(): void
    {
        $uuid = $this->generator->generate();
        self::assertSame(36, strlen($uuid));
    }
}
