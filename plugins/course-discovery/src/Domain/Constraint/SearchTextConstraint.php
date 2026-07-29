<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Constraint;

use InvalidArgumentException;

final readonly class SearchTextConstraint implements Constraint
{
    public string $terms;

    public function __construct(string $terms)
    {
        $trimmed = trim($terms);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Search text cannot be empty; omit the constraint instead.');
        }

        $this->terms = $trimmed;
    }
}
