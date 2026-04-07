<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\IdGenerator;

use PhpOutbox\Outbox\IdGenerator\UlidGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UlidGenerator::class)]
final class UlidGeneratorTest extends TestCase
{
    private UlidGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UlidGenerator();
    }

    #[Test]
    public function it_generates_26_character_string(): void
    {
        $ulid = $this->generator->generate();
        self::assertSame(26, strlen($ulid));
    }

    #[Test]
    public function it_uses_crockford_base32_characters(): void
    {
        $ulid = $this->generator->generate();

        // Crockford Base32 valid chars: 0-9, A-H, J-K, M-N, P-T, V-X, Z
        // (excludes I, L, O, U)
        self::assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            $ulid,
        );
    }

    #[Test]
    public function it_generates_unique_ids(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $this->generator->generate();
        }

        $unique = array_unique($ids);
        self::assertCount(1000, $unique, 'All generated ULIDs should be unique');
    }

    #[Test]
    public function it_generates_lexicographically_sortable_ids(): void
    {
        $first = $this->generator->generate();
        usleep(2000); // 2ms to ensure timestamp difference
        $second = $this->generator->generate();

        // ULID timestamp portion (first 10 chars) should be sortable
        $firstTime = substr($first, 0, 10);
        $secondTime = substr($second, 0, 10);

        self::assertLessThanOrEqual($secondTime, $firstTime);
    }
}
