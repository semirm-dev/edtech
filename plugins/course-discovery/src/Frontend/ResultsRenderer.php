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
 * The outer wrapper carries `aria-live="polite"` and `aria-atomic="true"` so
 * a screen reader announces the new result count whenever this markup is
 * swapped in. The empty state is an explicit paragraph, not an absence of
 * markup, so "no results" is itself announced rather than looking broken.
 */
final class ResultsRenderer
{
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

        $html = '<div class="cd-results" aria-live="polite" aria-atomic="true">';
        $html .= '<p class="cd-results-count">' . esc_html($this->countMessage($total)) . '</p>';

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
        $html = '<ol class="cd-results-list">';

        foreach ($result->courses as $course) {
            $html .= $this->renderItem($course);
        }

        $html .= '</ol>';

        return $html;
    }

    private function renderItem(Course $course): string
    {
        $permalink = get_permalink($course->id->value);
        $url = $permalink !== false ? $permalink : '';

        $html = '<li class="cd-result">';
        $html .= '<h3 class="cd-result-title"><a href="' . esc_url($url) . '">'
            . esc_html($course->title) . '</a></h3>';
        $html .= '<p class="cd-result-description">' . esc_html($course->shortDescription) . '</p>';
        $html .= '<p class="cd-result-price">' . esc_html($course->pricing->format()) . '</p>';
        $html .= '<p class="cd-result-start">' . esc_html($this->startLabel($course)) . '</p>';
        $html .= '</li>';

        return $html;
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

        $currentPage = $result->pagination->page;

        $html = '<nav class="cd-pagination" aria-label="' . esc_attr__('Pagination', 'course-discovery') . '">';

        for ($page = 1; $page <= $totalPages; $page++) {
            $html .= $this->renderPageLink($criteria, $page, $currentPage, $baseUrl);
        }

        $html .= '</nav>';

        return $html;
    }

    private function renderPageLink(SearchCriteria $criteria, int $page, int $currentPage, string $baseUrl): string
    {
        $params = $criteria->toQueryParams();

        if ($page > 1) {
            $params[SearchCriteria::PARAM_PAGE] = (string) $page;
        } else {
            unset($params[SearchCriteria::PARAM_PAGE]);
        }

        $url = add_query_arg($params, $baseUrl);
        $isCurrent = $page === $currentPage;

        $label = sprintf(
            /* translators: %d: page number. */
            __('Page %d', 'course-discovery'),
            $page
        );

        return '<a href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '"'
            . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html((string) $page) . '</a>';
    }
}
