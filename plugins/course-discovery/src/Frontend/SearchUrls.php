<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\FilterRegistry;

/**
 * URL arithmetic for the search UI: which query keys this plugin owns, and
 * where "clear everything" points.
 *
 * Extracted from FormRenderer because ActiveFiltersRenderer needs the same
 * two answers for its "Clear all" link. Duplicating them would let the
 * form's idea of "params we own" drift from the chips'.
 */
final class SearchUrls
{
    /**
     * The keys SearchCriteria and the registry already model explicitly:
     * anything else present in the current request (e.g. `page_id` on a
     * plain-permalinks site) is "non-filter" state the form must not drop.
     *
     * @return list<string>
     */
    public function knownKeys(FilterRegistry $registry): array
    {
        $keys = [
            SearchCriteria::PARAM_TERM,
            SearchCriteria::PARAM_SORT,
            SearchCriteria::PARAM_PAGE,
        ];

        foreach ($registry->keys() as $key) {
            $keys[] = $key->queryParam();
        }

        return $keys;
    }

    /**
     * Built with remove_query_arg() rather than from scratch, so params this
     * plugin does not own survive the reset -- on a plain-permalinks site
     * $baseUrl is itself `?page_id=42`, which the link must keep.
     *
     * $baseUrl is required for the same reason ResultsRenderer::render()
     * requires one (see its docblock): remove_query_arg()/add_query_arg()
     * called with no URL fall back to $_SERVER['REQUEST_URI'], so the link
     * silently inherits whatever URL happened to be requested instead of the
     * page the markup is destined for. Taking it as an argument makes the
     * caller state which page that is -- and makes the result assertable,
     * which the implicit form is not (wp-phpunit leaves REQUEST_URI empty, so
     * every "no filter params in the href" assertion held vacuously).
     */
    public function clearFilters(FilterRegistry $registry, string $baseUrl): string
    {
        return remove_query_arg($this->knownKeys($registry), $baseUrl);
    }
}
