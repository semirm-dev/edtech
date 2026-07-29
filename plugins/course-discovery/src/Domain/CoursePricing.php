<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

/**
 * How a course is priced.
 *
 * An interface with a single implementation, deliberately: pricing is
 * expected to grow a range or multiple price points, and adding a PriceRange
 * later is then a new class rather than a breaking change to Course and every
 * consumer. Ranges are not implemented because nothing currently filters on
 * price.
 */
interface CoursePricing
{
    public function format(): string;

    /**
     * The lowest amount in minor units — the sortable, filterable scalar.
     */
    public function lowestMinor(): int;
}
