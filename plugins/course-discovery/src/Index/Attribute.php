<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

/**
 * The dimensions a course can be filtered on.
 *
 * A single attribute table keyed by this enum, rather than one table per
 * dimension: a third-party filter can then store its own rows without a
 * schema migration, which is what makes the system extensible without
 * modification.
 */
enum Attribute: string
{
    case Provider = 'provider';
    case Location = 'location';
    case Start = 'start';
    case Category = 'category';
}
