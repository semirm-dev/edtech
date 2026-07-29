<?php

declare(strict_types=1);

namespace CourseDiscovery\Query;

use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SearchResult;
use CourseDiscovery\Domain\SortOrder;

/**
 * Reading courses. The signature mentions no WordPress type, so an
 * alternative implementation (Elasticsearch, a test fake) can be substituted
 * without touching callers.
 */
interface CourseRepository
{
    public function search(ConstraintSet $constraints, Pagination $pagination, SortOrder $orderBy): SearchResult;

    /**
     * Distinct value ids currently present for an attribute — the raw material for
     * building filter option lists.
     *
     * @return list<int>
     */
    public function attributeValues(string $attribute): array;
}
