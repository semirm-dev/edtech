<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Filter\KeywordFilter;
use CourseDiscovery\Tests\Support\FilterContractTestCase;

final class KeywordFilterTest extends FilterContractTestCase
{
    protected function makeFilter(): Filter
    {
        return new KeywordFilter();
    }

    protected function validValues(): FilterValues
    {
        return FilterValues::fromStrings(['machine learning']);
    }

    public function test_it_is_a_text_input(): void
    {
        self::assertSame(FilterInputType::Text, (new KeywordFilter())->inputType());
    }

    public function test_it_offers_no_options(): void
    {
        self::assertTrue((new KeywordFilter())->options()->isEmpty());
    }

    public function test_a_non_blank_term_produces_a_search_text_constraint(): void
    {
        $constraint = (new KeywordFilter())->constrain(FilterValues::fromStrings(['machine learning']));

        self::assertInstanceOf(SearchTextConstraint::class, $constraint);
        self::assertSame('machine learning', $constraint->terms);
    }

    public function test_a_blank_or_whitespace_only_term_produces_no_constraint(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            self::assertNull(
                (new KeywordFilter())->constrain(FilterValues::fromStrings([$blank])),
                sprintf('A blank term (%s) must not produce a constraint.', json_encode($blank))
            );
        }
    }
}
