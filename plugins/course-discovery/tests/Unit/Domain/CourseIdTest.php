<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\CourseId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CourseIdTest extends TestCase
{
    public function test_it_wraps_a_positive_integer(): void
    {
        self::assertSame(42, CourseId::fromInt(42)->value);
    }

    public function test_it_rejects_a_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CourseId::fromInt(0);
    }

    public function test_equality_is_by_value(): void
    {
        self::assertTrue(CourseId::fromInt(7)->equals(CourseId::fromInt(7)));
        self::assertFalse(CourseId::fromInt(7)->equals(CourseId::fromInt(8)));
    }
}
