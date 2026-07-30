<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\Domain\CourseCollection;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SearchResult;
use CourseDiscovery\Frontend\ResultsRenderer;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Pagination::clamp() only clamps a forged `?cd_paged=` against
 * MAX_PAGE (10000) -- it has no way to know this particular result set's
 * actual totalPages() until after the query has run (see its own
 * docblock), so a request like `?cd_paged=3` against a 5-result/12-per-page
 * search reaches ResultsRenderer with an honest total of 5 but a page that
 * does not exist. Before the fix, render() still printed "5 courses
 * found" and then an empty <ol> underneath it -- a positive count over a
 * bare empty list. This pins the presentation-only fix: an explicit
 * out-of-range message instead.
 */
final class ResultsRendererTest extends IntegrationTestCase
{
    public function test_a_page_past_the_end_with_results_present_does_not_render_a_bare_empty_list(): void
    {
        $result = new SearchResult(
            CourseCollection::empty(), // this page's slice: nothing at offset 24 of 5 rows
            5,
            new Pagination(3, 12) // page 3 of ceil(5/12) = 1 total page
        );

        $html = (new ResultsRenderer())->render($result, SearchCriteria::empty(), 'https://example.test/courses/');

        self::assertStringContainsString(
            '5 courses found',
            (new ResultsRenderer())->renderCount($result),
            'The accurate total must still be reported.'
        );
        self::assertStringNotContainsString(
            '<ol class="cd-results-list"></ol>',
            $html,
            'A positive course count must never be immediately followed by a bare empty list.'
        );
        self::assertStringContainsString(
            'cd-empty-state',
            $html,
            'An out-of-range page must render an explicit empty-state message.'
        );
    }

    public function test_a_valid_page_with_results_renders_the_list_not_the_out_of_range_message(): void
    {
        $result = new SearchResult(
            CourseCollection::empty(),
            5,
            new Pagination(1, 12)
        );

        $html = (new ResultsRenderer())->render($result, SearchCriteria::empty(), 'https://example.test/courses/');

        self::assertStringContainsString('<ol class="cd-results-list">', $html);
        self::assertStringNotContainsString(
            'This page is out of range',
            $html,
            'A page within range must not show the out-of-range message.'
        );
    }

    /**
     * Builds a result set with exactly $totalPages pages, so the pagination
     * assertions below can name a page count directly instead of computing
     * one from a course total.
     */
    private function renderWithPages(int $currentPage, int $totalPages): string
    {
        $perPage = 12;

        $result = new SearchResult(
            CourseCollection::empty(),
            $totalPages * $perPage,
            new Pagination($currentPage, $perPage)
        );

        return (new ResultsRenderer())->render(
            $result,
            SearchCriteria::empty(),
            'https://example.test/courses/'
        );
    }

    public function test_a_single_page_of_results_renders_no_pagination_at_all(): void
    {
        self::assertStringNotContainsString('cd-pagination', $this->renderWithPages(1, 1));
    }

    public function test_two_pages_link_both_of_them_with_no_ellipsis(): void
    {
        $html = $this->renderWithPages(1, 2);

        self::assertStringContainsString('aria-label="Page 1"', $html);
        self::assertStringContainsString('aria-label="Page 2"', $html);
        self::assertStringNotContainsString('cd-pagination-gap', $html);
    }

    public function test_the_first_page_of_twelve_links_only_its_window_and_the_last_page(): void
    {
        $html = $this->renderWithPages(1, 12);

        self::assertStringContainsString('aria-label="Page 1"', $html);
        self::assertStringContainsString('aria-label="Page 2"', $html);
        self::assertStringContainsString('aria-label="Page 12"', $html);
        self::assertStringNotContainsString(
            'aria-label="Page 5"',
            $html,
            'A page outside the window must not be linked -- that is the whole point of the window.'
        );
        self::assertStringContainsString('cd-pagination-gap', $html);
    }

    /**
     * Five is the largest page count the window covers completely: first,
     * last, current and its two neighbours ARE all five pages, so there is no
     * jump for an ellipsis to mark.
     */
    public function test_five_pages_all_fit_the_window_with_no_ellipsis(): void
    {
        $html = $this->renderWithPages(3, 5);

        foreach ([1, 2, 3, 4, 5] as $page) {
            self::assertStringContainsString('aria-label="Page ' . $page . '"', $html);
        }

        self::assertStringNotContainsString('cd-pagination-gap', $html);
    }

    /**
     * Six is therefore the first page count that needs one: page 5 is the
     * only page left out, between the window and the last page.
     */
    public function test_six_pages_is_the_smallest_set_that_needs_one_ellipsis(): void
    {
        $html = $this->renderWithPages(3, 6);

        foreach ([1, 2, 3, 4, 6] as $page) {
            self::assertStringContainsString('aria-label="Page ' . $page . '"', $html);
        }

        self::assertStringNotContainsString('aria-label="Page 5"', $html);
        self::assertSame(
            1,
            substr_count($html, 'cd-pagination-gap'),
            'One gap, on the far side of the window only -- page 1 is a window neighbour here.'
        );
    }

    /**
     * render()'s out-of-range branch renders pagination as well, and did so
     * with an unclamped current page: a forged `?cd_paged=9999` against a
     * three-page result set produced a Prev link to page 9998, itself out of
     * range, so the only offered way out of a forged page led to another one.
     *
     * Nothing exercised that branch before this test. The fixture in
     * test_a_page_past_the_end_... above has a single page, where
     * renderPagination() early-returns on `$totalPages <= 1` -- so the call
     * could be deleted from that branch entirely with the suite still green.
     */
    public function test_a_forged_page_past_a_multi_page_result_set_links_only_real_pages(): void
    {
        $html = $this->renderWithPages(9999, 3);

        self::assertStringContainsString('This page is out of range', $html);
        self::assertStringContainsString('cd-pagination', $html, 'The way back must be rendered at all.');

        $matched = preg_match('/<a class="cd-pagination-prev" href="([^"]+)"/', $html, $prev);

        self::assertSame(1, $matched, 'Expected a Previous page link off the forged page.');
        self::assertStringContainsString(
            'cd_paged=2',
            html_entity_decode($prev[1]),
            'Prev must step back from the LAST REAL page, not from the forged one.'
        );
        self::assertStringNotContainsString('cd_paged=9998', $html);
        self::assertStringNotContainsString(
            'rel="next"',
            $html,
            'Clamped to the last page, there is nowhere forward to go.'
        );

        foreach ([1, 2, 3] as $page) {
            self::assertStringContainsString('aria-label="Page ' . $page . '"', $html);
        }
    }

    public function test_a_middle_page_links_its_neighbours_and_both_ends(): void
    {
        $html = $this->renderWithPages(6, 12);

        foreach ([1, 5, 6, 7, 12] as $page) {
            self::assertStringContainsString(
                'aria-label="Page ' . $page . '"',
                $html,
                sprintf('Page %d belongs to the window for page 6 of 12.', $page)
            );
        }

        self::assertSame(
            2,
            substr_count($html, 'cd-pagination-gap'),
            'A middle page has a gap on each side of its window.'
        );
    }

    public function test_the_window_never_links_more_than_five_pages(): void
    {
        self::assertSame(
            5,
            substr_count($this->renderWithPages(20, 40), 'aria-label="Page '),
            'First, last, current and its two neighbours -- never more, however many pages exist.'
        );
    }

    public function test_previous_is_absent_on_the_first_page_and_next_on_the_last(): void
    {
        $first = $this->renderWithPages(1, 12);
        $last = $this->renderWithPages(12, 12);

        self::assertStringNotContainsString('rel="prev"', $first);
        self::assertStringContainsString('rel="next"', $first);

        self::assertStringContainsString('rel="prev"', $last);
        self::assertStringNotContainsString('rel="next"', $last);
    }

    public function test_the_current_page_is_still_a_link_and_carries_aria_current(): void
    {
        self::assertMatchesRegularExpression(
            '/<a href="[^"]*"[^>]*aria-current="page"[^>]*>6<\/a>/',
            $this->renderWithPages(6, 12),
            'The current page stays a link -- e2e addresses pages by their link role.'
        );
    }
}
