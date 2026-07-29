<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter;

use CourseDiscovery\Domain\Filter\FilterOptions;

/**
 * Applies the per-filter options hook.
 *
 * Extracted so every filter exposes the seam identically and none can
 * forget it. Third parties use it to add, remove or reorder the choices a
 * filter offers.
 */
final class FilterOptionsHook
{
    public static function apply(string $key, FilterOptions $options): FilterOptions
    {
        /** @var mixed $filtered */
        $filtered = apply_filters('course_discovery/filter_options/' . $key, $options, $key);

        return $filtered instanceof FilterOptions ? $filtered : $options;
    }
}
