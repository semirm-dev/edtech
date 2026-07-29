# Course Discovery — testing strategy

What is tested, where the risk is, how new filters are covered. Counts are verbatim command output, re-run 2026-07-29.

## Layers

| Layer | Command | Requires | Result (2026-07-29) |
|---|---|---|---|
| Unit | `ddev composer test:unit` | Nothing | **136 tests, 260 assertions** |
| Integration | `ddev composer test:integration` | WordPress + MariaDB (wp-phpunit) | **268 tests, 527 assertions** (28 deprecations) |
| Architecture | `ddev composer test:arch` | Nothing | **59 tests, 59 assertions** (2 deprecations) |

Those deprecations are Yoast PHPUnit Polyfills' doc-comment metadata, not this
project's code. Total **463 tests, 846 assertions**; `ddev composer stan` (PHPStan level 9) reports **No errors**.

- **Unit** (`tests/Unit/`) — domain only: value objects, `SearchCriteria` URL
  serialisation, filter value objects, the DI container, `Constraint` types.
  `bootstrap-unit.php` loads only the autoloader; `src/Domain/` has no WordPress.
- **Integration** (`tests/Integration/`) — indexer, invalidation hooks,
  repository and where-clause builder against real MariaDB, the five filters,
  shortcode frontend, migrations, example extension. Boots real WordPress, so it
  catches hook ordering, `$wpdb` query shape and ACF meta quirks.
- **Architecture** (`tests/Architecture/DomainPurityTest.php`) — one file
  enforcing domain purity by scanning real source files, not by convention.
- **E2E** (`e2e/`, Playwright) — standalone Node project, no `composer.json`
  dependency, driving a browser against a seeded site. **3 specs**:
  `no-js.spec.ts` (JavaScript disabled, narrowing via the plain
  `<form method="get">`), `keyboard.spec.ts` (combobox by keyboard alone) and
  `pagination.spec.ts` (page 2 over real HTTP — see below).

## High-risk areas

- **Provider-location indexer staleness** — a provider's location term changing
  leaves attached courses holding a stale `location` row despite never being
  edited. `IndexInvalidatorTest::test_changing_a_provider_location_reindexes_its_courses()`.
- **AND/OR composition** — AND between groups, OR within a group's values,
  held structurally, not by accident of fixture data. `WpCourseRepositoryTest::test_values_within_one_filter_are_combined_with_or()`,
  `test_separate_filters_are_combined_with_and()`.
- **Chronological ordering** — `StartDate::sortKey()` stores an integer
  (`202603`), so order is correct by construction, not by string comparison
  happening to work. `WpCourseRepositoryTest::test_results_order_by_soonest_start_date()`.
- **Rows present but unfindable** — InnoDB gives every FULLTEXT row a hidden
  `FTS_DOC_ID` and filters deleted ids out of every `MATCH`. Empty the lookup
  table with `DELETE` and the doc-id counter can restart while those ids are
  still listed, so the next generation of rows is invisible to search despite
  correct `search_text` — `LIKE` finds them, `MATCH` never does. `indexAll()`
  therefore `TRUNCATE`s (which resets the FTS auxiliary tables with the data),
  pinned by `ReindexCommandTest::test_index_all_discards_rows_for_courses_that_no_longer_exist()`.
- **Reserved WordPress query vars** — `SearchCriteria::PARAM_PAGE` is
  `cd_paged`, not `paged`, because `redirect_canonical()` 301s `?paged=2` on a
  shortcode-hosting page to the pretty `/page/2/`, dropping the parameter and
  re-rendering page 1 under a page-2 URL. Invisible to PHPUnit, which renders
  the shortcode with no HTTP layer in front of it, so it is pinned in E2E:
  `pagination.spec.ts` asserts the parameter survives the navigation and that
  page 2's result set shares no course with page 1's.
- **XSS, two paths** — the reflected search term
  (`ShortcodeTest::test_it_escapes_a_reflected_search_term()`) and editor-authored
  option labels (`test_a_provider_name_containing_markup_is_escaped_in_checkbox_options()`,
  `test_a_location_name_containing_markup_is_escaped_in_combobox_options()`).
  The location case writes straight into the terms table, because `pre_term_name`
  strips tags on save; it must prove `FormRenderer`'s `esc_html()` does the work.
- **Keyword double-application** — `KeywordFilter::KEY` equals
  `SearchCriteria::PARAM_TERM` (`q`), so the term could be applied both as
  `SearchCriteria::term()` and as an ordinary filter, doubling the
  `MATCH ... AGAINST`; counting rows cannot detect that, as the row set is
  identical. `SearchServiceTest::test_the_keyword_term_is_applied_to_the_query_exactly_once()`
  captures the `ConstraintSet` via `course_discovery/constraints` and asserts exactly one
  `SearchTextConstraint`; `test_the_term_never_appears_in_the_services_active_filter_keys()` covers the service layer.

## Regression prevention

- **The worked AND/OR example, as a permanent test.** `WpCourseRepositoryTest::test_the_canonical_worked_example_composes_correctly()`
  builds provider IN (uosd, dmu) AND location IN (india) and asserts the match
  set is exactly the seeded courses satisfying both groups.
- **Mutation-verification — a found bug gets a test proven to fail first.**
  `IndexInvalidator::onPostDeleted()` once issued two `DELETE`s for every
  deleted post site-wide; its pinning test asserts the index tables are never
  touched for an unrelated post type, not merely that they stay empty — which
  would have passed before the fix.
- **The self-testing purity guard.** `test_domain_file_contains_no_wordpress_references()`
  scans `src/Domain/` for WordPress patterns (`wp_*()`, `WP_*`, `$wpdb`, hooks,
  `esc_*()`, `ABSPATH`) after stripping comments with the PHP tokenizer, so prose
  naming an API is not a false positive. A pattern no real file trips is unverified,
  so `test_pattern_detection_mechanism()` proves each family reachable, plus negatives.
- **PHPStan and the tests are one gate.** Level 9 runs across the whole plugin,
  not just the domain layer, and is not an advisory extra.

## How new filters are tested

`tests/Support/FilterContractTestCase.php` is an abstract case a filter opts
into by implementing two methods:

```php
abstract protected function makeFilter(): Filter;
abstract protected function validValues(): FilterValues;
```

That inherits eight `final` contract tests: `test_contract_key_is_stable`,
`_label_is_non_empty`, `_empty_values_produce_no_constraint`,
`_valid_values_produce_a_constraint`, `_garbage_values_do_not_throw` (feeds `'0'`, `'-1'`, `'abc'`, `'<script>'`, `"' OR 1=1"` through `constrain()`),
`_options_are_consistent_with_input_type` (an advertised option must be actionable, not just well shaped),
`_options_accepts_an_optional_criteria_context`, `_description_does_not_throw`. All five core filters extend it.

`plugins/course-discovery-example-extension/` adds an `instructor` filter via
`course_discovery/register_filters` and the public
`CourseIndexer::addAttributeValues()` API, with zero changes under
`plugins/course-discovery/src/`. `ExampleExtensionTest` proves the registry holds it
plus the five core filters unchanged, and that indexing and constraints work.

**Gap:** `ExampleExtensionTest` extends `IntegrationTestCase`, not
`FilterContractTestCase` — it hand-writes assertions mirroring the contract
rather than inheriting them. It could use the shared base directly.

See also: [`architecture.md`](architecture.md) · [`performance.md`](performance.md) · [`e2e/README.md`](../e2e/README.md)
