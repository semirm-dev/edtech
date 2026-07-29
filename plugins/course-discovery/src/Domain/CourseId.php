<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use InvalidArgumentException;

final readonly class CourseId
{
    /**
     * @param positive-int $value
     */
    private function __construct(public int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('Course id must be positive, got %d.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
