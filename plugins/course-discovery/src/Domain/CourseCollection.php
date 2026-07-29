<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Course>
 */
final class CourseCollection implements IteratorAggregate, Countable
{
    /**
     * @param list<Course> $courses
     */
    private function __construct(private readonly array $courses)
    {
    }

    /**
     * @param list<Course> $courses
     */
    public static function fromArray(array $courses): self
    {
        return new self($courses);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return Traversable<int, Course>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->courses);
    }

    public function count(): int
    {
        return count($this->courses);
    }

    public function isEmpty(): bool
    {
        return $this->courses === [];
    }
}
