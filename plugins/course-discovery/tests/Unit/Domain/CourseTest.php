<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\Course;
use CourseDiscovery\Domain\CourseCollection;
use CourseDiscovery\Domain\CourseId;
use CourseDiscovery\Domain\Money;
use CourseDiscovery\Domain\SinglePrice;
use CourseDiscovery\Domain\StartDate;
use CourseDiscovery\Domain\StartDateCollection;
use PHPUnit\Framework\TestCase;

final class CourseTest extends TestCase
{
    private function makeCourse(int $id = 1): Course
    {
        return new Course(
            CourseId::fromInt($id),
            'Graphic Design Foundation',
            'Short description.',
            'Long description.',
            new SinglePrice(Money::fromMinor(95000, 'GBP')),
            StartDateCollection::fromSortKeys([202603, 202601]),
            [12, 47],
            [101],
            [8],
            [3, 9],
        );
    }

    public function test_it_exposes_its_data(): void
    {
        $course = $this->makeCourse();

        self::assertSame('Graphic Design Foundation', $course->title);
        self::assertSame('£950.00', $course->pricing->format());

        // Four distinct, non-overlapping id sets: a transposition of any
        // two of these (e.g. instructorIds <-> categoryIds) must fail here.
        self::assertSame([12, 47], $course->providerIds);
        self::assertSame([101], $course->instructorIds);
        self::assertSame([8], $course->categoryIds);
        self::assertSame([3, 9], $course->locationIds);
    }

    public function test_start_dates_are_chronological_regardless_of_input_order(): void
    {
        $course = $this->makeCourse();

        self::assertSame([202601, 202603], $course->startDates->toSortKeys());
    }

    public function test_it_reports_its_earliest_start_date(): void
    {
        $earliest = $this->makeCourse()->startDates->earliest();

        self::assertInstanceOf(StartDate::class, $earliest);
        self::assertSame(202601, $earliest->sortKey());
    }

    public function test_a_course_with_no_dates_has_no_earliest(): void
    {
        self::assertNull(StartDateCollection::fromSortKeys([])->earliest());
    }

    public function test_collection_is_countable_and_iterable(): void
    {
        $collection = CourseCollection::fromArray([$this->makeCourse(1), $this->makeCourse(2)]);

        self::assertCount(2, $collection);

        $seen = [];

        foreach ($collection as $course) {
            $seen[] = $course->id->value;
        }

        self::assertSame([1, 2], $seen);
    }

    public function test_from_sort_keys_deduplicates_repeated_keys(): void
    {
        $dates = StartDateCollection::fromSortKeys([202603, 202601, 202603]);

        self::assertSame([202601, 202603], $dates->toSortKeys());
    }

    public function test_empty_collection_reports_itself_as_empty(): void
    {
        $collection = CourseCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertCount(0, $collection);
    }

    public function test_non_empty_collection_reports_itself_as_not_empty(): void
    {
        $collection = CourseCollection::fromArray([$this->makeCourse()]);

        self::assertFalse($collection->isEmpty());
    }
}
