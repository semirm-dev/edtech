<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Domain\Course;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\SearchResult;

/**
 * Renders the results list, its empty state, and pagination.
 *
 * The count is rendered separately by renderCount() so Shortcode can place
 * it in the results toolbar beside the sort control. The empty state is an
 * explicit paragraph, not an absence of markup, so "no results" is itself
 * announced rather than looking broken.
 */
final class ResultsRenderer
{
    public function __construct(private readonly AttributeLabels $labels)
    {
    }

    /**
     * The result count, as its own live region.
     *
     * aria-live sits on this paragraph rather than on the results wrapper:
     * the count is the thing that changes and is worth announcing, and
     * aria-atomic on the wrapper would make a screen reader re-read every
     * result in the list. Nothing swaps results client-side today (there is
     * no fetch/XHR in course-discovery.js), so this is future-proofing that
     * costs nothing.
     */
    public function renderCount(SearchResult $result): string
    {
        return '<p class="cd-results-count" aria-live="polite" aria-atomic="true">'
            . esc_html($this->countMessage($result->total)) . '</p>';
    }

    /**
     * $baseUrl is the URL pagination links are built against via
     * add_query_arg($params, $baseUrl) -- never the implicit-base form, which
     * falls back to $_SERVER['REQUEST_URI']. Required rather than defaulted so
     * the caller always states which page the markup is destined for, instead
     * of the links silently inheriting whatever URL happened to be requested.
     */
    public function render(SearchResult $result, SearchCriteria $criteria, string $baseUrl): string
    {
        $total = $result->total;

        $html = '<div class="cd-results">';

        if ($total === 0) {
            $html .= '<p class="cd-empty-state">' . esc_html__(
                'No courses match your search. Try adjusting your filters.',
                'course-discovery'
            ) . '</p>';
        } elseif ($result->pagination->page > $result->totalPages()) {
            // Pagination::clamp() only clamps against MAX_PAGE, not this result
            // set's actual totalPages() (unknown until the query runs), so a
            // too-high page can reach here. Presentation-only: the count and
            // query are correct, only this page's render is out of range.
            $html .= '<p class="cd-empty-state">' . esc_html__(
                'This page is out of range. Try an earlier page.',
                'course-discovery'
            ) . '</p>';
            $html .= $this->renderPagination($result, $criteria, $baseUrl);
        } else {
            $html .= $this->renderList($result);
            $html .= $this->renderPagination($result, $criteria, $baseUrl);
        }

        $html .= '</div>';

        return $html;
    }

    private function countMessage(int $total): string
    {
        return sprintf(
            /* translators: %s: number of matching courses, already localised. */
            _n('%s course found', '%s courses found', $total, 'course-discovery'),
            number_format_i18n($total)
        );
    }

    private function renderList(SearchResult $result): string
    {
        // Resolved once for the whole page, not once per card -- see
        // AttributeLabels.
        $labels = $this->labels->forPage($result->courses);

        $html = '<ol class="cd-results-list">';

        foreach ($result->courses as $course) {
            $html .= $this->renderItem($course, $labels);
        }

        $html .= '</ol>';

        return $html;
    }

    private function renderItem(Course $course, LabelMap $labels): string
    {
        $permalink = get_permalink($course->id->value);
        $url = $permalink !== false ? $permalink : '';

        $html = '<li class="cd-result">';
        $html .= '<h3 class="cd-result-title"><a href="' . esc_url($url) . '">'
            . esc_html($course->title) . '</a></h3>';
        $html .= '<p class="cd-result-description">' . esc_html($course->shortDescription) . '</p>';
        $html .= $this->renderMeta($course, $labels);
        $html .= '<p class="cd-result-price">' . esc_html($course->pricing->format()) . '</p>';
        $html .= '<p class="cd-result-start">' . esc_html($this->startLabel($course)) . '</p>';
        $html .= '</li>';

        return $html;
    }

    /**
     * Who runs the course and where, e.g. "Coventry University · Coventry".
     *
     * Nothing renders when no provider name resolves: a course with no
     * provider indexed, or one whose provider rows have gone stale, gets no
     * meta line at all rather than an empty element or a placeholder. The
     * separator follows the same rule -- it only appears with a list on
     * each side of it, so a provider whose location term was deleted never
     * ends up with a dangling dot.
     *
     * The dot itself is aria-hidden: it is punctuation between two lists,
     * and a screen reader announcing "middle dot" on every card in the
     * results is noise.
     */
    private function renderMeta(Course $course, LabelMap $labels): string
    {
        $providers = $this->names($course->providerIds, fn (int $id): ?string => $labels->provider($id));

        if ($providers === []) {
            return '';
        }

        $locations = $this->names($course->locationIds, fn (int $id): ?string => $labels->location($id));

        $html = '<p class="cd-result-meta">';
        $html .= '<span class="cd-result-providers">' . esc_html(implode(', ', $providers)) . '</span>';

        if ($locations !== []) {
            $html .= '<span class="cd-result-meta-sep" aria-hidden="true"> &middot; </span>';
            $html .= '<span class="cd-result-locations">' . esc_html(implode(', ', $locations)) . '</span>';
        }

        $html .= '</p>';

        return $html;
    }

    /**
     * Ids that resolve, in the order the course carries them -- which the
     * attribute lookup already returns sorted, so the line is stable
     * between renders. An id that resolves to null is dropped.
     *
     * @param  list<int>                $ids
     * @param  callable(int): ?string   $resolve
     * @return list<string>
     */
    private function names(array $ids, callable $resolve): array
    {
        $names = [];

        foreach ($ids as $id) {
            $name = $resolve($id);

            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function startLabel(Course $course): string
    {
        $earliest = $course->startDates->earliest();

        if ($earliest === null) {
            return __('Start date to be confirmed', 'course-discovery');
        }

        return sprintf(
            /* translators: %s: localised month and year, e.g. "March 2026". */
            __('Starts %s', 'course-discovery'),
            StartDates::formatLocalised($earliest->sortKey())
        );
    }

    private function renderPagination(SearchResult $result, SearchCriteria $criteria, string $baseUrl): string
    {
        $totalPages = $result->totalPages();

        if ($totalPages <= 1) {
            return '';
        }

        // Clamped because render()'s out-of-range branch calls this with a
        // page past the end (a forged ?cd_paged=9999): unclamped, Prev pointed
        // at 9998, itself out of range, so the only way out of a forged page
        // led to another one. Clamping to the last real page makes Prev the
        // last page of results and drops Next, which is where the visitor
        // wanted to be. pageWindow() already discards pages > $totalPages.
        $currentPage = min($result->pagination->page, $totalPages);

        $html = '<nav class="cd-pagination" aria-label="' . esc_attr__('Pagination', 'course-discovery') . '">';

        if ($currentPage > 1) {
            $html .= $this->renderRelativeLink(
                $criteria,
                $currentPage - 1,
                $baseUrl,
                'prev',
                __('Previous page', 'course-discovery'),
                __('‹ Prev', 'course-discovery')
            );
        }

        $previous = null;

        foreach ($this->pageWindow($currentPage, $totalPages) as $page) {
            if ($previous !== null && $page - $previous > 1) {
                $html .= '<span class="cd-pagination-gap" aria-hidden="true">&hellip;</span>';
            }

            $html .= $this->renderPageLink($criteria, $page, $currentPage, $baseUrl);
            $previous = $page;
        }

        if ($currentPage < $totalPages) {
            $html .= $this->renderRelativeLink(
                $criteria,
                $currentPage + 1,
                $baseUrl,
                'next',
                __('Next page', 'course-discovery'),
                __('Next ›', 'course-discovery')
            );
        }

        $html .= '</nav>';

        return $html;
    }

    /**
     * The pages worth linking: the first, the last, the current one and its
     * immediate neighbours -- at most five, however many pages exist. The
     * previous behaviour linked every page, so a 400-course result set
     * emitted 40 links.
     *
     * Returned ascending and deduplicated, which is what lets the caller
     * detect a jump between consecutive entries and insert one ellipsis
     * there.
     *
     * @return list<int>
     */
    private function pageWindow(int $currentPage, int $totalPages): array
    {
        $pages = [];

        foreach ([1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages] as $page) {
            if ($page >= 1 && $page <= $totalPages) {
                $pages[$page] = true;
            }
        }

        $window = array_keys($pages);
        sort($window);

        return $window;
    }

    /**
     * Prev/Next. Kept apart from renderPageLink() because these carry a rel
     * attribute and a directional accessible name, and are never the
     * current page.
     */
    private function renderRelativeLink(
        SearchCriteria $criteria,
        int $page,
        string $baseUrl,
        string $rel,
        string $accessibleName,
        string $visibleLabel
    ): string {
        $url = add_query_arg($this->pageParams($criteria, $page), $baseUrl);

        return '<a class="cd-pagination-' . esc_attr($rel) . '" href="' . esc_url($url)
            . '" rel="' . esc_attr($rel) . '" aria-label="' . esc_attr($accessibleName) . '">'
            . esc_html($visibleLabel) . '</a>';
    }

    private function renderPageLink(SearchCriteria $criteria, int $page, int $currentPage, string $baseUrl): string
    {
        $url = add_query_arg($this->pageParams($criteria, $page), $baseUrl);
        $isCurrent = $page === $currentPage;

        $label = sprintf(
            /* translators: %d: page number. */
            __('Page %d', 'course-discovery'),
            $page
        );

        return '<a href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '"'
            . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html((string) $page) . '</a>';
    }

    /**
     * Shared by every link in the nav so the "page 1 omits the parameter"
     * rule lives in exactly one place.
     *
     * @return array<string, string|list<string>>
     */
    private function pageParams(SearchCriteria $criteria, int $page): array
    {
        $params = $criteria->toQueryParams();

        if ($page > 1) {
            $params[SearchCriteria::PARAM_PAGE] = (string) $page;
        } else {
            unset($params[SearchCriteria::PARAM_PAGE]);
        }

        return $params;
    }
}
