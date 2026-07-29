<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\ContentModel;

use CourseDiscovery\ContentModel\StartDates;
use PHPUnit\Framework\TestCase;

final class StartDatesTest extends TestCase
{
    public function test_it_parses_numeric_month_year(): void
    {
        self::assertSame(202603, StartDates::parse('03-2026'));
    }

    public function test_it_parses_month_names(): void
    {
        self::assertSame(202603, StartDates::parse('March-2026'));
        self::assertSame(202601, StartDates::parse('january-2026'));
    }

    public function test_it_rejects_malformed_input(): void
    {
        self::assertNull(StartDates::parse('not a date'));
        self::assertNull(StartDates::parse('13-2026'));
        self::assertNull(StartDates::parse('00-2026'));
        self::assertNull(StartDates::parse(''));
    }

    public function test_it_formats_for_display(): void
    {
        self::assertSame('March 2026', StartDates::format(202603));
    }

    public function test_it_formats_display_boundaries(): void
    {
        self::assertSame('January 2026', StartDates::format(202601));
        self::assertSame('December 2026', StartDates::format(202612));
    }

    public function test_it_returns_empty_string_for_out_of_range_format(): void
    {
        self::assertSame('', StartDates::format(202600));
        self::assertSame('', StartDates::format(202613));
        self::assertSame('', StartDates::format(-1));
    }

    /**
     * The formatters delegate their validity rules to Domain\StartDate, which
     * bounds the YEAR as well as the month. A sort key like 199912 is
     * well-formed as an integer but could only have reached storage by
     * bypassing parse() (which rejects it), so it degrades to '' rather than
     * rendering a date the system never accepted as valid.
     */
    public function test_it_returns_empty_string_for_a_year_outside_the_domains_range(): void
    {
        self::assertNull(StartDates::parse('12-1999'));

        self::assertSame('', StartDates::format(199912));
        self::assertSame('', StartDates::format(210112));
        self::assertSame('', StartDates::formatForInput(199912));
    }

    public function test_it_round_trips_via_format_for_input(): void
    {
        foreach ([202601, 202603, 202612] as $key) {
            self::assertSame($key, StartDates::parse(StartDates::formatForInput($key)));
        }
    }

    public function test_it_formats_for_input_as_hyphenated_zero_padded_form(): void
    {
        self::assertSame('03-2026', StartDates::formatForInput(202603));
        self::assertSame('12-2026', StartDates::formatForInput(202612));
    }

    public function test_it_returns_empty_string_for_out_of_range_format_for_input(): void
    {
        self::assertSame('', StartDates::formatForInput(202600));
        self::assertSame('', StartDates::formatForInput(202613));
        self::assertSame('', StartDates::formatForInput(-1));
    }

    public function test_it_rejects_years_outside_bounds(): void
    {
        self::assertNull(StartDates::parse('03-0000'));
        self::assertNull(StartDates::parse('03-0026'));
        self::assertNull(StartDates::parse('03-2101'));
    }

    public function test_it_accepts_years_at_bounds(): void
    {
        self::assertSame(200003, StartDates::parse('03-2000'));
        self::assertSame(210003, StartDates::parse('03-2100'));
    }

    public function test_it_sorts_chronologically_not_alphabetically(): void
    {
        $normalised = StartDates::normaliseList(['March-2026', 'January-2026', 'April-2026']);

        self::assertSame([202601, 202603, 202604], $normalised);
    }

    public function test_it_removes_duplicates(): void
    {
        self::assertSame([202603], StartDates::normaliseList(['03-2026', 'March-2026']));
    }

    public function test_it_dedupes_before_sorting(): void
    {
        $normalised = StartDates::normaliseList(['April-2026', 'January-2026', 'April-2026']);

        self::assertSame([202601, 202604], $normalised);
    }

    public function test_it_discards_invalid_entries_from_a_list(): void
    {
        self::assertSame([202603], StartDates::normaliseList(['03-2026', 'rubbish', '']));
    }
}
