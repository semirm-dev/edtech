<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\CourseCollection;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function test_total_pages_is_zero_for_zero_results(): void
    {
        $result = new SearchResult(CourseCollection::empty(), 0, Pagination::default());

        self::assertSame(0, $result->totalPages());
    }

    public function test_total_pages_when_total_divides_exactly_by_per_page(): void
    {
        $result = new SearchResult(CourseCollection::empty(), 24, new Pagination(1, 12));

        self::assertSame(2, $result->totalPages());
    }

    public function test_total_pages_rounds_up_when_total_leaves_a_remainder(): void
    {
        $result = new SearchResult(CourseCollection::empty(), 25, new Pagination(1, 12));

        self::assertSame(3, $result->totalPages());
    }

    public function test_it_round_trips_its_constructor_arguments(): void
    {
        $courses = CourseCollection::empty();
        $pagination = new Pagination(2, 12);

        $result = new SearchResult($courses, 25, $pagination);

        self::assertSame(25, $result->total);
        self::assertSame($courses, $result->courses);
        self::assertSame($pagination, $result->pagination);
    }
}
