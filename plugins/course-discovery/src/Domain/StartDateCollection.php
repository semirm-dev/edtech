<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Start dates, unique and chronologically ordered by construction.
 *
 * Concrete and final rather than extending a shared TypedCollection base:
 * composition over inheritance, and a base class here would save a few lines
 * at the cost of coupling every collection to it.
 *
 * @implements IteratorAggregate<int, StartDate>
 */
final class StartDateCollection implements IteratorAggregate, Countable
{
    /**
     * @param list<StartDate> $dates
     */
    private function __construct(private readonly array $dates)
    {
    }

    /**
     * @param list<int> $sortKeys
     */
    public static function fromSortKeys(array $sortKeys): self
    {
        $unique = array_values(array_unique($sortKeys));
        sort($unique);

        return new self(array_map(
            static fn (int $key): StartDate => StartDate::fromSortKey($key),
            $unique
        ));
    }

    public function earliest(): ?StartDate
    {
        return $this->dates[0] ?? null;
    }

    /**
     * @return list<int>
     */
    public function toSortKeys(): array
    {
        return array_map(static fn (StartDate $d): int => $d->sortKey(), $this->dates);
    }

    /**
     * @return Traversable<int, StartDate>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->dates);
    }

    public function count(): int
    {
        return count($this->dates);
    }
}
