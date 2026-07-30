<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Frontend;

use CourseDiscovery\Frontend\Shortcode;
use PHPUnit\Framework\TestCase;

/**
 * The `per_page` attribute is editor input: whoever can edit the page
 * carrying [course_discovery] chooses it, and a typo must not be able to
 * ask the database for an unbounded page of courses.
 *
 * Parsing it is a pure function of that string, so it is pinned here
 * directly rather than through 50-odd seeded courses in an integration
 * test. Null means "not specified" -- the caller then uses the default page
 * size, which is NOT the same as clamping to the minimum: "abc" is a
 * mistake, and answering it with one course per page would be a far more
 * confusing outcome than falling back to the usual twelve.
 */
final class ShortcodePerPageTest extends TestCase
{
    public function test_a_value_in_range_is_taken_as_given(): void
    {
        self::assertSame(24, Shortcode::perPageFrom('24'));
        self::assertSame(1, Shortcode::perPageFrom('1'));
        self::assertSame(50, Shortcode::perPageFrom('50'));
    }

    public function test_an_absent_or_empty_attribute_means_the_default(): void
    {
        self::assertNull(Shortcode::perPageFrom(''));
        self::assertNull(Shortcode::perPageFrom('   '));
        self::assertNull(Shortcode::perPageFrom(null));
    }

    public function test_a_non_numeric_value_falls_back_to_the_default(): void
    {
        self::assertNull(Shortcode::perPageFrom('abc'));
        self::assertNull(Shortcode::perPageFrom('twelve'));
        self::assertNull(Shortcode::perPageFrom([]));
    }

    public function test_a_value_below_the_minimum_is_clamped_to_one(): void
    {
        self::assertSame(1, Shortcode::perPageFrom('0'));
        self::assertSame(1, Shortcode::perPageFrom('-5'));
    }

    public function test_a_value_above_the_maximum_is_clamped_to_fifty(): void
    {
        self::assertSame(50, Shortcode::perPageFrom('51'));
        self::assertSame(50, Shortcode::perPageFrom('9999'));
    }

    /**
     * (int) on a digit string longer than PHP_INT_MAX saturates rather than
     * erroring, and scientific notation is is_numeric() too -- both must
     * land on the maximum, not on something the query layer has to deal
     * with.
     */
    public function test_absurd_but_numeric_input_still_lands_in_range(): void
    {
        self::assertSame(50, Shortcode::perPageFrom('999999999999999999999999'));
        self::assertSame(50, Shortcode::perPageFrom('1e6'));
        self::assertSame(1, Shortcode::perPageFrom('-999999999999999999999999'));
    }

    public function test_a_fractional_value_truncates_before_clamping(): void
    {
        self::assertSame(12, Shortcode::perPageFrom('12.7'));
        self::assertSame(1, Shortcode::perPageFrom('0.5'));
    }
}
