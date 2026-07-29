<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * `final class` with a readonly property rather than `final readonly class`:
 * this matches the other IteratorAggregate collections in the codebase
 * (StartDateCollection, CourseCollection), which use the same form. Its
 * single-value siblings in this namespace (FilterKey, FilterOption,
 * FilterValues) are `final readonly class` because they wrap one scalar
 * identity; a collection's precedent is the other collections, not those.
 *
 * @implements IteratorAggregate<int, FilterOption>
 */
final class FilterOptions implements IteratorAggregate, Countable
{
    /**
     * @param list<FilterOption> $options
     */
    private function __construct(private readonly array $options)
    {
    }

    /**
     * @param list<FilterOption> $options
     */
    public static function fromArray(array $options): self
    {
        return new self($options);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    /**
     * @return Traversable<int, FilterOption>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->options);
    }

    public function count(): int
    {
        return count($this->options);
    }
}
