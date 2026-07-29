<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

final readonly class SearchResult
{
    public function __construct(
        public CourseCollection $courses,
        public int $total,
        public Pagination $pagination,
    ) {
    }

    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->pagination->perPage);
    }
}
