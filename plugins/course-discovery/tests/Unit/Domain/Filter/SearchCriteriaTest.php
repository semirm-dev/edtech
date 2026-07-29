<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain\Filter;

use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SortOrder;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaTest extends TestCase
{
    private function providerKey(): FilterKey
    {
        return FilterKey::fromString('provider');
    }

    public function test_it_starts_empty(): void
    {
        $criteria = SearchCriteria::empty();

        self::assertNull($criteria->term);
        self::assertSame([], $criteria->activeFilterKeys());
        self::assertSame(SortOrder::Soonest, $criteria->sort);
    }

    public function test_mutations_return_new_instances(): void
    {
        $first = SearchCriteria::empty();
        $second = $first->withTerm('design');

        self::assertNull($first->term, 'withTerm() must not mutate the original.');
        self::assertSame('design', $second->term);
    }

    public function test_a_blank_term_is_normalised_to_null(): void
    {
        self::assertNull(SearchCriteria::empty()->withTerm('   ')->term);
        self::assertNull(SearchCriteria::empty()->withTerm('')->term);
    }

    public function test_adding_an_empty_value_set_does_not_activate_a_filter(): void
    {
        $criteria = SearchCriteria::empty()->withFilter($this->providerKey(), FilterValues::empty());

        self::assertSame([], $criteria->activeFilterKeys());
    }

    public function test_replacing_an_active_filter_with_an_empty_value_set_removes_it(): void
    {
        // Unlike the test above, this starts from a filter that IS active,
        // so an implementation that only ever adds to the map (never
        // unset()s) would leave a stale, empty entry here and this would
        // catch it where the fresh-instance version cannot.
        $criteria = SearchCriteria::empty()
            ->withFilter($this->providerKey(), FilterValues::fromInts([12]))
            ->withFilter($this->providerKey(), FilterValues::empty());

        self::assertSame([], $criteria->activeFilterKeys());
    }

    public function test_values_for_an_unset_filter_are_empty_not_null(): void
    {
        self::assertTrue(SearchCriteria::empty()->valuesFor($this->providerKey())->isEmpty());
    }

    public function test_it_serialises_to_query_params(): void
    {
        $criteria = SearchCriteria::empty()
            ->withTerm('graphic design')
            ->withFilter($this->providerKey(), FilterValues::fromInts([12, 47]))
            ->withPagination(new Pagination(2, 12));

        $params = $criteria->toQueryParams();

        self::assertSame('graphic design', $params['q']);
        self::assertSame(['12', '47'], $params['provider']);
        self::assertSame('2', $params['paged']);
    }

    public function test_it_omits_defaults_from_query_params(): void
    {
        $params = SearchCriteria::empty()->toQueryParams();

        self::assertArrayNotHasKey('q', $params, 'An empty search must not appear in the URL.');
        self::assertArrayNotHasKey('paged', $params, 'Page 1 is the default and must be omitted.');
        self::assertArrayNotHasKey('sort', $params);
    }

    public function test_it_round_trips_through_query_params(): void
    {
        $known = [$this->providerKey(), FilterKey::fromString('location')];

        $original = SearchCriteria::empty()
            ->withTerm('design')
            ->withFilter($this->providerKey(), FilterValues::fromInts([12, 47]))
            ->withSort(SortOrder::PriceAscending)
            ->withPagination(new Pagination(3, 12));

        $restored = SearchCriteria::fromQueryParams($original->toQueryParams(), $known);

        self::assertSame('design', $restored->term);
        self::assertSame([12, 47], $restored->valuesFor($this->providerKey())->toInts());
        self::assertSame(SortOrder::PriceAscending, $restored->sort);
        self::assertSame(3, $restored->pagination->page);
    }

    public function test_with_filter_on_the_term_key_sets_the_term_instead_of_the_filter_map(): void
    {
        // KeywordFilter's key IS SearchCriteria::PARAM_TERM ('q'). If
        // withFilter() wrote that into the filter map, it would silently
        // overwrite whatever withTerm() had set instead of agreeing with it.
        $criteria = SearchCriteria::empty()
            ->withTerm('design')
            ->withFilter(FilterKey::fromString(SearchCriteria::PARAM_TERM), FilterValues::fromStrings(['alpha']));

        self::assertSame('alpha', $criteria->term);
        self::assertSame([], $criteria->activeFilterKeys(), 'The term key must never appear in the filter map.');
    }

    public function test_with_filter_on_the_term_key_with_empty_values_clears_the_term(): void
    {
        $criteria = SearchCriteria::empty()
            ->withTerm('design')
            ->withFilter(FilterKey::fromString(SearchCriteria::PARAM_TERM), FilterValues::empty());

        self::assertNull($criteria->term);
    }

    public function test_with_filter_on_the_term_key_round_trips_through_query_params(): void
    {
        $termKey = FilterKey::fromString(SearchCriteria::PARAM_TERM);

        $original = SearchCriteria::empty()->withFilter($termKey, FilterValues::fromStrings(['alpha']));

        $restored = SearchCriteria::fromQueryParams($original->toQueryParams(), [$termKey]);

        self::assertSame('alpha', $restored->term);
        self::assertSame([], $restored->activeFilterKeys());
    }

    public function test_it_ignores_query_params_for_unknown_filters(): void
    {
        $criteria = SearchCriteria::fromQueryParams(
            ['provider' => ['12'], 'evil' => ['99']],
            [$this->providerKey()]
        );

        self::assertSame(['provider'], $criteria->activeFilterKeys());
    }

    public function test_it_clamps_a_hostile_page_number(): void
    {
        foreach (['0', '-5', 'abc', '99999999999999999999', '1.9', '', '0x10'] as $hostile) {
            $criteria = SearchCriteria::fromQueryParams(['paged' => $hostile], []);

            self::assertGreaterThanOrEqual(1, $criteria->pagination->page);
            self::assertLessThanOrEqual(10000, $criteria->pagination->page);
        }
    }

    public function test_it_falls_back_to_the_default_sort_for_an_unknown_value(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['sort' => 'nonsense'], []);

        self::assertSame(SortOrder::Soonest, $criteria->sort);
    }

    public function test_the_term_parameter_is_not_also_read_as_a_filter(): void
    {
        // KeywordFilter's key is 'q', the same as the term parameter. If
        // fromQueryParams() read it as both, the text constraint would be
        // applied twice.
        $criteria = SearchCriteria::fromQueryParams(
            ['q' => 'design'],
            [FilterKey::fromString('q'), $this->providerKey()]
        );

        self::assertSame('design', $criteria->term);
        self::assertSame([], $criteria->activeFilterKeys(), 'The term must not also register as a filter.');
    }

    public function test_it_accepts_a_scalar_where_a_list_is_expected(): void
    {
        // ?provider=12 rather than ?provider[]=12
        $criteria = SearchCriteria::fromQueryParams(['provider' => '12'], [$this->providerKey()]);

        self::assertSame([12], $criteria->valuesFor($this->providerKey())->toInts());
    }

    public function test_page_size_is_never_serialised_into_query_params(): void
    {
        $params = SearchCriteria::empty()
            ->withPagination(new Pagination(3, 48))
            ->toQueryParams();

        // If 48 leaked into the params, this would fail: 'paged' is the only
        // key present, and its value carries the page number, not the size.
        self::assertSame(['paged' => '3'], $params);
    }

    public function test_a_caller_supplied_page_size_is_honoured(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['paged' => '2'], [], 48);

        self::assertSame(48, $criteria->pagination->perPage);
    }

    public function test_a_url_supplied_page_size_is_ignored_even_under_a_plausible_key(): void
    {
        // There is no page-size query parameter at all. Even if a caller's
        // $_GET happens to contain something that looks like one, it must
        // not override the value the caller explicitly passed in.
        $criteria = SearchCriteria::fromQueryParams(
            ['paged' => '2', 'perPage' => '999', 'per_page' => '999'],
            [],
            48
        );

        self::assertSame(48, $criteria->pagination->perPage);
    }

    public function test_an_omitted_page_size_falls_back_to_the_default(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['paged' => '2'], []);

        self::assertSame(Pagination::default()->perPage, $criteria->pagination->perPage);
    }

    public function test_an_overlong_term_is_truncated(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['q' => str_repeat('a', 200_000)], []);

        self::assertSame(str_repeat('a', 200), $criteria->term);
    }

    public function test_a_filter_with_too_many_values_is_capped(): void
    {
        // Without a cap this would carry all 5,000 values through to an IN
        // clause with 5,000 placeholders.
        $many = array_map(strval(...), range(1, 5000));

        $criteria = SearchCriteria::fromQueryParams(['provider' => $many], [$this->providerKey()]);

        self::assertSame(range(1, 50), $criteria->valuesFor($this->providerKey())->toInts());
    }

    public function test_the_term_zero_survives_round_tripping(): void
    {
        // Regression guard: '0' is falsy in PHP. A refactor from
        // "$this->term !== null" to "if ($this->term)" would silently drop
        // a search for the digit "0" here.
        $criteria = SearchCriteria::empty()->withTerm('0');

        self::assertSame('0', $criteria->term);

        $restored = SearchCriteria::fromQueryParams($criteria->toQueryParams(), []);

        self::assertSame('0', $restored->term);
    }

    public function test_a_non_scalar_term_does_not_throw_and_leaves_the_term_unset(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['q' => ['a']], []);

        self::assertNull($criteria->term);
    }

    public function test_a_non_scalar_page_does_not_throw_and_falls_back_to_page_one(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['paged' => ['a']], []);

        self::assertSame(1, $criteria->pagination->page);
    }

    public function test_a_non_scalar_sort_does_not_throw_and_falls_back_to_the_default(): void
    {
        $criteria = SearchCriteria::fromQueryParams(['sort' => ['x']], []);

        self::assertSame(SortOrder::Soonest, $criteria->sort);
    }

    public function test_hostile_scalar_garbage_in_a_filter_list_does_not_throw_and_is_discarded(): void
    {
        $criteria = SearchCriteria::fromQueryParams(
            ['provider' => [null, false, 1.5, new \stdClass()]],
            [$this->providerKey()]
        );

        self::assertSame([], $criteria->activeFilterKeys(), 'None of these values are valid filter values.');
    }

    public function test_a_deeply_nested_array_in_a_filter_list_does_not_throw(): void
    {
        $criteria = SearchCriteria::fromQueryParams(
            ['provider' => [['nested' => ['deeply' => ['array']]]]],
            [$this->providerKey()]
        );

        self::assertSame([], $criteria->activeFilterKeys());
    }
}
