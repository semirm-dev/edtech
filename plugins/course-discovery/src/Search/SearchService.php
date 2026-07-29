<?php

declare(strict_types=1);

namespace CourseDiscovery\Search;

use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\SearchResult;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Query\CourseRepository;

/**
 * Turns a request into results.
 *
 * The pipeline, with its documented extension points:
 *
 *   query params
 *     -> SearchCriteria::fromQueryParams()   each filter's own key only
 *     -> [course_discovery/criteria]         transform search criteria
 *     -> Filter::constrain() per active filter
 *     -> [course_discovery/constraints]      modify filter queries
 *     -> repository -> [course_discovery/order]
 *     -> SearchResult
 *
 * Hook returns are type-checked: a third-party handler returning the wrong
 * thing degrades to the unmodified value rather than fatalling a page a
 * visitor is looking at.
 */
final readonly class SearchService
{
    public const HOOK_CRITERIA = 'course_discovery/criteria';
    public const HOOK_CONSTRAINTS = 'course_discovery/constraints';

    public function __construct(
        public FilterRegistry $registry,
        private CourseRepository $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function criteriaFromParams(array $params): SearchCriteria
    {
        return SearchCriteria::fromQueryParams($params, $this->registry->keys());
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        /** @var mixed $filtered */
        $filtered = apply_filters(self::HOOK_CRITERIA, $criteria);
        $criteria = $filtered instanceof SearchCriteria ? $filtered : $criteria;

        $set = ConstraintSet::empty();

        foreach ($this->registry->all() as $filter) {
            $values = $criteria->valuesFor($filter->key());

            if ($values->isEmpty()) {
                continue;
            }

            $constraint = $filter->constrain($values);

            if ($constraint !== null) {
                $set = $set->add($constraint);
            }
        }

        /** @var mixed $filteredSet */
        $filteredSet = apply_filters(self::HOOK_CONSTRAINTS, $set, $criteria);
        $set = $filteredSet instanceof ConstraintSet ? $filteredSet : $set;

        return $this->repository->search($set, $criteria->pagination, $criteria->sort);
    }
}
