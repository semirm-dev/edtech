<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter;

use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\StartDate;
use CourseDiscovery\Index\Attribute;
use CourseDiscovery\Query\CourseRepository;
use InvalidArgumentException;

/**
 * Start dates offered as a chronological dropdown.
 *
 * Options come from the index rather than a taxonomy, because start dates
 * are course meta. Sorting is by the integer YYYYMM key, so chronological
 * order is structural — sorting the display strings would put April before
 * January.
 *
 * Months earlier than the current one are omitted: a course that has
 * already started is not discoverable in any useful sense (assumption A6).
 * The rule is presentation-only — the rows stay in the index — so it can be
 * reversed through the filter_options hook.
 */
final class StartDateFilter implements Filter
{
    public const KEY = 'start_date';

    public function __construct(private readonly CourseRepository $repository)
    {
    }

    public function key(): FilterKey
    {
        return FilterKey::fromString(self::KEY);
    }

    public function label(): string
    {
        return __('Start date', 'course-discovery');
    }

    public function inputType(): FilterInputType
    {
        return FilterInputType::ComboboxMulti;
    }

    public function description(): ?string
    {
        return null;
    }

    public function options(?SearchCriteria $context = null): FilterOptions
    {
        $keys = $this->repository->attributeValues(Attribute::Start->value);

        // What month it "is now" is genuinely timezone-relative -- unlike
        // formatLocalised()'s bare month/year values, "now" IS an instant,
        // so it should be read through the SITE's configured timezone via
        // current_time() rather than gmdate()'s UTC clock. Near a month
        // boundary the two can disagree about which month it currently is.
        $currentMonth = (int) current_time('Ym');

        $upcoming = array_values(array_filter(
            $keys,
            static fn (int $key): bool => $key >= $currentMonth
        ));

        sort($upcoming);

        $options = [];

        foreach ($upcoming as $key) {
            try {
                // formatLocalised(), not Domain\StartDate::toDisplay() —
                // the domain object's month names are canonical English and
                // cannot call WordPress. This dropdown is user-facing.
                StartDate::fromSortKey($key); // validates the key, throws if malformed
                $options[] = new FilterOption((string) $key, StartDates::formatLocalised($key));
            } catch (InvalidArgumentException) {
                // A malformed key in the index is not worth failing a page over.
                continue;
            }
        }

        return FilterOptionsHook::apply(self::KEY, FilterOptions::fromArray($options));
    }

    public function constrain(FilterValues $values): ?Constraint
    {
        $valid = [];

        foreach ($values->toInts() as $candidate) {
            try {
                $valid[] = StartDate::fromSortKey($candidate)->sortKey();
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $valid === [] ? null : new AttributeInConstraint(Attribute::Start->value, $valid);
    }
}
