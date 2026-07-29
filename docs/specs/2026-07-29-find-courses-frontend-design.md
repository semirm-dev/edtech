# Find Courses frontend redesign

**Date:** 2026-07-29
**Status:** Approved, not yet implemented
**Scope:** Presentation layer of the `[course_discovery]` shortcode, plus three
UX gaps that styling alone cannot close.

## Why

The frontend was built deliberately unstyled: `assets/course-discovery.css` is
125 lines that declare themselves *"layout only, no visual opinion"*. The markup
is already semantic, accessible and class-hooked, so a visual redesign is mostly
a CSS exercise. Three functional gaps surfaced while reading the code, and none
of them can be fixed with CSS:

1. **No visible sort control.** `SortOrder` has three cases and
   `WpCourseRepository` honours all of them, but the form only emits a hidden
   input to preserve a sort that arrived in the URL
   (`FormRenderer::renderSortState()`). A visitor cannot change the sort.
2. **Pagination renders every page.** `renderPagination()` loops
   `1..$totalPages`, so a 400-course result set emits 40 links.
3. **No active-filter summary.** Nothing states what is currently applied except
   the controls themselves, and on narrow viewports the five filter fieldsets
   push all results below the fold.

## Decisions

| Decision | Choice | Reasoning |
| --- | --- | --- |
| Layout | Hero search + facet sidebar + results toolbar | Gives the count and the new sort control a home; keeps keyword search — the first thing most visitors reach for — visible rather than fifth in a sidebar. |
| Filter controls | Stay as native inputs | A horizontal bar of dropdown pills would need JavaScript to open, which breaks the plugin's no-JS baseline. |
| Result rows | Keep the existing four fields | Provider/category pills were considered and rejected: they would need a new resolver class and two extra queries for presentation-only gain. |
| Colour and type | Inherit Twenty Twenty-Five presets, with fallbacks | Looks native in the active theme without going colourless in a theme that defines no presets. |

## Structure

The wrapper element becomes the form. This is the one structural change with
reach, and it is what allows the sort control to sit in the results toolbar
while remaining a field of the same form:

```html
<form class="cd-discovery cd-search-form" method="get" data-cd-root>
  <div class="cd-search-hero">      <!-- keyword field + Search button -->
  <div class="cd-active-filters">   <!-- removable chips; omitted when none active -->
  <details class="cd-filters" open> <!-- <summary>Filters (3)</summary> + 4 fieldsets + Apply -->
  <div class="cd-results-region">
    <div class="cd-toolbar">        <!-- count (aria-live) + sort select -->
    <div class="cd-results">        <!-- list + pagination, or empty state -->
  </div>
</form>
```

`.cd-toolbar` is composed by `Shortcode::render()` from two pieces: a new
`ResultsRenderer::renderCount(SearchResult): string` (the `aria-live` count
paragraph, extracted from `render()`) and `FormRenderer::renderSortControl()`.
`ResultsRenderer::render()` keeps the list, the pagination, the empty state and
the out-of-range message, and no longer emits the count itself.

Results move inside the form. They contain only `<a>` elements, so nothing
nests illegally, and pressing Enter in the search field submits as expected.

`display: contents` was rejected as an alternative for placing form children
into the parent grid: it has documented accessibility regressions, and making
the form itself the grid container achieves the same layout with no caveats.

The keyword filter is selected out of `$registry->all()` by matching
`SearchCriteria::PARAM_TERM` (`'q'`, `KeywordFilter::KEY`) and rendered in the
hero slot. The remaining four filters render in the sidebar unchanged. Matching
on the key rather than on `FilterInputType::Text` is deliberate: a
third-party text filter registered via `course_discovery/register_filters`
should land in the sidebar, not silently take over the hero.

## Gap 1 — Sort control

`FormRenderer::renderSortControl(SearchCriteria $criteria): string` emits a
`<label for>` plus `<select name="sort">` with one `<option>` per `SortOrder`
case, the current sort marked `selected`.

Human labels map enum case → `__()` string in a private method on
`FormRenderer`. They cannot live on the enum: `src/Domain/` must contain no
WordPress, and the architecture suite enforces it.

- Soonest → "Starting soonest"
- PriceAscending → "Price: low to high"
- Title → "Title A–Z"

`renderSortState()` and its hidden input are deleted. The select always emits
`sort`, so the hidden input becomes a duplicate field on every submit.

**Progressive enhancement:** with JavaScript off the select is applied by
pressing Search, like any other field. `course-discovery.js` adds a `change`
listener that calls `form.requestSubmit()` so the sort applies immediately.

## Gap 2 — Condensed pagination

`renderPagination()` keeps its early return when `$totalPages <= 1`.

The set of page numbers to link is:

```
{ 1, current - 1, current, current + 1, totalPages } ∩ [1, totalPages]
```

deduplicated, ascending. An ellipsis is inserted between any two consecutive
entries in that set whose values differ by more than 1. Result: at most five
number links and two ellipses, e.g. `‹ Prev · 1 · … · 4 5 6 · … · 12 · Next ›`.

- Prev is omitted when `current === 1`; Next when `current === totalPages`.
- Prev/Next carry `rel="prev"` / `rel="next"` and the accessible names
  "Previous page" / "Next page".
- Ellipsis is `<span class="cd-pagination-gap" aria-hidden="true">…</span>`.
- The current page keeps rendering as an `<a>` with `aria-current="page"`,
  unchanged. This preserves the existing `getByRole('link', { name: 'Page 1' })`
  e2e assertions, and `aria-current` already communicates the state.

The existing out-of-range branch in `render()` is untouched.

## Gap 3 — Active filter chips and mobile collapse

New class `Frontend/ActiveFiltersRenderer`, constructed with no dependencies and
called from `Shortcode::render()` with the registry, criteria and base URL — the
same three values `ResultsRenderer` already receives.

For each registered filter except the hero keyword filter, for each selected
value, it emits a link that removes that one value:

- **Label** comes from matching the value against `$filter->options($criteria)`,
  which is already in memory — no new queries. A selected value with no matching
  option (a stale term id in a bookmarked URL) is **skipped entirely** rather
  than rendered as a raw slug.
- **Href** is built from `$criteria->toQueryParams()` with that value removed
  from its key's list; if the list empties, the key is unset. `PARAM_PAGE` is
  always unset — removing a filter should return to page 1. Resolved with
  `add_query_arg($params, $baseUrl)` against an explicitly threaded base URL,
  for the reason given in `ResultsRenderer`'s docblock.
- The keyword term gets no chip: it is already visible in the hero field.
  Sort gets no chip either.
- When no chips would render, the whole `.cd-active-filters` block is omitted,
  including its "Clear all" link — there is nothing to clear.

"Clear all" **moves** into `.cd-active-filters` from `.cd-search-actions`, next
to the chips it clears, and keeps its existing `.cd-clear-filters` class so the
current e2e assertion still resolves. Its href still comes from
`clearFiltersUrl()`, which is built on `remove_query_arg()` and so already
preserves params the plugin does not own.

**Mobile collapse:** the sidebar is a `<details open>` whose `<summary>` reads
"Filters (N)", N being the number of active chips. When N is 0 the summary reads
just "Filters", with no count in parentheses. `course-discovery.js` removes
the `open` attribute when `matchMedia('(max-width: 48rem)')` matches, so results
are visible immediately on a phone. With JavaScript off the filters are simply
expanded — today's behaviour. On desktop CSS hides the disclosure marker and
styles the summary as a section heading.

CSS cannot toggle `open`, which is why this one enhancement is JavaScript rather
than a media query.

## Accessibility change

`aria-live="polite" aria-atomic="true"` currently wraps the entire `.cd-results`
div. Combined with `aria-atomic`, any future swap would make a screen reader
re-announce the whole result list.

It moves onto the count paragraph alone, which is the element
`ResultsRenderer`'s docblock says it exists for, and which is what allows the
count to sit in the toolbar beside the sort control.

This is safe today: `course-discovery.js` contains no `fetch`, `XMLHttpRequest`
or `innerHTML`, so results are never swapped client-side. Every state change is
a full page load.

## CSS

`assets/course-discovery.css` is rewritten, roughly 400 lines. Tokens are
declared once on `.cd-discovery` so the plugin never writes a bare colour into a
rule, and every token falls back:

| Token | Source | Fallback |
| --- | --- | --- |
| `--cd-text` | `--wp--preset--color--contrast` | `#111111` |
| `--cd-text-muted` | `--wp--preset--color--accent-4` | `#686868` |
| `--cd-bg` | `--wp--preset--color--base` | `#ffffff` |
| `--cd-surface` | `--wp--preset--color--accent-5` | `#fbfaf3` |
| `--cd-accent` | `--wp--preset--color--accent-3` | `#503aa8` |
| `--cd-highlight` | `--wp--preset--color--accent-1` | `#ffee58` |
| `--cd-border` | `--wp--preset--color--accent-6` | `color-mix(in srgb, currentColor 20%, transparent)` |
| `--cd-space-*` | `--wp--preset--spacing--30…60` | `rem` values |
| `--cd-font-*` | `--wp--preset--font-size--small…x-large` | `rem` values |

`accent-3` is the interactive colour (links, focus ring, Search button) because
it is the only accent with text-grade contrast on `base`. `accent-1` is used
only as a chip background behind `--cd-text`, never as a text or border colour.

Layout is `grid-template-areas` on `.cd-discovery` with a single breakpoint at
`48rem`: one column below it, `18rem 1fr` above, hero and chips spanning both.

Kept from the current file: the `:focus-visible` rule, `.cd-visually-hidden`,
and the whole `.cd-combobox*` block, which the JS combobox upgrade depends on.
Any transition is wrapped in a `prefers-reduced-motion: no-preference` guard.

## Files

| File | Change |
| --- | --- |
| `src/Frontend/Shortcode.php` | Wrapper becomes `<form>`; composes hero, chips, filters, toolbar, results |
| `src/Frontend/FormRenderer.php` | Hero/sidebar split, `renderSortControl()`, `<details>` wrapper, `renderSortState()` deleted |
| `src/Frontend/ResultsRenderer.php` | Condensed pagination; count extracted to `renderCount()` with `aria-live` on it |
| `src/Frontend/ActiveFiltersRenderer.php` | New |
| `assets/course-discovery.css` | Rewritten |
| `assets/course-discovery.js` | Sort auto-submit, mobile `<details>` close |

## Tests

The pagination window and the chip-removal URL are pure, edge-case-heavy logic,
so both get **failing tests written first**.

- **Pagination window:** 1 page (renders nothing), 2, 3, `current=1 of 12`,
  `current=6 of 12`, `current=12 of 12`, and the existing out-of-range branch.
  Assert ellipsis placement and that Prev/Next are absent at the ends.
- **Chip removal:** removing one value from a multi-value key, removing the last
  value (key unset), `page` always dropped, stale value skipped, keyword and
  sort never chipped, empty block omitted.
- **Updated:** `FormRendererTest` (sort select present, hidden sort input gone,
  hero/sidebar split), `ResultsRendererTest`, `ShortcodeTest` (form wrapper and
  composition order).
- **New:** `ActiveFiltersRendererTest`.
- **Architecture suite** must still pass unchanged — the new class is in
  `Frontend/` and touches no `Domain/` rule.
- **E2E:** `no-js.spec.ts` gains sort-by-Search-button coverage;
  `pagination.spec.ts` gains Prev/Next and ellipsis; `keyboard.spec.ts` gains
  the `<details>` toggle and chip links in the tab order.

## Verification

```bash
ddev composer test
ddev composer stan
```

Judge `test` by assertion counts and the absence of `FAILURES!` / `ERRORS!` —
the integration and architecture suites end with `OK, but there were issues!`
from Yoast Polyfills' doc-comment deprecations. `stan` must report
`No errors`. E2E runs separately against a seeded site.

No schema, index or option changes, so `course-discovery reindex` is not
required.

## Out of scope

Explicitly not in this change: provider/category pills on result rows, AJAX or
client-side result swapping, saved searches, a results-per-page control, course
featured images, and any change to `src/Domain/`, the index tables or the query
layer.

## Rollback

A single commit touching six source files plus their tests, with no database,
schema or option state involved. `git revert` restores the previous behaviour
completely.
