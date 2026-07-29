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
 * Pagination::clamp() only clamps a forged `?paged=` against
 * MAX_PAGE (10000) -- it has no way to know this particular result set's
 * actual totalPages() until after the query has run (see its own
 * docblock), so a request like `?paged=3` against a 5-result/12-per-page
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
            $html,
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
}
