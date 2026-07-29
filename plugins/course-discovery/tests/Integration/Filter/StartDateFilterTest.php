<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Filter\StartDateFilter;
use CourseDiscovery\Tests\Support\FilterContractTestCase;

final class StartDateFilterTest extends FilterContractTestCase
{
    protected function makeFilter(): Filter
    {
        $currentMonth = (int) gmdate('Ym');

        // Future keys, deliberately out of chronological order, so that
        // options() is non-empty under the real clock and the inherited
        // "advertised option is actionable" contract test in
        // FilterContractTestCase actually runs its assertion instead of
        // skipping (see test_contract_options_are_consistent_with_input_type).
        return new StartDateFilter($this->fakeRepository([
            self::shiftYearMonth($currentMonth, 3),
            self::shiftYearMonth($currentMonth, 1),
            self::shiftYearMonth($currentMonth, 2),
        ]));
    }

    protected function validValues(): FilterValues
    {
        return FilterValues::fromInts([self::shiftYearMonth((int) gmdate('Ym'), 1)]);
    }

    /**
     * @param list<int> $sortKeys
     */
    private function fakeRepository(array $sortKeys): \CourseDiscovery\Query\CourseRepository
    {
        return new class ($sortKeys) implements \CourseDiscovery\Query\CourseRepository {
            /**
             * @param list<int> $sortKeys
             */
            public function __construct(private readonly array $sortKeys)
            {
            }

            public function search(
                \CourseDiscovery\Domain\Constraint\ConstraintSet $constraints,
                \CourseDiscovery\Domain\Pagination $pagination,
                \CourseDiscovery\Domain\SortOrder $orderBy
            ): \CourseDiscovery\Domain\SearchResult {
                return new \CourseDiscovery\Domain\SearchResult(
                    \CourseDiscovery\Domain\CourseCollection::empty(),
                    0,
                    $pagination
                );
            }

            /**
             * @return list<int>
             */
            public function attributeValues(string $attribute): array
            {
                return $this->sortKeys;
            }
        };
    }

    /**
     * Shifts a YYYYMM sort key by a (possibly negative) number of months,
     * correctly rolling the year over in both directions (...12 + 1 =>
     * ...01 of the next year; ...01 - 1 => ...12 of the previous year).
     *
     * Every test in this class that needs a "future" or "past" fixture
     * month computes it through here, relative to the real clock, instead
     * of hardcoding a year -- see test_options_include_current_and_next_
     * month_but_exclude_last_month for a check that this rollover itself
     * is correct.
     */
    private static function shiftYearMonth(int $yearMonth, int $months): int
    {
        $year = intdiv($yearMonth, 100);
        $month = $yearMonth % 100;

        $zeroBasedMonth = ($month - 1) + $months;
        $year += intdiv($zeroBasedMonth, 12);
        $month = $zeroBasedMonth % 12;

        if ($month < 0) {
            $month += 12;
            $year--;
        }

        return $year * 100 + ($month + 1);
    }

    public function test_it_is_a_combobox(): void
    {
        self::assertSame(FilterInputType::ComboboxMulti, $this->makeFilter()->inputType());
    }

    public function test_options_are_chronological_not_alphabetical(): void
    {
        $currentMonth = (int) gmdate('Ym');

        // now+1 and now+13 always name the same month a year apart (13 =
        // 1 + 12), so alphabetical order groups them together by that
        // shared month name and orders them by the year suffix, while
        // chronological order interleaves now+2 between them. Since now+2
        // is always a different month name to now+1 (consecutive months
        // can never share a name), the two orderings are guaranteed to
        // diverge for ANY current month -- e.g. with "now" in July, this
        // gives August 2026 / September 2026 / August 2027, where
        // alphabetical order is [Aug 2026, Aug 2027, Sep 2026] but
        // chronological order is [Aug 2026, Sep 2026, Aug 2027].
        $first = self::shiftYearMonth($currentMonth, 1);
        $second = self::shiftYearMonth($currentMonth, 2);
        $third = self::shiftYearMonth($currentMonth, 13);

        // Fed to the fake out of chronological order on purpose.
        $filter = new StartDateFilter($this->fakeRepository([$third, $first, $second]));

        $labels = [];

        foreach ($filter->options() as $option) {
            $labels[] = $option->label;
        }

        // Expected labels are derived from the same keys via the
        // production formatter, so this assertion cannot drift from
        // StartDates::formatLocalised()'s own behaviour.
        self::assertSame(
            [
                StartDates::formatLocalised($first),
                StartDates::formatLocalised($second),
                StartDates::formatLocalised($third),
            ],
            $labels,
            'Options must be ordered by the chronological integer sort key, not the display label.'
        );
    }

    public function test_options_include_current_and_next_month_but_exclude_last_month(): void
    {
        // Pin the helper's own rollover in both directions, independent of
        // whatever the real current month happens to be.
        self::assertSame(202701, self::shiftYearMonth(202612, 1), 'December + 1 month must roll into next January.');
        self::assertSame(202512, self::shiftYearMonth(202601, -1), 'January - 1 month must roll into previous December.');

        $currentMonth = (int) gmdate('Ym');
        $nextMonth = self::shiftYearMonth($currentMonth, 1);
        $lastMonth = self::shiftYearMonth($currentMonth, -1);

        $filter = new StartDateFilter($this->fakeRepository([$lastMonth, $currentMonth, $nextMonth]));

        $values = [];

        foreach ($filter->options() as $option) {
            $values[] = $option->value;
        }

        self::assertContains(
            (string) $currentMonth,
            $values,
            'A course starting this month has not necessarily started yet.'
        );
        self::assertContains((string) $nextMonth, $values);
        self::assertNotContains(
            (string) $lastMonth,
            $values,
            'A course that started last month is not discoverable.'
        );
    }

    public function test_options_exclude_months_already_past(): void
    {
        // No `?: time()` fallback after strtotime(): PHPStan at level 9 with
        // treatPhpDocTypesAsCertain narrows strtotime()'s return type to plain
        // int for a literal, valid relative-format string, making the fallback
        // dead code (ternary.alwaysTrue). Dropping it is preferable to adding
        // an ignore; behaviour for these two fixed format strings is
        // unchanged.
        $past = (int) gmdate('Ym', strtotime('-2 months'));
        $future = (int) gmdate('Ym', strtotime('+2 months'));

        $filter = new StartDateFilter($this->fakeRepository([$past, $future]));

        $values = [];

        foreach ($filter->options() as $option) {
            $values[] = $option->value;
        }

        self::assertNotContains((string) $past, $values, 'A course that already started is not discoverable.');
        self::assertContains((string) $future, $values);
    }

    public function test_a_malformed_index_key_is_skipped_not_thrown(): void
    {
        $currentMonth = (int) gmdate('Ym');
        $nextMonth = self::shiftYearMonth($currentMonth, 1);

        // 999999 parses as year 9999, month 99 -- month out of range, so
        // StartDate::fromSortKey() throws and options()'s catch must skip
        // it rather than let the whole build fail.
        $filter = new StartDateFilter($this->fakeRepository([999999, $currentMonth, $nextMonth]));

        $values = [];

        foreach ($filter->options() as $option) {
            $values[] = $option->value;
        }

        self::assertContains((string) $currentMonth, $values);
        self::assertContains((string) $nextMonth, $values);
        self::assertNotContains('999999', $values);
    }

    public function test_it_constrains_on_the_start_attribute(): void
    {
        $constraint = $this->makeFilter()->constrain(FilterValues::fromInts([202603]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('start', $constraint->attribute);
    }

    public function test_it_discards_a_malformed_sort_key(): void
    {
        self::assertNull(
            $this->makeFilter()->constrain(FilterValues::fromStrings(['999999', '0'])),
            'A value that is not a valid YYYYMM must not reach the query.'
        );
    }
}
