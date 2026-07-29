<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

final readonly class SinglePrice implements CoursePricing
{
    public function __construct(private Money $amount)
    {
    }

    public function format(): string
    {
        return $this->amount->format();
    }

    public function lowestMinor(): int
    {
        return $this->amount->minor;
    }
}
