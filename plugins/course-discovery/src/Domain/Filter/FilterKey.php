<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

use InvalidArgumentException;

/**
 * A filter's identity.
 *
 * Constrained to [a-z][a-z0-9_]*, at most 64 characters, at construction
 * because the same string becomes a URL query parameter, an HTML name
 * attribute and part of a hook name. Validating once here means none of
 * those has to re-sanitise it. The pattern anchors on `\z` rather than `$`
 * so a trailing newline cannot sneak a value past the check.
 */
final readonly class FilterKey
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Filter key must match [a-z][a-z0-9_]* and be at most 64 characters, got "%s".',
                    $value
                )
            );
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function queryParam(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
