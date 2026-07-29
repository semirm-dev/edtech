<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

final readonly class FilterOption
{
    public function __construct(
        public string $value,
        public string $label,
    ) {
    }
}
