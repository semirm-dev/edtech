<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Constraint;

/**
 * A declarative restriction on a course query.
 *
 * Constraints describe WHAT to restrict, never HOW. Only the where-clause builder in the
 * Query layer knows SQL exists, which is what keeps filters unit-testable
 * without a database and stops third-party filters injecting raw SQL by
 * default.
 */
interface Constraint
{
}
