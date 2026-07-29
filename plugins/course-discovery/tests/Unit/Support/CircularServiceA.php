<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Support;

/**
 * Fixture for ContainerTest's indirect-cycle case: A depends on B, and (see
 * CircularServiceB) B depends back on A.
 */
final class CircularServiceA
{
    public function __construct(public readonly CircularServiceB $b)
    {
    }
}
