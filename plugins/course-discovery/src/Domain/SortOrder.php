<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

/**
 * How search results are ordered.
 *
 * A backed enum in the domain layer, not string constants on the
 * WordPress adapter, so SearchCriteria can name a sort order with no
 * WordPress dependency while still round-tripping via ->value / ::from().
 * The string values must not change — they're already serialised into
 * shared URLs. Only WpCourseRepository (infrastructure) may map these to
 * SQL — see its private orderSql().
 */
enum SortOrder: string
{
    case Soonest = 'soonest';
    case PriceAscending = 'price_asc';
    case Title = 'title';
}
