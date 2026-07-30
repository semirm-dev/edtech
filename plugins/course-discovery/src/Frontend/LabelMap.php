<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

/**
 * Display names for the provider and location ids on one page of results.
 *
 * Two id spaces, deliberately kept apart: providers are post ids and
 * locations are term ids, and those are independent auto-increment
 * sequences that can coincide numerically. A single merged map would let a
 * provider id resolve to a location's name.
 *
 * A missing id answers null rather than throwing or returning the id back
 * as a string. The attribute lookup is a projection and can go stale
 * relative to wp_posts and the term tables, so an id arriving here may name
 * something unpublished or deleted -- the caller drops it, exactly as the
 * applied-filter chips drop a stale value instead of rendering it raw.
 */
final readonly class LabelMap
{
    /**
     * @param array<int, string> $providers
     * @param array<int, string> $locations
     */
    public function __construct(private array $providers, private array $locations)
    {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function provider(int $id): ?string
    {
        return $this->providers[$id] ?? null;
    }

    public function location(int $id): ?string
    {
        return $this->locations[$id] ?? null;
    }
}
