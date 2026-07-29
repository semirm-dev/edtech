<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

use Countable;

/**
 * The values a user selected for one filter.
 *
 * Stored as strings because that is what arrives from a URL; integer access
 * is offered for the common attribute case. Order is preserved and duplicates
 * removed, so the same selection always produces the same query.
 */
final readonly class FilterValues implements Countable
{
    /**
     * @param list<string> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param list<string> $values
     */
    public static function fromStrings(array $values): self
    {
        return new self(array_values(array_unique($values)));
    }

    /**
     * @param list<int> $values
     */
    public static function fromInts(array $values): self
    {
        return self::fromStrings(array_map(strval(...), $values));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<string>
     */
    public function toStrings(): array
    {
        return $this->values;
    }

    /**
     * Non-numeric entries are discarded rather than coerced to 0, and a
     * value that overflows PHP_INT_MAX is discarded rather than silently
     * saturated by the (int) cast — both would otherwise produce a
     * wrong-looking match instead of dropping the value. Leading zeros
     * (e.g. "012") are still accepted as 12.
     *
     * @return list<int>
     */
    public function toInts(): array
    {
        $ints = [];

        foreach ($this->values as $value) {
            if (!ctype_digit($value)) {
                continue;
            }

            $int = (int) $value;

            if ($int <= 0) {
                continue;
            }

            if ((string) $int !== ltrim($value, '0')) {
                continue;
            }

            $ints[] = $int;
        }

        return $ints;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function count(): int
    {
        return count($this->values);
    }
}
