# Find Courses Frontend Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the `[course_discovery]` UI and close the three UX gaps CSS cannot — no sort control, pagination that prints every page, and no active-filter summary.

**Architecture:** The shortcode's wrapper `<div>` becomes the `<form>`, so CSS grid can place four regions (hero search, chips, filter panel, results) while every control still submits together. Presentation stays in `src/Frontend/`; `src/Domain/` is not touched. Chip removal URLs are built by round-tripping through `SearchCriteria`'s own `withFilter()`/`toQueryParams()` rather than editing query strings by hand.

**Tech Stack:** PHP 8.3, WordPress shortcode + `<form method="get">`, vanilla ES5-style JS (no build step), hand-written CSS with custom properties, PHPUnit 11 via wp-phpunit, PHPStan level 9, Playwright for e2e.

**Spec:** [docs/specs/2026-07-29-find-courses-frontend-design.md](../specs/2026-07-29-find-courses-frontend-design.md)

## Global Constraints

- **Never run `php`, `composer`, `wp`, or `mysql` on the host.** Always `ddev composer …`, `ddev wp …`, `ddev exec …`.
- **Edit only under `plugins/`.** The paths under `wp/wp-content/plugins/` are bind-mount points and are empty on the host.
- **Plugin PHP is 4-space indented, PSR-12-ish**, PSR-4 `PascalCase` under `plugins/course-discovery/src/`. Not WordPress-core style — no tabs, no Yoda conditions. **CSS and JS in `assets/` are tab-indented** — match each file.
- **`src/Domain/` must contain no WordPress.** No `$wpdb`, hooks, `WP_*`, and no `__()`/`esc_*`. `tests/Architecture/DomainPurityTest.php` enforces this.
- **Escape on output** (`esc_html`, `esc_attr`, `esc_url`), sanitize on input, `$wpdb->prepare()` for SQL.
- **Prefix everything public:** classes under `CourseDiscovery\`, CSS classes `cd-`, query params owned by `SearchCriteria::PARAM_*`.
- **PHPStan level 9 must report `No errors`.** Every array shape needs a docblock; no `mixed` leaks.
- **Judge `ddev composer test` by assertion counts and the absence of `FAILURES!` / `ERRORS!`.** The integration and architecture suites end with `OK, but there were issues!` — that is PHPUnit deprecation noise from Yoast Polyfills' doc-comment annotations, not a failure.
- **No schema, index, or option changes anywhere in this plan.** `course-discovery reindex` is never required.
- Text domain is always `'course-discovery'`.

## File Structure

| File | Responsibility after this plan |
| --- | --- |
| `src/Frontend/Shortcode.php` | Emits the `<form>` wrapper and composes the four regions. Owns no markup for controls or results. |
| `src/Frontend/FormRenderer.php` | Renders the hero search, the `<details>` filter panel, and the sort control. No longer emits `<form>`. |
| `src/Frontend/ResultsRenderer.php` | Renders the count (separately), the list, the empty/out-of-range states, and a condensed pagination window. |
| `src/Frontend/ActiveFiltersRenderer.php` | **New.** Applied-filter chips, their removal URLs, and the chip count. |
| `src/Frontend/SearchUrls.php` | **New.** Which query keys the plugin owns, and the "clear all" URL. Shared by `FormRenderer` and `ActiveFiltersRenderer`. |
| `assets/course-discovery.css` | Rewritten: token block, grid layout, all visual design. |
| `assets/course-discovery.js` | Existing combobox upgrade, plus sort auto-submit and the small-screen `<details>` collapse. |
| `src/Plugin.php` | Wiring only — constructs the two new classes. |

`SearchUrls` is an addition to the spec's six-file list. The spec moves "Clear all" out of `FormRenderer` into the chips block, which would have duplicated `knownQueryKeys()`/`clearFiltersUrl()` across two renderers. Extracting them into one small collaborator instead keeps a single source for "which params does this plugin own".

## Task Order Rationale

Structure lands before features, so nothing is rendered in a temporary home and then relocated. Every task ends with the full suite green.

1. Pagination window — isolated inside `ResultsRenderer`.
2. Structural recomposition — the `<form>` wrapper, hero/panel split, toolbar with the count.
3. Sort control — drops into the toolbar built in Task 2.
4. `SearchUrls` extraction — pure refactor, no behaviour change.
5. Active-filter chips — needs Task 4's collaborator.
6. CSS rewrite — needs the final markup from Tasks 2–5.
7. Small-screen collapse + e2e — needs the final CSS and markup.

---

### Task 1: Condensed pagination window

**Files:**
- Modify: `plugins/course-discovery/src/Frontend/ResultsRenderer.php:114-156`
- Test: `plugins/course-discovery/tests/Integration/Frontend/ResultsRendererTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: no new public API. `ResultsRenderer::render(SearchResult $result, SearchCriteria $criteria, string $baseUrl): string` keeps its signature; only the pagination markup inside it changes.

- [ ] **Step 1: Write the failing tests**

Append these methods to `ResultsRendererTest`, inside the existing class body. Add `use CourseDiscovery\Domain\SortOrder;` to the imports only if you need it — these tests do not.

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
ddev composer test:integration
```

Expected: `FAILURES!` naming the seven new tests. `test_a_single_page_of_results_renders_no_pagination_at_all` passes already (the `$totalPages <= 1` early return exists); the rest fail on missing `aria-label="Page N"` / `cd-pagination-gap` / `rel="prev"` markup, because today's links use `aria-label="Page %d"` but there is no window, no gap and no prev/next.

- [ ] **Step 3: Replace `renderPagination()` and `renderPageLink()`**

Replace lines 114-156 of `ResultsRenderer.php` — everything from `private function renderPagination(` to the end of `renderPageLink()` — with:

```php
    private function renderPagination(SearchResult $result, SearchCriteria $criteria, string $baseUrl): string
    {
        $totalPages = $result->totalPages();

        if ($totalPages <= 1) {
            return '';
        }

        $currentPage = $result->pagination->page;

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
```

- [ ] **Step 4: Run the tests and PHPStan**

```bash
ddev composer test:integration && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, and `No errors` from PHPStan. `ShortcodeTest::test_pagination_links_carry_query_and_mark_the_current_page` must still pass — 15 courses at 12 per page is 2 pages, so the window is `{1, 2}` and both stay linked.

- [ ] **Step 5: Commit**

```bash
git add plugins/course-discovery/src/Frontend/ResultsRenderer.php plugins/course-discovery/tests/Integration/Frontend/ResultsRendererTest.php && git commit -m "fix: condense pagination to a page window"
```

---

### Task 2: Structural recomposition

**Files:**
- Modify: `plugins/course-discovery/src/Frontend/Shortcode.php:31-57`
- Modify: `plugins/course-discovery/src/Frontend/FormRenderer.php:25-46` (replace `render()`), `:184-194` (leave `renderSortState()` alone — Task 3 removes it)
- Modify: `plugins/course-discovery/src/Frontend/ResultsRenderer.php:29-59`
- Test: `plugins/course-discovery/tests/Integration/Frontend/ShortcodeTest.php`, `plugins/course-discovery/tests/Integration/Frontend/FormRendererTest.php`

**Interfaces:**
- Consumes: `ResultsRenderer::render()` from Task 1, unchanged.
- Produces:
  - `FormRenderer::renderHero(FilterRegistry $registry, SearchCriteria $criteria): string`
  - `FormRenderer::renderFilters(FilterRegistry $registry, SearchCriteria $criteria, int $activeCount = 0): string`
  - `ResultsRenderer::renderCount(SearchResult $result): string`
  - `FormRenderer::render()` is **deleted**. Task 5 passes a real `$activeCount`; until then it defaults to 0.

- [ ] **Step 1: Write the failing tests**

Append to `ShortcodeTest`:

```php
    public function test_the_wrapper_itself_is_the_form_so_every_region_can_be_laid_out(): void
    {
        $html = $this->render();

        self::assertStringContainsString(
            '<form class="cd-discovery cd-search-form" method="get" data-cd-root>',
            $html,
            'The form is the grid container: the sort control sits in the results toolbar and must still submit.'
        );
    }

    public function test_the_keyword_field_is_lifted_out_of_the_filter_panel_into_the_hero(): void
    {
        $html = $this->render();

        $heroStart = strpos($html, '<div class="cd-search-hero">');
        $panelStart = strpos($html, '<details class="cd-filters"');
        $keyword = strpos($html, 'id="cd-filter-q"');

        self::assertNotFalse($heroStart, 'Expected a hero region.');
        self::assertNotFalse($panelStart, 'Expected a filter panel.');
        self::assertNotFalse($keyword, 'Expected the keyword field.');

        self::assertGreaterThan($heroStart, $keyword, 'The keyword field belongs to the hero.');
        self::assertLessThan($panelStart, $keyword, 'The keyword field must not be inside the filter panel.');
    }

    public function test_the_count_sits_in_the_toolbar_and_carries_the_live_region_itself(): void
    {
        $html = $this->render();

        self::assertStringContainsString(
            '<p class="cd-results-count" aria-live="polite" aria-atomic="true">',
            $html,
            'aria-live belongs on the count, not on a wrapper that would re-announce every result.'
        );
        self::assertStringNotContainsString(
            '<div class="cd-results" aria-live="polite"',
            $html,
            'The results wrapper must no longer be the live region.'
        );

        $toolbar = strpos($html, '<div class="cd-toolbar">');
        $count = strpos($html, 'class="cd-results-count"');

        self::assertNotFalse($toolbar, 'Expected a results toolbar.');
        self::assertNotFalse($count);
        self::assertGreaterThan($toolbar, $count, 'The count renders inside the toolbar.');
    }

    public function test_the_filter_panel_is_a_disclosure_open_by_default(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<details class="cd-filters" open>', $html);
        self::assertStringContainsString('<summary class="cd-filters-summary">Filters</summary>', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest
```

Expected: `FAILURES!` on all four new tests — today the wrapper is `<div class="cd-discovery" data-cd-root>`, there is no hero, no toolbar, no `<details>`, and `aria-live` is on the results div.

- [ ] **Step 3: Split `FormRenderer::render()` into `renderHero()` and `renderFilters()`**

In `FormRenderer.php`, add `use CourseDiscovery\Domain\Filter\Filter;` to the imports if it is not already there, then replace the whole `render()` method (lines 25-46) with:

```php
    /**
     * The hero search region: the free-text field and the primary submit.
     *
     * Separate from renderFilters() because Shortcode places the two in
     * different grid areas -- the hero spans the full width above both
     * columns. Preserved params ride along here so they sit inside the
     * form exactly once.
     */
    public function renderHero(FilterRegistry $registry, SearchCriteria $criteria): string
    {
        $keyword = $this->keywordFilter($registry);

        $html = '<div class="cd-search-hero">';

        if ($keyword !== null) {
            $html .= $this->renderText($keyword, $criteria);
        }

        $html .= '<button type="submit" class="cd-search-submit">'
            . esc_html__('Search', 'course-discovery') . '</button>';
        $html .= '</div>';
        // Hidden state-preservation inputs. Both must sit inside the form but
        // have no visual position, so they ride along here together.
        $html .= $this->renderSortState($criteria);
        $html .= $this->renderPreservedParams($registry);

        return $html;
    }

    /**
     * The facet panel: every filter except the one that owns the free-text
     * term, wrapped in a <details> so it can collapse on a narrow viewport.
     *
     * $activeCount drives the summary's "(N)" and is supplied by
     * ActiveFiltersRenderer::activeCount(), so the panel header and the
     * chips can never disagree about what counts as applied.
     */
    public function renderFilters(FilterRegistry $registry, SearchCriteria $criteria, int $activeCount = 0): string
    {
        $html = '<details class="cd-filters" open>';
        $html .= '<summary class="cd-filters-summary">'
            . esc_html($this->filtersSummary($activeCount)) . '</summary>';
        $html .= '<div class="cd-filters-body">';

        foreach ($registry->all() as $filter) {
            if ($filter->key()->queryParam() === SearchCriteria::PARAM_TERM) {
                continue;
            }

            $html .= $this->renderFieldset($filter, $criteria);
        }

        $html .= '<div class="cd-search-actions">';
        $html .= '<button type="submit" class="cd-apply-filters">'
            . esc_html__('Apply filters', 'course-discovery') . '</button>';
        // Stays here only until Task 5, which relocates it next to the chips
        // it clears. Keeping it for now is what stops
        // ShortcodeTest::test_clear_filters_link_has_no_known_filter_params
        // failing in the one commit between deleting render() and building
        // the chips block.
        $html .= ' <a class="cd-clear-filters" href="' . esc_url($this->clearFiltersUrl($registry)) . '">'
            . esc_html__('Clear filters', 'course-discovery') . '</a>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</details>';

        return $html;
    }

    private function filtersSummary(int $activeCount): string
    {
        if ($activeCount < 1) {
            return __('Filters', 'course-discovery');
        }

        return sprintf(
            /* translators: %s: number of applied filters, already localised. */
            __('Filters (%s)', 'course-discovery'),
            number_format_i18n($activeCount)
        );
    }

    /**
     * The filter that owns the free-text term, or null when none is
     * registered.
     *
     * Matched on the key rather than on FilterInputType::Text so a
     * third-party text filter registered via
     * course_discovery/register_filters lands in the facet panel with the
     * others instead of silently taking over the hero search field.
     */
    private function keywordFilter(FilterRegistry $registry): ?Filter
    {
        return $registry->get(SearchCriteria::PARAM_TERM);
    }
```

`renderSortState()` and its `use CourseDiscovery\Domain\SortOrder;` import stay untouched, and `renderHero()` above keeps calling it. Task 3 is what deletes both the call and the method, once the visible `<select name="sort">` supersedes them. This task changes **structure only**: every test passing before it must still pass after it, with no exceptions and no PHPStan suppressions.

- [ ] **Step 4: Extract `renderCount()` in `ResultsRenderer`**

In `ResultsRenderer.php`, replace the docblock and `render()` (lines 12-59) with:

```php
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
```

- [ ] **Step 5: Recompose `Shortcode::render()`**

Replace `Shortcode::render()`'s `return` statement (lines 53-56) with:

```php
        return '<form class="cd-discovery cd-search-form" method="get" data-cd-root>'
            . $this->form->renderHero($this->service->registry, $criteria)
            . $this->form->renderFilters($this->service->registry, $criteria)
            . '<div class="cd-results-region">'
            . '<div class="cd-toolbar">'
            . $this->results->renderCount($result)
            . '</div>'
            . $this->results->render($result, $criteria, $baseUrl)
            . '</div>'
            . '</form>';
```

- [ ] **Step 6: Fix the two tests that assert the old shape**

In `ResultsRendererTest::test_a_page_past_the_end_with_results_present_does_not_render_a_bare_empty_list`, the `'5 courses found'` assertion now belongs to `renderCount()`. Replace that one assertion with:

```php
        self::assertStringContainsString(
            '5 courses found',
            (new ResultsRenderer())->renderCount($result),
            'The accurate total must still be reported.'
        );
```

In `FormRendererTest::test_a_combobox_filters_description_is_rendered_and_referenced`, change the render call from `render($registry, SearchCriteria::empty())` to:

```php
        $html = (new FormRenderer())->renderFilters($registry, SearchCriteria::empty());
```

- [ ] **Step 7: Run the full gate**

```bash
ddev composer test && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, `No errors` from PHPStan. `ShortcodeTest::test_each_filter_group_is_a_fieldset_with_a_legend` still passes on the four facet filters; `test_it_announces_the_result_count_to_assistive_technology` still finds `aria-live="polite"`, now on the count.

- [ ] **Step 8: Commit**

```bash
git add plugins/course-discovery/src/Frontend plugins/course-discovery/tests/Integration/Frontend && git commit -m "refactor: restructure find courses markup"
```

---

### Task 3: Sort control

**Files:**
- Modify: `plugins/course-discovery/src/Frontend/FormRenderer.php` (add `renderSortControl()` and `sortLabel()`, delete `renderSortState()`)
- Modify: `plugins/course-discovery/src/Frontend/Shortcode.php` (add the control to the toolbar)
- Modify: `plugins/course-discovery/assets/course-discovery.js`
- Test: `plugins/course-discovery/tests/Integration/Frontend/ShortcodeTest.php`

**Interfaces:**
- Consumes: Task 2's `.cd-toolbar` region and `FormRenderer::renderFilters()`.
- Produces: `FormRenderer::renderSortControl(SearchCriteria $criteria): string`, emitting a `<select name="sort" data-cd-sort>`. `renderSortState()` no longer exists.

- [ ] **Step 1: Write the failing tests**

In `ShortcodeTest`, **replace** the last two methods (`test_it_preserves_a_non_default_sort_as_a_hidden_input_on_submit` and `test_it_does_not_render_a_sort_hidden_input_for_the_default_sort`) with:

```php
    public function test_the_sort_control_is_a_real_select_in_the_toolbar(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<select id="cd-sort" name="sort" data-cd-sort>', $html);
        self::assertStringContainsString('<label for="cd-sort">', $html);

        $toolbar = strpos($html, '<div class="cd-toolbar">');
        $sort = strpos($html, 'name="sort"');

        self::assertNotFalse($toolbar);
        self::assertNotFalse($sort);
        self::assertGreaterThan($toolbar, $sort, 'The sort control belongs to the results toolbar.');
    }

    public function test_every_sort_order_is_offered_with_a_human_label(): void
    {
        $html = $this->render();

        foreach (['soonest', 'price_asc', 'title'] as $value) {
            self::assertStringContainsString('<option value="' . $value . '"', $html);
        }

        self::assertStringContainsString('Starting soonest', $html);
        self::assertStringContainsString('Price: low to high', $html);
    }

    public function test_a_non_default_sort_round_trips_as_the_selected_option(): void
    {
        $_GET['sort'] = 'price_asc';

        self::assertStringContainsString('<option value="price_asc" selected>', $this->render());
    }

    public function test_the_default_sort_is_selected_and_no_hidden_sort_input_remains(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<option value="soonest" selected>', $html);
        self::assertStringNotContainsString(
            '<input type="hidden" name="sort"',
            $html,
            'The select carries sort on every submit -- a hidden input would duplicate the field.'
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ShortcodeTest
```

Expected: `FAILURES!` on all four — there is no `<select name="sort">` anywhere yet.

- [ ] **Step 3: Add the control and delete `renderSortState()`**

In `FormRenderer.php`, add these two methods (keep the existing `use CourseDiscovery\Domain\SortOrder;` import):

```php
    /**
     * The sort control.
     *
     * A real <select> inside the form, so with JavaScript off it applies on
     * the next submit like any other field. course-discovery.js adds a
     * change listener scoped to this element -- see its own comment for why
     * the listener must not sit on the form.
     *
     * This replaces the hidden input that used to preserve a non-default
     * sort across a submit: the select now carries `sort` on every request,
     * so a hidden field would be a duplicate of the same name.
     */
    public function renderSortControl(SearchCriteria $criteria): string
    {
        $id = 'cd-sort';

        $html = '<div class="cd-sort">';
        $html .= '<label for="' . esc_attr($id) . '">'
            . esc_html__('Sort by', 'course-discovery') . '</label> ';
        $html .= '<select id="' . esc_attr($id) . '" name="'
            . esc_attr(SearchCriteria::PARAM_SORT) . '" data-cd-sort>';

        foreach (SortOrder::cases() as $sort) {
            $selected = $sort === $criteria->sort ? ' selected' : '';
            $html .= '<option value="' . esc_attr($sort->value) . '"' . $selected . '>'
                . esc_html($this->sortLabel($sort)) . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Human labels for SortOrder.
     *
     * They live here rather than on the enum because src/Domain/ must
     * contain no WordPress and __() is WordPress -- DomainPurityTest
     * enforces it. A match on the enum (not a map keyed by ->value) means a
     * new case is a compile-time error here rather than a silently
     * unlabelled option.
     */
    private function sortLabel(SortOrder $sort): string
    {
        return match ($sort) {
            SortOrder::Soonest => __('Starting soonest', 'course-discovery'),
            SortOrder::PriceAscending => __('Price: low to high', 'course-discovery'),
            SortOrder::Title => __('Title A–Z', 'course-discovery'),
        };
    }
```

Then **delete** `renderSortState()` entirely — its docblock, its body, **and its call in `renderHero()`** (the line `$html .= $this->renderSortState($criteria);`, along with the two-line comment above it that also covers `renderPreservedParams()`; keep that call and reword the comment to describe it alone). The visible select now carries `sort` on every submit, so the hidden input would be a duplicate field of the same name.

- [ ] **Step 4: Add it to the toolbar**

In `Shortcode::render()`, change the toolbar block to:

```php
            . '<div class="cd-toolbar">'
            . $this->results->renderCount($result)
            . $this->form->renderSortControl($criteria)
            . '</div>'
```

- [ ] **Step 5: Add the scoped auto-submit to the JS**

In `assets/course-discovery.js`, insert this immediately before the closing `})();` on the last line (tab-indented, matching the file):

```js
	// ---------------------------------------------------------------------
	// Sort auto-submit.
	//
	// The listener is scoped to the sort <select> itself, NEVER to the form.
	// A form-level 'change' listener would also fire for every checkbox and
	// for every combobox option toggle -- toggleOption() dispatches 'change'
	// on the native <select> on each keypress -- reloading the page in the
	// middle of a multi-select. With JavaScript off, the select is applied by
	// the Search button like any other field.
	// ---------------------------------------------------------------------
	var sort = root.querySelector('[data-cd-sort]');

	if (sort && sort.form) {
		sort.addEventListener('change', function () {
			if (typeof sort.form.requestSubmit === 'function') {
				sort.form.requestSubmit();
			} else {
				sort.form.submit();
			}
		});
	}
```

- [ ] **Step 6: Run the full gate**

```bash
ddev composer test && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, `No errors`. PHPStan proves the `match` on `SortOrder` is exhaustive.

- [ ] **Step 7: Commit**

```bash
git add plugins/course-discovery/src/Frontend plugins/course-discovery/assets/course-discovery.js plugins/course-discovery/tests/Integration/Frontend/ShortcodeTest.php && git commit -m "feat: add a sort control to course search"
```

---

### Task 4: Extract `SearchUrls`

A pure refactor with no behaviour change, so Task 5 can add "Clear all" to the chips block without copying `knownQueryKeys()` into a second renderer.

**Files:**
- Create: `plugins/course-discovery/src/Frontend/SearchUrls.php`
- Modify: `plugins/course-discovery/src/Frontend/FormRenderer.php` (add a constructor, delegate)
- Modify: `plugins/course-discovery/src/Plugin.php:94-98`
- Test: `plugins/course-discovery/tests/Integration/Frontend/FormRendererTest.php` (constructor call only)

**Interfaces:**
- Consumes: Task 3's `FormRenderer`.
- Produces:
  - `SearchUrls::knownKeys(FilterRegistry $registry): list<string>`
  - `SearchUrls::clearFilters(FilterRegistry $registry): string`
  - `FormRenderer::__construct(SearchUrls $urls)` — **every** `new FormRenderer()` becomes `new FormRenderer(new SearchUrls())`.

- [ ] **Step 1: Create the class**

```php
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
     * plugin does not own survive the reset.
     */
    public function clearFilters(FilterRegistry $registry): string
    {
        return remove_query_arg($this->knownKeys($registry));
    }
}
```

- [ ] **Step 2: Delegate from `FormRenderer`**

Add the constructor directly above `renderHero()`:

```php
    public function __construct(private readonly SearchUrls $urls)
    {
    }
```

Then **delete** `FormRenderer`'s own `knownQueryKeys()` and `clearFiltersUrl()` methods, and repoint their two callers at the collaborator. In `renderPreservedParams()`:

```php
        $known = $this->urls->knownKeys($registry);
```

and in `renderFilters()`:

```php
        $html .= ' <a class="cd-clear-filters" href="' . esc_url($this->urls->clearFilters($registry)) . '">'
            . esc_html__('Clear filters', 'course-discovery') . '</a>';
```

The link still renders from `renderFilters()` after this task — Task 5 is what relocates it into the chips block. This task changes only where its URL comes from, so no test changes behaviour.

- [ ] **Step 3: Update the two construction sites**

In `Plugin.php`, change the `Frontend\FormRenderer()` argument (line 96) to:

```php
                new Frontend\FormRenderer(new Frontend\SearchUrls()),
```

In `FormRendererTest`, add `use CourseDiscovery\Frontend\SearchUrls;` to the imports and change the render call to:

```php
        $html = (new FormRenderer(new SearchUrls()))->renderFilters($registry, SearchCriteria::empty());
```

- [ ] **Step 4: Verify nothing else constructs a `FormRenderer`**

```bash
grep -rn "new FormRenderer\|new Frontend\\\\FormRenderer" plugins/ --include=*.php
```

Expected: exactly the two sites above, both already passing `SearchUrls`. Fix any others the same way.

- [ ] **Step 5: Run the full gate**

```bash
ddev composer test && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, `No errors`. This task is a pure refactor — every test that passed before it must still pass, including `ShortcodeTest::test_clear_filters_link_has_no_known_filter_params`. If any test changes behaviour, the delegation is wrong, not the test.

- [ ] **Step 6: Commit**

```bash
git add plugins/course-discovery/src/Frontend plugins/course-discovery/src/Plugin.php plugins/course-discovery/tests/Integration/Frontend && git commit -m "refactor: extract SearchUrls from FormRenderer"
```

---

### Task 5: Active filter chips

**Files:**
- Create: `plugins/course-discovery/src/Frontend/ActiveFiltersRenderer.php`
- Create: `plugins/course-discovery/tests/Integration/Frontend/ActiveFiltersRendererTest.php`
- Modify: `plugins/course-discovery/src/Frontend/Shortcode.php`
- Modify: `plugins/course-discovery/src/Plugin.php:94-98`
- Modify: `plugins/course-discovery/tests/Integration/Frontend/ShortcodeTest.php`

**Interfaces:**
- Consumes: `SearchUrls::clearFilters()` from Task 4; `FormRenderer::renderFilters($registry, $criteria, int $activeCount)` from Task 2.
- Produces:
  - `ActiveFiltersRenderer::__construct(SearchUrls $urls)`
  - `ActiveFiltersRenderer::render(FilterRegistry $registry, SearchCriteria $criteria, string $baseUrl): string`
  - `ActiveFiltersRenderer::activeCount(FilterRegistry $registry, SearchCriteria $criteria): int`
  - `Shortcode::__construct(SearchService $service, FormRenderer $form, ResultsRenderer $results, ActiveFiltersRenderer $activeFilters)`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Frontend/ActiveFiltersRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Filter\KeywordFilter;
use CourseDiscovery\Frontend\ActiveFiltersRenderer;
use CourseDiscovery\Frontend\SearchUrls;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Chip labels come from the filter's own options(), which the form has
 * already built for this request, so chips cost no extra queries. The
 * removal URL is built by round-tripping through SearchCriteria rather than
 * editing a query string, so "unset a key whose values are now empty" and
 * "omit defaults" stay in one place -- the domain object.
 */
final class ActiveFiltersRendererTest extends IntegrationTestCase
{
    private const BASE_URL = 'https://example.test/courses/';

    private function registry(): FilterRegistry
    {
        $registry = new FilterRegistry();
        $registry->register(new KeywordFilter());
        $registry->register(new class () implements Filter {
            public function key(): FilterKey
            {
                return FilterKey::fromString('subject');
            }

            public function label(): string
            {
                return 'Subject';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::CheckboxGroup;
            }

            public function description(): ?string
            {
                return null;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([
                    new FilterOption('10', 'Design'),
                    new FilterOption('20', 'Statistics'),
                ]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        return $registry;
    }

    private function criteriaWith(string ...$subjects): SearchCriteria
    {
        return SearchCriteria::empty()->withFilter(
            FilterKey::fromString('subject'),
            FilterValues::fromStrings($subjects)
        );
    }

    private function render(SearchCriteria $criteria): string
    {
        return (new ActiveFiltersRenderer(new SearchUrls()))
            ->render($this->registry(), $criteria, self::BASE_URL);
    }

    public function test_nothing_is_rendered_when_no_filter_is_applied(): void
    {
        self::assertSame('', $this->render(SearchCriteria::empty()));
    }

    public function test_a_chip_shows_the_option_label_not_the_raw_value(): void
    {
        $html = $this->render($this->criteriaWith('10'));

        self::assertStringContainsString('Design', $html);
        self::assertStringNotContainsString('>10<', $html);
    }

    public function test_removing_one_of_two_values_keeps_the_other_in_the_url(): void
    {
        $html = $this->render($this->criteriaWith('10', '20'));

        preg_match_all('/<a class="cd-chip" href="([^"]+)"/', $html, $matches);

        self::assertCount(2, $matches[1], 'One chip per applied value.');

        $first = html_entity_decode($matches[1][0]);

        self::assertStringNotContainsString('subject%5B0%5D=10', $first);
        self::assertStringContainsString('20', $first, 'The value not being removed must survive.');
    }

    public function test_removing_the_only_value_drops_the_key_entirely(): void
    {
        $html = $this->render($this->criteriaWith('10'));

        preg_match('/<a class="cd-chip" href="([^"]+)"/', $html, $match);

        self::assertArrayHasKey(1, $match);
        self::assertStringNotContainsString('subject', html_entity_decode($match[1]));
    }

    public function test_a_removal_url_always_returns_to_the_first_page(): void
    {
        $criteria = $this->criteriaWith('10')->withPagination(new \CourseDiscovery\Domain\Pagination(4, 12));

        $html = $this->render($criteria);

        preg_match('/<a class="cd-chip" href="([^"]+)"/', $html, $match);

        self::assertArrayHasKey(1, $match);
        self::assertStringNotContainsString(
            SearchCriteria::PARAM_PAGE,
            html_entity_decode($match[1]),
            'Removing a filter widens the result set, so page 4 of the narrower one is meaningless.'
        );
    }

    public function test_a_value_with_no_matching_option_is_skipped_rather_than_shown_raw(): void
    {
        self::assertSame(
            '',
            $this->render($this->criteriaWith('999')),
            'A stale term id from a bookmarked URL must not render as a raw slug.'
        );
    }

    public function test_the_keyword_term_gets_no_chip(): void
    {
        self::assertSame('', $this->render(SearchCriteria::empty()->withTerm('design')));
    }

    public function test_the_active_count_matches_the_number_of_chips(): void
    {
        $renderer = new ActiveFiltersRenderer(new SearchUrls());

        self::assertSame(0, $renderer->activeCount($this->registry(), SearchCriteria::empty()));
        self::assertSame(2, $renderer->activeCount($this->registry(), $this->criteriaWith('10', '20')));
        self::assertSame(
            0,
            $renderer->activeCount($this->registry(), $this->criteriaWith('999')),
            'The count must apply the same "must match an option" rule the chips do.'
        );
    }

    public function test_a_chip_label_containing_markup_is_escaped(): void
    {
        $registry = new FilterRegistry();
        $registry->register(new class () implements Filter {
            public function key(): FilterKey
            {
                return FilterKey::fromString('subject');
            }

            public function label(): string
            {
                return 'Subject';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::CheckboxGroup;
            }

            public function description(): ?string
            {
                return null;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([
                    new FilterOption('10', '"><img src=x onerror=alert(1)>'),
                ]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        $html = (new ActiveFiltersRenderer(new SearchUrls()))
            ->render($registry, $this->criteriaWith('10'), self::BASE_URL);

        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }
}
```

Verify `KeywordFilter`'s constructor takes no arguments before relying on `new KeywordFilter()`; if it does take arguments, register the same anonymous-class pattern with key `q` instead.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ActiveFiltersRendererTest
```

Expected: `ERRORS!` — `Class "CourseDiscovery\Frontend\ActiveFiltersRenderer" not found`.

- [ ] **Step 3: Create the renderer**

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Filter\FilterRegistry;

/**
 * The "currently applied" chips: one per selected value, each a link that
 * removes just that value.
 *
 * Labels come from the filter's own options(), which the form has already
 * built for this request, so chips add no queries. A selected value with no
 * matching option -- a stale term id in a bookmarked URL -- is skipped
 * rather than rendered as a raw slug.
 *
 * The free-text term gets no chip: it is already visible in the hero search
 * field, and a chip would be a second, separately-removable copy of the same
 * state. Sort is not a filter and gets none either.
 */
final class ActiveFiltersRenderer
{
    public function __construct(private readonly SearchUrls $urls)
    {
    }

    public function render(FilterRegistry $registry, SearchCriteria $criteria, string $baseUrl): string
    {
        $chips = $this->chips($registry, $criteria);

        if ($chips === []) {
            return '';
        }

        $html = '<div class="cd-active-filters">';
        $html .= '<h2 class="cd-active-filters-heading">'
            . esc_html__('Applied filters', 'course-discovery') . '</h2>';
        $html .= '<ul class="cd-active-filters-list">';

        foreach ($chips as $chip) {
            $html .= '<li>' . $this->renderChip($chip, $criteria, $baseUrl) . '</li>';
        }

        $html .= '</ul>';
        $html .= '<a class="cd-clear-filters" href="'
            . esc_url($this->urls->clearFilters($registry)) . '">'
            . esc_html__('Clear all', 'course-discovery') . '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * How many chips render() would emit.
     *
     * Shared with FormRenderer's "Filters (N)" summary through
     * Shortcode, so the panel header and the chips can never disagree
     * about what counts as an applied filter.
     */
    public function activeCount(FilterRegistry $registry, SearchCriteria $criteria): int
    {
        return count($this->chips($registry, $criteria));
    }

    /**
     * @return list<array{filter: Filter, value: string, label: string}>
     */
    private function chips(FilterRegistry $registry, SearchCriteria $criteria): array
    {
        $chips = [];

        foreach ($registry->all() as $filter) {
            if ($filter->key()->queryParam() === SearchCriteria::PARAM_TERM) {
                continue;
            }

            $selected = $criteria->valuesFor($filter->key())->toStrings();

            if ($selected === []) {
                continue;
            }

            $labels = $this->optionLabels($filter, $criteria);

            foreach ($selected as $value) {
                if (! isset($labels[$value])) {
                    continue;
                }

                $chips[] = ['filter' => $filter, 'value' => $value, 'label' => $labels[$value]];
            }
        }

        return $chips;
    }

    /**
     * @return array<string, string>
     */
    private function optionLabels(Filter $filter, SearchCriteria $criteria): array
    {
        $labels = [];

        /** @var FilterOption $option */
        foreach ($filter->options($criteria) as $option) {
            $labels[$option->value] = $option->label;
        }

        return $labels;
    }

    /**
     * @param array{filter: Filter, value: string, label: string} $chip
     */
    private function renderChip(array $chip, SearchCriteria $criteria, string $baseUrl): string
    {
        $url = $this->removalUrl($chip['filter'], $chip['value'], $criteria, $baseUrl);

        $accessibleName = sprintf(
            /* translators: %s: the filter value being removed, e.g. "Oxford". */
            __('Remove filter: %s', 'course-discovery'),
            $chip['label']
        );

        return '<a class="cd-chip" href="' . esc_url($url) . '" aria-label="'
            . esc_attr($accessibleName) . '">'
            . esc_html($chip['label'])
            . '<span class="cd-chip-remove" aria-hidden="true">&times;</span>'
            . '</a>';
    }

    /**
     * Rebuilds the query with one value dropped by going through
     * SearchCriteria rather than editing the query string: withFilter()
     * already knows to unset a key whose values are now empty, and
     * toQueryParams() already knows which defaults to omit.
     *
     * Page resets to 1 -- removing a filter widens the result set, so page 7
     * of the old, narrower one is meaningless and may not exist.
     */
    private function removalUrl(
        Filter $filter,
        string $value,
        SearchCriteria $criteria,
        string $baseUrl
    ): string {
        $remaining = array_values(array_filter(
            $criteria->valuesFor($filter->key())->toStrings(),
            static fn (string $candidate): bool => $candidate !== $value
        ));

        $params = $criteria
            ->withFilter($filter->key(), FilterValues::fromStrings($remaining))
            ->withPagination(new Pagination(1, $criteria->pagination->perPage))
            ->toQueryParams();

        return add_query_arg($params, $baseUrl);
    }
}
```

- [ ] **Step 4: Run the new tests**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ActiveFiltersRendererTest
```

Expected: all pass, no `FAILURES!` / `ERRORS!`.

- [ ] **Step 5: Wire it into `Shortcode` and `Plugin`, and relocate the clear link**

First, in `FormRenderer::renderFilters()`, **delete** the `.cd-clear-filters` anchor and the comment above it — `ActiveFiltersRenderer::render()` now emits it, next to the chips it clears. The `.cd-search-actions` div keeps only the Apply button.

In `Shortcode.php`, add the constructor property:

```php
    public function __construct(
        private SearchService $service,
        private FormRenderer $form,
        private ResultsRenderer $results,
        private ActiveFiltersRenderer $activeFilters,
    ) {
    }
```

and change the `return` to compute the count once and place the chips between hero and panel:

```php
        $registry = $this->service->registry;
        $activeCount = $this->activeFilters->activeCount($registry, $criteria);

        return '<form class="cd-discovery cd-search-form" method="get" data-cd-root>'
            . $this->form->renderHero($registry, $criteria)
            . $this->activeFilters->render($registry, $criteria, $baseUrl)
            . $this->form->renderFilters($registry, $criteria, $activeCount)
            . '<div class="cd-results-region">'
            . '<div class="cd-toolbar">'
            . $this->results->renderCount($result)
            . $this->form->renderSortControl($criteria)
            . '</div>'
            . $this->results->render($result, $criteria, $baseUrl)
            . '</div>'
            . '</form>';
```

In `Plugin.php`, add the fourth argument:

```php
            (new Frontend\Shortcode(
                $container->get(Search\SearchService::class),
                new Frontend\FormRenderer(new Frontend\SearchUrls()),
                new Frontend\ResultsRenderer(),
                new Frontend\ActiveFiltersRenderer(new Frontend\SearchUrls()),
            ))->register();
```

- [ ] **Step 6: Restore the clear-filters test and add end-to-end chip coverage**

Append to `ShortcodeTest`:

```php
    public function test_an_applied_filter_renders_a_chip_and_a_filter_count(): void
    {
        /** @var int $providerId */
        $providerId = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_title'  => 'Chipped Provider',
            'post_status' => 'publish',
        ]);

        $_GET['provider'] = [(string) $providerId];

        $html = $this->render();

        self::assertStringContainsString('<div class="cd-active-filters">', $html);
        self::assertStringContainsString('aria-label="Remove filter: Chipped Provider"', $html);
        self::assertStringContainsString('<summary class="cd-filters-summary">Filters (1)</summary>', $html);
    }

    public function test_no_chip_block_renders_for_an_unfiltered_search(): void
    {
        self::assertStringNotContainsString('cd-active-filters', $this->render());
    }
```

Note `test_clear_filters_link_has_no_known_filter_params` sets `$_GET['provider'] = ['5']`, which is not a real provider id — so no chip renders for it, but `$_GET['q'] = 'design'` is also set and the term never chips either. That test would then find no clear-filters link at all. Change its `$_GET['provider']` to a real provider created via the factory, the same way the new test above does, so a chip (and therefore the clear link) actually renders.

- [ ] **Step 7: Run the full gate**

```bash
ddev composer test && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, `No errors`.

- [ ] **Step 8: Commit**

```bash
git add plugins/course-discovery/src plugins/course-discovery/tests && git commit -m "feat: add active filter chips"
```

---

### Task 6: Stylesheet rewrite

**Files:**
- Rewrite: `plugins/course-discovery/assets/course-discovery.css`

**Interfaces:**
- Consumes: the final markup from Tasks 2, 3 and 5 — `.cd-discovery`, `.cd-search-hero`, `.cd-active-filters`, `.cd-active-filters-heading`, `.cd-active-filters-list`, `.cd-chip`, `.cd-chip-remove`, `.cd-clear-filters`, `.cd-filters`, `.cd-filters-summary`, `.cd-filters-body`, `.cd-filter`, `.cd-filter-options`, `.cd-checkbox`, `.cd-filter-description`, `.cd-search-actions`, `.cd-search-submit`, `.cd-apply-filters`, `.cd-results-region`, `.cd-toolbar`, `.cd-results-count`, `.cd-sort`, `.cd-results`, `.cd-results-list`, `.cd-result`, `.cd-result-title`, `.cd-result-description`, `.cd-result-price`, `.cd-result-start`, `.cd-empty-state`, `.cd-pagination`, `.cd-pagination-gap`, `.cd-pagination-prev`, `.cd-pagination-next`, `.cd-visually-hidden`, `.cd-combobox`, `.cd-combobox-trigger`, `.cd-combobox-listbox`.
- Produces: nothing consumed by later tasks.

This file is **tab-indented** — match it.

- [ ] **Step 1: Replace the whole file**

```css
/*
 * Course Discovery -- the Find Courses UI.
 *
 * Tokens are declared once on .cd-discovery and every rule below reads them,
 * so no rule writes a bare colour or size. Each token reads a block theme's
 * preset with the plugin's own value as a fallback, so the UI looks native
 * where a theme defines presets (Twenty Twenty-Five does) and still looks
 * deliberate where it does not.
 *
 * On the palette: of Twenty Twenty-Five's six accents only accent-3 has
 * text-grade contrast against base, so it is the sole interactive colour.
 * accent-1 is a yellow used only as a chip background behind --cd-text --
 * never as a text or border colour. accent-6 is already
 * color-mix(currentColor 20%), i.e. the theme's own border token.
 *
 * The focus style is an accessibility requirement, not decoration: it is
 * what makes keyboard-only navigation (the no-JS baseline this plugin is
 * built on) usable at all. It is scoped to .cd-discovery rather than left
 * global, so a plugin stylesheet does not restyle focus for the whole site.
 */

/* ------------------------------------------------------------------ *
 * Tokens and page grid
 * ------------------------------------------------------------------ */

.cd-discovery {
	--cd-text: var(--wp--preset--color--contrast, #111111);
	--cd-text-muted: var(--wp--preset--color--accent-4, #686868);
	--cd-bg: var(--wp--preset--color--base, #ffffff);
	--cd-surface: var(--wp--preset--color--accent-5, #fbfaf3);
	--cd-accent: var(--wp--preset--color--accent-3, #503aa8);
	--cd-highlight: var(--wp--preset--color--accent-1, #ffee58);
	--cd-border: var(--wp--preset--color--accent-6, color-mix(in srgb, currentColor 20%, transparent));

	--cd-space-xs: var(--wp--preset--spacing--20, 0.5rem);
	--cd-space-sm: var(--wp--preset--spacing--30, 0.75rem);
	--cd-space-md: var(--wp--preset--spacing--40, 1.25rem);
	--cd-space-lg: var(--wp--preset--spacing--50, 2rem);

	--cd-font-sm: var(--wp--preset--font-size--small, 0.875rem);
	--cd-font-md: var(--wp--preset--font-size--medium, 1rem);
	--cd-font-lg: var(--wp--preset--font-size--large, 1.38rem);

	--cd-radius: 6px;
	--cd-radius-pill: 999px;

	color: var(--cd-text);
	display: grid;
	gap: var(--cd-space-md);
	grid-template-columns: 1fr;
	grid-template-areas:
		'hero'
		'chips'
		'filters'
		'results';
	align-items: start;
}

/*
 * Only the FALLBACKS flip here. Where a theme defines a preset it still
 * wins in both schemes, which is the point of inheriting them.
 */
@media (prefers-color-scheme: dark) {
	.cd-discovery {
		--cd-text: var(--wp--preset--color--contrast, #f2f2f2);
		--cd-text-muted: var(--wp--preset--color--accent-4, #a8a8a8);
		--cd-bg: var(--wp--preset--color--base, #111111);
		--cd-surface: var(--wp--preset--color--accent-5, #1c1c1c);
		--cd-accent: var(--wp--preset--color--accent-3, #b9a7ff);

		/*
		 * Invariant: any colour token paired against a token redeclared in
		 * this block must itself be redeclared here, or the pairing breaks in
		 * one scheme. --cd-highlight backs .cd-chip's background with
		 * --cd-text as its foreground; leaving it at the light yellow while
		 * --cd-text flipped to #f2f2f2 gave 1.07:1 -- invisible. This dark
		 * fallback restores 8.8:1.
		 */
		--cd-highlight: var(--wp--preset--color--accent-1, #4a4420);
	}
}

@media (min-width: 48rem) {
	.cd-discovery {
		grid-template-columns: 18rem 1fr;
		grid-template-areas:
			'hero    hero'
			'chips   chips'
			'filters results';
		column-gap: var(--cd-space-lg);
	}
}

.cd-search-hero {
	grid-area: hero;
}

.cd-active-filters {
	grid-area: chips;
}

.cd-filters {
	grid-area: filters;
}

.cd-results-region {
	grid-area: results;
}

/* ------------------------------------------------------------------ *
 * Hero search
 * ------------------------------------------------------------------ */

.cd-search-hero {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--cd-space-xs);
	padding: var(--cd-space-sm);
	background: var(--cd-surface);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-search-hero label {
	flex: 0 0 auto;
	font-size: var(--cd-font-sm);
	font-weight: 600;
}

.cd-search-hero input[type='search'] {
	flex: 1 1 12rem;
	min-width: 0;
	padding: 0.6rem 0.75rem;
	font: inherit;
	font-size: var(--cd-font-md);
	color: var(--cd-text);
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-filter-description {
	flex: 1 0 100%;
	margin: 0;
	font-size: var(--cd-font-sm);
	color: var(--cd-text-muted);
}

/* ------------------------------------------------------------------ *
 * Buttons
 * ------------------------------------------------------------------ */

.cd-search-submit,
.cd-apply-filters {
	padding: 0.6rem 1.1rem;
	font: inherit;
	font-size: var(--cd-font-md);
	font-weight: 600;
	color: var(--cd-bg);
	cursor: pointer;
	background: var(--cd-accent);
	border: 1px solid var(--cd-accent);
	border-radius: var(--cd-radius);
}

.cd-apply-filters {
	width: 100%;
}

.cd-search-submit:hover,
.cd-apply-filters:hover {
	background: var(--cd-text);
	border-color: var(--cd-text);
}

@media (prefers-reduced-motion: no-preference) {
	.cd-search-submit,
	.cd-apply-filters,
	.cd-chip,
	.cd-result,
	.cd-pagination a {
		transition: color 120ms ease, background-color 120ms ease, border-color 120ms ease;
	}
}

/* ------------------------------------------------------------------ *
 * Applied-filter chips
 * ------------------------------------------------------------------ */

.cd-active-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--cd-space-xs);
}

.cd-active-filters-heading {
	margin: 0;
	font-size: var(--cd-font-sm);
	font-weight: 600;
	color: var(--cd-text-muted);
	letter-spacing: 0.05em;
	text-transform: uppercase;
}

.cd-active-filters-list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--cd-space-xs);
	margin: 0;
	padding: 0;
	list-style: none;
}

.cd-chip {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
	padding: 0.25rem 0.7rem;
	font-size: var(--cd-font-sm);
	color: var(--cd-text);
	text-decoration: none;
	background: var(--cd-highlight);
	border: 1px solid transparent;
	border-radius: var(--cd-radius-pill);
}

.cd-chip:hover {
	border-color: var(--cd-text);
}

.cd-chip-remove {
	font-weight: 700;
	line-height: 1;
}

.cd-clear-filters {
	font-size: var(--cd-font-sm);
	color: var(--cd-accent);
}

/* ------------------------------------------------------------------ *
 * Filter panel
 * ------------------------------------------------------------------ */

.cd-filters {
	padding: var(--cd-space-sm);
	background: var(--cd-surface);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

/*
 * The disclosure marker stays visible at every width. It would be tempting
 * to hide it above 48rem, where the panel is always open -- but <summary>
 * still toggles on click, so hiding the affordance would leave a heading
 * that mysteriously collapses.
 */
.cd-filters-summary {
	padding: 0.25rem 0;
	font-size: var(--cd-font-md);
	font-weight: 600;
	cursor: pointer;
}

.cd-filters-body {
	display: flex;
	flex-direction: column;
	gap: var(--cd-space-md);
	margin-top: var(--cd-space-sm);
}

.cd-filter {
	margin: 0;
	padding: 0;
	border: 0;
}

.cd-filter legend {
	padding: 0;
	font-size: var(--cd-font-sm);
	font-weight: 600;
	color: var(--cd-text-muted);
	letter-spacing: 0.05em;
	text-transform: uppercase;
}

.cd-filter-options {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	margin-top: var(--cd-space-xs);
}

.cd-checkbox {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: var(--cd-font-sm);
}

/* The unenhanced multi-select, i.e. what a no-JS visitor actually uses. */
.cd-filter select[multiple] {
	width: 100%;
	padding: 0.25rem;
	font: inherit;
	font-size: var(--cd-font-sm);
	color: var(--cd-text);
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-search-actions {
	display: flex;
}

/* ------------------------------------------------------------------ *
 * Results toolbar
 * ------------------------------------------------------------------ */

.cd-results-region {
	display: flex;
	flex-direction: column;
	gap: var(--cd-space-md);
	min-width: 0;
}

.cd-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--cd-space-xs);
	padding-bottom: var(--cd-space-xs);
	border-bottom: 1px solid var(--cd-border);
}

.cd-results-count {
	margin: 0;
	font-size: var(--cd-font-md);
	font-weight: 600;
}

.cd-sort {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.cd-sort label {
	font-size: var(--cd-font-sm);
	color: var(--cd-text-muted);
}

.cd-sort select {
	padding: 0.3rem 0.5rem;
	font: inherit;
	font-size: var(--cd-font-sm);
	color: var(--cd-text);
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

/* ------------------------------------------------------------------ *
 * Results
 * ------------------------------------------------------------------ */

.cd-results {
	min-width: 0;
}

.cd-results-list {
	display: flex;
	flex-direction: column;
	gap: var(--cd-space-sm);
	margin: 0;
	padding: 0;
	list-style: none;
}

.cd-result {
	padding: var(--cd-space-md);
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-result:hover {
	border-color: var(--cd-text);
}

.cd-result-title {
	margin: 0 0 var(--cd-space-xs);
	font-size: var(--cd-font-lg);
	line-height: 1.25;
}

.cd-result-title a {
	color: var(--cd-text);
	text-decoration: none;
}

.cd-result-title a:hover {
	color: var(--cd-accent);
	text-decoration: underline;
}

.cd-result-description {
	margin: 0 0 var(--cd-space-xs);
	color: var(--cd-text-muted);
}

.cd-result-price {
	margin: 0;
	font-weight: 700;
}

.cd-result-start {
	margin: 0;
	font-size: var(--cd-font-sm);
	color: var(--cd-text-muted);
}

.cd-empty-state {
	margin: 0;
	padding: var(--cd-space-lg);
	color: var(--cd-text-muted);
	text-align: center;
	background: var(--cd-surface);
	border: 1px dashed var(--cd-border);
	border-radius: var(--cd-radius);
}

/* ------------------------------------------------------------------ *
 * Pagination
 * ------------------------------------------------------------------ */

.cd-pagination {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--cd-space-xs);
	margin-top: var(--cd-space-md);
}

.cd-pagination a {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 2.25rem;
	padding: 0.35rem 0.6rem;
	font-size: var(--cd-font-sm);
	color: var(--cd-text);
	text-decoration: none;
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-pagination a:hover {
	border-color: var(--cd-text);
}

.cd-pagination a[aria-current='page'] {
	font-weight: 700;
	color: var(--cd-bg);
	background: var(--cd-accent);
	border-color: var(--cd-accent);
}

.cd-pagination-prev,
.cd-pagination-next {
	font-weight: 600;
}

.cd-pagination-gap {
	padding: 0 0.15rem;
	color: var(--cd-text-muted);
}

/* ------------------------------------------------------------------ *
 * Accessibility and the JS combobox upgrade
 * ------------------------------------------------------------------ */

/* Every focusable element must show a visible focus indicator, since this
 * UI must be fully operable by keyboard alone. */
.cd-discovery :focus-visible {
	outline: 2px solid var(--cd-accent);
	outline-offset: 2px;
}

/*
 * The JS-only combobox upgrade. The underlying <select multiple> stays in
 * the DOM as the source of truth (see course-discovery.js) but is hidden
 * both visually and from assistive tech, which now reads the .cd-combobox
 * widget built next to it instead.
 */
.cd-visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	white-space: nowrap;
	border: 0;
	clip: rect(0, 0, 0, 0);
}

.cd-combobox {
	position: relative;
}

.cd-combobox-trigger {
	width: 100%;
	padding: 0.4rem 0.6rem;
	font: inherit;
	font-size: var(--cd-font-sm);
	color: var(--cd-text);
	text-align: left;
	cursor: pointer;
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
}

.cd-combobox-listbox {
	position: absolute;
	z-index: 10;
	inset-inline-start: 0;
	width: 100%;
	max-height: 12rem;
	margin: 0;
	padding: 0.25rem 0;
	overflow-y: auto;
	list-style: none;
	background: var(--cd-bg);
	border: 1px solid var(--cd-border);
	border-radius: var(--cd-radius);
	box-shadow: 0 4px 12px rgb(0 0 0 / 12%);
}

.cd-combobox-listbox [role='option'] {
	padding: 0.35rem 0.75rem;
	font-size: var(--cd-font-sm);
	cursor: pointer;
}

.cd-combobox-listbox [role='option']:hover {
	background: var(--cd-surface);
}

.cd-combobox-listbox [aria-selected='true'] {
	font-weight: 700;
}

.cd-combobox-listbox .is-active {
	outline: 2px solid var(--cd-accent);
	outline-offset: -2px;
}
```

- [ ] **Step 2: Confirm no PHP test regressed**

```bash
ddev composer test
```

Expected: no `FAILURES!` / `ERRORS!`. CSS cannot break these, so this is a cheap guard against having edited the wrong file.

- [ ] **Step 3: Look at the real page**

```bash
ddev launch /find-courses/
```

Check, at a desktop width and then narrowed below `48rem`:

- Two columns above `48rem`, one column below.
- Hero search spans the full width; the Search button sits beside the field.
- Result rows are bordered cards; the title is the largest text; price is bold; the start date is small and muted.
- The toolbar shows the count on the left and "Sort by" on the right, with a rule beneath.
- Changing the sort reloads immediately (JS on) and the new order sticks in the select.
- Applying a filter shows a yellow chip; clicking its × removes only that filter.
- Tab through the whole UI: every stop shows a purple focus ring.

- [ ] **Step 4: Commit**

```bash
git add plugins/course-discovery/assets/course-discovery.css && git commit -m "style: redesign find courses stylesheet"
```

---

### Task 7: Small-screen collapse and e2e coverage

**Files:**
- Modify: `plugins/course-discovery/assets/course-discovery.js`
- Create: `e2e/tests/enhancement.spec.ts`
- Modify: `e2e/playwright.config.ts` (register the new spec in the `js-enabled` project)
- Modify: `e2e/tests/no-js.spec.ts`, `e2e/tests/pagination.spec.ts`, `e2e/tests/keyboard.spec.ts`

**Why a new spec file:** `playwright.config.ts` assigns specs to projects by explicit `testMatch` lists — `keyboard.spec.ts` runs with JavaScript **enabled**, `no-js.spec.ts` and `pagination.spec.ts` with it **disabled**. The two behaviours added here (sort auto-submit, small-screen collapse) both need JavaScript on but are not keyboard concerns, so they get their own `enhancement.spec.ts` rather than being wedged into the keyboard spec. Registering it is mandatory: an unregistered spec file matches no project and **silently never runs**.

**Interfaces:**
- Consumes: `.cd-filters` from Task 2, `[data-cd-sort]` from Task 3, `.cd-chip` from Task 5.
- Produces: nothing consumed by later tasks.

**Scope note on e2e:** the seed holds 20 courses at 12 per page, i.e. **2 pages**. The window `{1, current±1, last}` therefore never produces an ellipsis against seed data, so ellipsis and five-link-cap behaviour is covered by Task 1's integration tests only. e2e here covers Prev/Next, which 2 pages does exercise.

- [ ] **Step 1: Add the small-screen collapse to the JS**

Append inside the IIFE in `assets/course-discovery.js`, after the sort block from Task 3 (tab-indented):

```js
	// ---------------------------------------------------------------------
	// Collapse the filter panel on a narrow viewport, so results are the
	// first thing on screen rather than pushed below five filter groups.
	//
	// This is JavaScript rather than a media query because CSS cannot
	// toggle the [open] attribute. It is also why the panel is rendered
	// OPEN: with this file absent or broken, a phone visitor sees expanded
	// filters -- the previous behaviour -- rather than a panel they cannot
	// open.
	// ---------------------------------------------------------------------
	var filters = root.querySelector('.cd-filters');

	if (filters && typeof window.matchMedia === 'function') {
		var narrow = window.matchMedia('(max-width: 48rem)');

		if (narrow.matches) {
			filters.removeAttribute('open');
		}
	}
```

- [ ] **Step 2: Add the no-JS sort test**

Append inside the existing `test.describe('filtering without JavaScript', …)` block in `e2e/tests/no-js.spec.ts` (tab-indented, matching the file):

```ts
	test('changing sort and submitting applies it without JavaScript', async ({ page }) => {
		await page.goto('/find-courses/');

		const form = page.locator('form.cd-search-form');
		const sort = page.locator('#cd-sort');

		// A real <select> in the form, not a JS widget.
		await expect(sort).toBeVisible();
		await expect(sort).toHaveValue('soonest');

		await sort.selectOption('price_asc');

		// No JS: nothing has submitted yet, so the sort is not applied.
		expect(new URL(page.url()).searchParams.has('sort')).toBe(false);

		await form.getByRole('button', { name: 'Search' }).click();
		await page.waitForURL((url) => url.searchParams.get('sort') === 'price_asc');

		// The applied sort round-trips into the control, so the page states
		// how it is currently ordered rather than resetting to the default.
		await expect(page.locator('#cd-sort')).toHaveValue('price_asc');
	});
```

- [ ] **Step 3: Add the Prev/Next test**

Append inside `test.describe('paginating results', …)` in `e2e/tests/pagination.spec.ts`:

```ts
	test('prev and next appear only where there is somewhere to go', async ({ page }) => {
		await page.goto('/find-courses/');

		// Page 1: nowhere back to.
		await expect(page.getByRole('link', { name: 'Previous page' })).toHaveCount(0);

		const next = page.getByRole('link', { name: 'Next page' });
		await expect(next).toBeVisible();
		await next.click();

		await expect(page.getByRole('link', { name: 'Previous page' })).toBeVisible();
		await expect(page.locator('.cd-pagination a[aria-current="page"]')).toHaveText('2');

		// The seed is two pages, so page 2 is the last one.
		await expect(page.getByRole('link', { name: 'Next page' })).toHaveCount(0);
	});
```

- [ ] **Step 4: Widen the keyboard spec's tab budget**

In `e2e/tests/keyboard.spec.ts`, change the `tabTo` default from `maxTabs = 30` to:

```ts
async function tabTo(page: Page, targetId: string, maxTabs = 50): Promise<void> {
```

The redesign adds focusable stops ahead of the location combobox — the hero Search button and the `<details>` summary — so the old budget is now uncomfortably tight rather than wrong.

- [ ] **Step 5: Create the JavaScript-enabled enhancement spec**

Create `e2e/tests/enhancement.spec.ts` (tab-indented, matching the other specs):

```ts
import { test, expect } from '@playwright/test';

/**
 * The two behaviours course-discovery.js adds beyond the combobox upgrade:
 * submitting on sort change, and collapsing the filter panel on a narrow
 * viewport. Both need JavaScript ON, so this spec belongs to the
 * 'js-enabled' project -- see playwright.config.ts. Neither is reachable
 * from PHPUnit, which is why they live here.
 */

test.describe('sort auto-submits when JavaScript is on', () => {
	test('changing sort reloads with the new order applied', async ({ page }) => {
		await page.goto('/find-courses/');

		const sort = page.locator('#cd-sort');
		await expect(sort).toHaveValue('soonest');

		// No Search click: the change event alone must submit the form.
		await Promise.all([
			page.waitForURL((url) => url.searchParams.get('sort') === 'price_asc'),
			sort.selectOption('price_asc'),
		]);

		await expect(page.locator('#cd-sort')).toHaveValue('price_asc');
	});

	test('toggling a combobox option does not submit the form', async ({ page }) => {
		await page.goto('/find-courses/');

		const urlBefore = page.url();
		const trigger = page.locator('#cd-filter-location');

		await trigger.click();
		await page.locator('#cd-filter-location-listbox [role="option"]').first().click();

		// The decisive assertion: the combobox dispatches a bubbling 'change'
		// on each toggle, so a form-level listener would have navigated here.
		// Scoping the sort listener to the select itself is what prevents it.
		expect(page.url()).toBe(urlBefore);
		await expect(page.locator('#cd-filter-location-listbox')).toBeVisible();
	});
});

test.describe('filter panel on a narrow viewport', () => {
	test('collapses so results are on screen first', async ({ page }) => {
		await page.setViewportSize({ width: 480, height: 900 });
		await page.goto('/find-courses/');

		const filters = page.locator('.cd-filters');

		await expect(filters).toBeVisible();
		await expect(filters).not.toHaveAttribute('open', '');

		// The summary is a real disclosure control: it still opens.
		await page.locator('.cd-filters-summary').click();
		await expect(filters).toHaveAttribute('open', '');
	});
});
```

Then register it in `e2e/playwright.config.ts` — change the `js-enabled` project's `testMatch` to:

```ts
			testMatch: ['keyboard.spec.ts', 'enhancement.spec.ts'],
```

Without this the file matches no project and never runs, while still reporting green.

The first test closes a real verification gap: Task 3 implemented the sort auto-submit but had no browser available to confirm the `change` listener fires. The second pins the scoping decision behind it — a form-level listener would reload the page mid-multi-select.

- [ ] **Step 6: Run the e2e suite**

```bash
cd e2e && npx playwright test
```

Expected: all specs pass across both projects (default and `js-disabled`). If the collapse test fails because `.cd-filters` still has `open`, confirm `matchMedia('(max-width: 48rem)')` matches at 480px — `48rem` is 768px at a 16px root, so it should.

- [ ] **Step 7: Run the full PHP gate one final time**

```bash
ddev composer test && ddev composer stan
```

Expected: no `FAILURES!` / `ERRORS!`, `No errors`.

- [ ] **Step 8: Commit**

```bash
git add plugins/course-discovery/assets/course-discovery.js e2e/tests && git commit -m "feat: collapse filters on small screens"
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: structure → 2; sort → 3; pagination window → 1; chips + mobile collapse → 5 and 7; the `aria-live` move → 2; CSS and the token table → 6; the file list → all; the test plan → each task's own tests plus 7; verification → every task's gate step; rollback → seven commits, each individually revertable.

**Two deliberate deviations from the spec, both noted inline above:**
1. `SearchUrls` is a seventh file. The spec moves "Clear all" into the chips block, which would otherwise duplicate `knownQueryKeys()` across two renderers.
2. The spec said desktop CSS "hides the disclosure marker and styles the summary as a section heading". Task 6 keeps the marker at every width instead: `<summary>` still toggles on click, so hiding the affordance would leave a heading that collapses when clicked.

**Type consistency.** `renderCount(SearchResult)`, `renderHero(FilterRegistry, SearchCriteria)`, `renderFilters(FilterRegistry, SearchCriteria, int)`, `renderSortControl(SearchCriteria)`, `render(FilterRegistry, SearchCriteria, string)` and `activeCount(FilterRegistry, SearchCriteria)` are used with exactly these signatures wherever they appear. `SearchUrls::knownKeys()`/`clearFilters()` are named identically at both call sites (they are *not* `knownQueryKeys()`/`clearFiltersUrl()`, the private names they replace). Both new classes take `SearchUrls` as their sole constructor argument.

**Known test edits, all called out in the step that causes them:** `ResultsRendererTest`'s count assertion (Task 2), `FormRendererTest`'s constructor and render call (Tasks 2 and 4), `ShortcodeTest`'s two sort tests replaced (Task 3) and its clear-filters test given a real provider id (Task 5), `keyboard.spec.ts`'s tab budget (Task 7).
