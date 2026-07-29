<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\Pagination;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function test_it_rejects_a_per_page_of_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(1, 0);
    }

    public function test_it_rejects_a_per_page_above_the_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(1, 101);
    }

    public function test_it_accepts_a_per_page_of_one(): void
    {
        self::assertSame(1, (new Pagination(1, 1))->perPage);
    }

    public function test_it_accepts_a_per_page_at_the_maximum(): void
    {
        self::assertSame(100, (new Pagination(1, 100))->perPage);
    }

    public function test_it_rejects_a_page_above_the_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(10001, 20);
    }

    public function test_it_accepts_a_page_at_the_maximum(): void
    {
        self::assertSame(10000, (new Pagination(10000, 20))->page);
    }

    public function test_it_rejects_a_saturated_int_cast_page(): void
    {
        // (int) "99999999999999999999" saturates to PHP_INT_MAX for an
        // untrusted URL query parameter. Without an upper bound this
        // constructs successfully and offset() overflows to a float,
        // producing a TypeError at the int return boundary instead of a
        // clean, user-facing validation error.
        $this->expectException(InvalidArgumentException::class);

        new Pagination(PHP_INT_MAX, 12);
    }

    public function test_offset_stays_within_int_range_at_the_maximum_bounds(): void
    {
        self::assertSame(999900, (new Pagination(10000, 100))->offset());
    }

    // Moved here from CourseIdTest: these exercise Pagination,
    // not CourseId, and belong alongside every other Pagination test rather
    // than scattered in an unrelated test class.

    public function test_it_computes_offset(): void
    {
        self::assertSame(0, (new Pagination(1, 20))->offset());
        self::assertSame(40, (new Pagination(3, 20))->offset());
    }

    public function test_it_rejects_a_page_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(0, 20);
    }

    public function test_it_has_a_sane_default(): void
    {
        $default = Pagination::default();

        self::assertSame(1, $default->page);
        self::assertSame(12, $default->perPage);
    }

    // clamp() is the untrusted-input entry point, so it must
    // never throw -- it must instead pull an out-of-range value back into
    // range.

    public function test_clamp_raises_a_page_of_zero_to_one(): void
    {
        self::assertSame(1, Pagination::clamp(0, 12)->page);
    }

    public function test_clamp_raises_a_negative_page_to_one(): void
    {
        self::assertSame(1, Pagination::clamp(-5, 12)->page);
    }

    /**
     * (int) casting an arbitrarily long digit string -- exactly what an
     * untrusted `?paged=` parameter can contain -- saturates to
     * PHP_INT_MAX rather than erroring. clamp() must pull that back down to
     * the same MAX_PAGE the throwing constructor enforces, not fatal.
     */
    public function test_clamp_lowers_a_saturated_int_cast_page_to_the_maximum(): void
    {
        self::assertSame(10000, Pagination::clamp(PHP_INT_MAX, 12)->page);
    }

    public function test_clamp_raises_a_per_page_of_zero_to_one(): void
    {
        self::assertSame(1, Pagination::clamp(1, 0)->perPage);
    }

    public function test_clamp_lowers_a_per_page_above_the_maximum_to_the_maximum(): void
    {
        self::assertSame(100, Pagination::clamp(1, 500)->perPage);
    }

    public function test_clamp_passes_through_in_range_values_unchanged(): void
    {
        $pagination = Pagination::clamp(3, 24);

        self::assertSame(3, $pagination->page);
        self::assertSame(24, $pagination->perPage);
    }

    public function test_the_strict_constructor_still_throws_for_invalid_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(0, 12);
    }
}
