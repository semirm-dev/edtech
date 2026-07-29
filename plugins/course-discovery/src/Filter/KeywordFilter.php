<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;

/**
 * Free-text search across name, short and long description.
 *
 * Modelled as an ordinary filter so the pipeline stays uniform and
 * register_filters works for text-style filters too. The trade-off is that
 * options() is always empty; that is documented rather than special-cased.
 *
 * Its key is deliberately the same string as SearchCriteria::PARAM_TERM.
 * SearchCriteria::fromQueryParams() skips that key when reading filter
 * values, so the term is parsed exactly once and this filter is what turns
 * it into a constraint. Changing either constant without the other would
 * either double-apply the search or drop it entirely.
 */
final class KeywordFilter implements Filter
{
    public const KEY = 'q';

    public function key(): FilterKey
    {
        return FilterKey::fromString(self::KEY);
    }

    public function label(): string
    {
        return __('Search', 'course-discovery');
    }

    public function inputType(): FilterInputType
    {
        return FilterInputType::Text;
    }

    public function description(): string
    {
        return __('Matches course name, short and long description.', 'course-discovery');
    }

    public function options(?SearchCriteria $context = null): FilterOptions
    {
        return FilterOptions::empty();
    }

    public function constrain(FilterValues $values): ?Constraint
    {
        $terms = trim(implode(' ', $values->toStrings()));

        return $terms === '' ? null : new SearchTextConstraint($terms);
    }
}
