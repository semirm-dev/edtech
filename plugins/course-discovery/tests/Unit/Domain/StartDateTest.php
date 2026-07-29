<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Domain\StartDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StartDateTest extends TestCase
{
    public function test_it_round_trips_a_sort_key(): void
    {
        $date = StartDate::fromSortKey(202603);

        self::assertSame(202603, $date->sortKey());
        self::assertSame('March 2026', $date->toDisplay());
        self::assertSame('03-2026', $date->toInputValue());
    }

    public function test_it_builds_from_year_and_month(): void
    {
        self::assertSame(202601, StartDate::fromYearMonth(2026, 1)->sortKey());
    }

    public function test_it_parses_a_numeric_month_input(): void
    {
        self::assertSame(202603, StartDate::tryFromInput('03-2026')?->sortKey());
        self::assertSame(202603, StartDate::tryFromInput('3-2026')?->sortKey());
    }

    public function test_it_parses_a_named_month_input_in_any_case(): void
    {
        self::assertSame(202603, StartDate::tryFromInput('March-2026')?->sortKey());
        self::assertSame(202603, StartDate::tryFromInput('march-2026')?->sortKey());
        self::assertSame(202612, StartDate::tryFromInput('DECEMBER-2026')?->sortKey());
    }

    /**
     * tryFromInput() is the one non-throwing constructor: it is the entry point
     * for untrusted user input, so a malformed string must be an ordinary null
     * rather than an exception that would fatal an admin save.
     */
    public function test_it_returns_null_for_input_it_cannot_accept(): void
    {
        self::assertNull(StartDate::tryFromInput('not a date'));
        self::assertNull(StartDate::tryFromInput(''));
        self::assertNull(StartDate::tryFromInput('13-2026'));     // impossible month
        self::assertNull(StartDate::tryFromInput('00-2026'));     // impossible month
        self::assertNull(StartDate::tryFromInput('Marmalade-2026')); // not a month
        self::assertNull(StartDate::tryFromInput('03-1999'));     // year below range
        self::assertNull(StartDate::tryFromInput('03-2101'));     // year above range
    }

    /**
     * The class holds two month maps (number→name for rendering, name→number
     * for parsing) as deliberate inverses. Nothing structurally forces them to
     * agree, so this pins all twelve against drift: the name comes out of the
     * rendering map and must parse back through the other one to the same
     * month.
     */
    public function test_the_two_month_maps_are_inverses_for_every_month(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $name = explode(' ', StartDate::fromYearMonth(2026, $month)->toDisplay())[0];

            self::assertSame(
                $month,
                StartDate::tryFromInput($name . '-2026')?->month,
                sprintf('Month %d renders as "%s" but does not parse back.', $month, $name)
            );
        }
    }

    /**
     * The whole reason parsing lives here: this class defines "03-2026" when
     * writing it, so it must also define it when reading it back.
     */
    public function test_input_parsing_is_the_exact_inverse_of_to_input_value(): void
    {
        foreach ([200001, 202603, 202612, 210012] as $sortKey) {
            $written = StartDate::fromSortKey($sortKey)->toInputValue();

            self::assertSame($sortKey, StartDate::tryFromInput($written)?->sortKey());
        }
    }

    public function test_it_rejects_an_impossible_month(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StartDate::fromYearMonth(2026, 13);
    }

    public function test_it_rejects_a_malformed_sort_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StartDate::fromSortKey(202600);
    }

    public function test_equality_is_by_value(): void
    {
        self::assertTrue(StartDate::fromSortKey(202603)->equals(StartDate::fromSortKey(202603)));
        self::assertFalse(StartDate::fromSortKey(202603)->equals(StartDate::fromSortKey(202604)));
    }

    public function test_it_rejects_a_year_below_the_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StartDate::fromYearMonth(1999, 1);
    }

    public function test_it_rejects_a_year_above_the_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StartDate::fromYearMonth(2101, 1);
    }

    public function test_it_accepts_a_year_at_the_minimum(): void
    {
        self::assertSame(200001, StartDate::fromYearMonth(2000, 1)->sortKey());
    }

    public function test_it_accepts_a_year_at_the_maximum(): void
    {
        self::assertSame(210001, StartDate::fromYearMonth(2100, 1)->sortKey());
    }

    public function test_it_rejects_a_zero_sort_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StartDate::fromSortKey(0);
    }

    /**
     * Domain\StartDate::toInputValue() produces the string that
     * ContentModel\StartDates::parse() must be able to read back. Nothing
     * else pins this interop, so drift in either class's format would
     * otherwise break it silently.
     */
    public function test_to_input_value_round_trips_through_content_model_start_dates_parse(): void
    {
        foreach ([200001, 202603, 210012] as $sortKey) {
            $startDate = StartDate::fromSortKey($sortKey);

            self::assertSame($startDate->sortKey(), StartDates::parse($startDate->toInputValue()));
        }
    }
}
