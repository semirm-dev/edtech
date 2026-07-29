<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

/**
 * The aggregate root.
 *
 * Location ids are present but not authored — they are derived from the
 * course's providers during indexing. Keeping them read-only here means the
 * derivation rule lives in exactly one place.
 */
final readonly class Course
{
    /**
     * @param list<int> $providerIds
     * @param list<int> $instructorIds
     * @param list<int> $categoryIds
     * @param list<int> $locationIds
     *
     * NOTE: these four id-list params are structurally identical, so
     * PHPStan can't catch a transposition. Call sites should use named
     * arguments (providerIds: ..., etc.) so a swap is visible in the diff.
     */
    public function __construct(
        public CourseId $id,
        public string $title,
        public string $shortDescription,
        public string $longDescription,
        public CoursePricing $pricing,
        public StartDateCollection $startDates,
        public array $providerIds,
        public array $instructorIds,
        public array $categoryIds,
        public array $locationIds,
    ) {
    }
}
