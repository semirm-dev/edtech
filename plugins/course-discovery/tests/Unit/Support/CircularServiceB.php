<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Support;

/**
 * Fixture for ContainerTest's indirect-cycle case: B depends back on A (see
 * CircularServiceA), so resolving either one recurses into the other
 * forever unless Container::get() detects the cycle.
 */
final class CircularServiceB
{
    public function __construct(public readonly CircularServiceA $a)
    {
    }
}
