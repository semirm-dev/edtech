# Course Discovery — architecture

## 1. The three-layer boundary

Dependencies point one way: `Query` → `Index` / `ContentModel` /
`Filter\Support` (the WordPress adapters) → `Domain` (pure PHP). Unit tests of
`src/Domain/` therefore need no database and no bootstrap
(`tests/bootstrap-unit.php` loads only the autoloader), and `src/Query/`
(`WhereClauseBuilder`, `WpCourseRepository`) is the only place a `Constraint`
becomes SQL, against the tables `src/Index/` maintains.

**Enforced rule: no file under `src/Domain/` may import WordPress.** The guard
(`tests/Architecture/DomainPurityTest.php`, in `test:arch`) tokenises each
domain file, discards comments so only executable code counts, and matches the
rest against forbidden patterns (`wp_*()`, `WP_*`, `$wpdb`, hooks, `esc_*()`,
i18n, options API, `ABSPATH`). It is self-tested: synthetic positive and
negative snippets run through the same detection function.

## 2. The domain model

| Object | File | Why it looks like this |
|---|---|---|
| `Money` | `Domain/Money.php` | Integer minor units plus a validated currency code — never a float; `format()` derives from the integer. |
| `StartDate` | `Domain/StartDate.php` | Year + month; `sortKey()` returns `202603`, so chronological ordering is integer comparison, not a callback. |
| `CoursePricing` | `Domain/CoursePricing.php` | Interface (`format()`, `lowestMinor()`) with one implementation, `SinglePrice`, so a later `PriceRange` is a new class, not a breaking change. |
| `Pagination` | `Domain/Pagination.php` | Constructor throws on out-of-range input; `clamp()` degrades untrusted `?cd_paged=` values to the nearest valid page. `MAX_PAGE = 10000` keeps `offset()` far from `PHP_INT_MAX`. |
| `SearchCriteria` | `Domain/Filter/SearchCriteria.php` | Immutable term + filter values + sort + pagination; `toQueryParams()`/`fromQueryParams()` make filter state shareable and back-button-safe. |
| `FilterKey`, `FilterValues`, `FilterOptions`, `FilterOption` | `Domain/Filter/` | Typed wrappers for filter identity and selections; `FilterValues::toInts()` discards non-positive-integer entries rather than throwing. |
| `CourseId` | `Domain/CourseId.php` | A validated post-id wrapper, so ids cannot be transposed unnoticed. |
| `SortOrder` | `Domain/SortOrder.php` | A backed enum, usable from pure-domain code. |

`Course` is the aggregate root: `id`, `title`, two descriptions, `pricing`,
`startDates`, and four `list<int>` id sets (`providerIds`, `instructorIds`,
`categoryIds`, `locationIds`). `locationIds` is derived from the course's
providers during indexing, so that rule lives only in `CourseIndexer`; the
four lists are indistinguishable to PHPStan, so the constructor docblock
requires named arguments. `CourseCollection`/`StartDateCollection` are `final`
`IteratorAggregate`s with generics and no shared base. Every file is
`declare(strict_types=1)`; PHPStan runs at **level 9** plugin-wide.

## 3. The filter system

```php
interface Filter {                          // Domain/Filter/Filter.php
    public function key(): FilterKey;
    public function label(): string;
    public function inputType(): FilterInputType;
    public function options(?SearchCriteria $context = null): FilterOptions;
    public function description(): ?string;
    public function constrain(FilterValues $values): ?Constraint;
}
```

`inputType()` returns `Text | CheckboxGroup | ComboboxMulti`, keeping filters
presentation-agnostic. **There is no `AbstractFilter`:** the five core filters
(`KeywordFilter`, `ProviderFilter`, `LocationFilter`, `StartDateFilter`,
`CategoryFilter`, all `final` in `src/Filter/`) share behaviour by holding
collaborators — `Support\TermOptions` (location, category),
`Support\PostTypeOptions` (provider, and the extension's instructor filter),
and `FilterOptionsHook`, so no `options()` can forget the per-filter hook.

`constrain()` returns a `?Constraint`, an empty marker interface:
`AttributeInConstraint` ("any of these value ids for this attribute") is the
workhorse, `SearchTextConstraint` covers free text, `RawConstraint` is a
`literal-string`-annotated escape hatch whose docblock notes the annotation
guarantees nothing about third-party callers. `WhereClauseBuilder` turns each
into a `%d`/`%s`-templated `EXISTS` subquery via `$wpdb->prepare()`, and
parenthesises every fragment before joining with `AND` so a top-level `OR`
cannot escape it. `FilterRegistry::boot()` registers the core set then fires
`course_discovery/register_filters`; a duplicate key does not throw (a throwing
public callback would fatal the page and abort every extension behind it) —
first registration wins, later ones get `_doing_it_wrong()`.

## 4. The index

Native storage cannot answer these queries:

- ACF relationship fields serialise as PHP arrays in `postmeta`, forcing an
  unindexed `LIKE` scan.
- Each extra `meta_query` clause is another self-join onto `postmeta`.
- Decisively: a course's location lives on its *provider*, a two-hop
  `meta_query` cannot express at all.

Two tables, resolved through `$wpdb->prefix` by `Index/Schema.php`:
`{prefix}cd_course_meta_lookup` (one row per course — `course_id`,
`price_minor`, `earliest_start_ym`, `search_text` `FULLTEXT`, `title`) and
`{prefix}cd_course_attribute_lookup` (many rows per course, PK `(course_id,
attribute, value_id)` plus covering index `attribute_lookup (attribute,
value_id, course_id)` for the `EXISTS` pattern). `attribute` is a plain string
column — `Index\Attribute` names the built-ins (`provider | location | start |
category`) but `AttributeInConstraint` takes a bare string, so a new filterable
dimension needs **no migration**, just a new value in an existing column (the
example extension writes `attribute = 'instructor'`). `CourseIndexer::indexCourse()`
rebuilds one course's rows in a transaction (or a `SAVEPOINT` when already
inside one); `IndexInvalidator` decides *when*:

| WordPress hook | Why this one |
|---|---|
| `wp_after_insert_post` | Fires after every `save_post_*`, so ACF and the start-date meta box have already committed. |
| `before_delete_post` / `deleted_post` | The first captures the post type while still readable; the second cleans up after the row is gone. |
| `trashed_post` | Trashing a provider still needs its courses reindexed. |
| `set_object_terms` | A provider's `cd_location` change edits no course, yet every attached course's location goes stale. |
| `edited_cd_course_category` / `delete_cd_course_category` | Categories are indexed with their ancestor chain, so descendants reindex too. |
| `delete_cd_location` | Reindexes every provider that carried the term, and their courses. |

Above `SYNC_REINDEX_LIMIT = 200` courses, provider reindexing defers to one
`wp_schedule_single_event()` on `course_discovery/reindex_provider_courses`
rather than thousands of `indexCourse()` calls in one page load; full rebuild
is `wp course-discovery reindex` (`ReindexCommand`, `WP_CLI` only), which
`TRUNCATE`s both lookup tables before repopulating them — a genuine rebuild,
so it also clears rows for courses that no longer exist and resets InnoDB's
FULLTEXT auxiliary tables, whose deleted-`FTS_DOC_ID` list would otherwise
make freshly written rows unmatchable. Searches see an empty index while it
runs; `TRUNCATE` is DDL, so that window cannot be closed with a transaction.
`MigrationRunner` applies a version-ordered list from `src/Index/Migrations/`
(`M001CreateLookupTables`, `M002AlterAttributeValueUnsigned`,
`M003AddTitleColumn`), storing the version in the `cd_schema_version` option;
`runIfPending()` runs on `admin_init`, not only activation, so a site activated
before a later migration catches up. `WpCourseRepository::search()` runs the
page `SELECT i.course_id … ORDER BY … LIMIT/OFFSET`, then a separate `SELECT
COUNT(*) FROM {index} i WHERE <the same already-prepared where clause>`.

## 5. Extension points

| Hook | Type | Fired from | Signature | Use this to… |
|---|---|---|---|---|
| `course_discovery/register_filters` | action | `FilterRegistry::boot()` (`src/Filter/FilterRegistry.php:108`) | `(FilterRegistry $registry)` | Register a new `Filter` without touching an existing file. |
| `course_discovery/filter_options/{key}` | filter | `FilterOptionsHook::apply()` (`src/Filter/FilterOptionsHook.php:21`) | `(FilterOptions $options, string $key) → FilterOptions` | Add, remove or reorder one filter's choices. |
| `course_discovery/criteria` | filter | `SearchService::search()` (`src/Search/SearchService.php:52`) | `(SearchCriteria $criteria) → SearchCriteria` | Transform parsed criteria before any filter constrains; a non-`SearchCriteria` return is ignored. |
| `course_discovery/constraints` | filter | `SearchService::search()` (`src/Search/SearchService.php:72`) | `(ConstraintSet $set, SearchCriteria $criteria) → ConstraintSet` | Add, remove or replace constraints after every filter has contributed. |
| `course_discovery/order` | filter | `WpCourseRepository::search()` (`src/Query/WpCourseRepository.php:79`) | `(string $defaultOrderSql, SortOrder $orderBy, ConstraintSet $constraints) → string` | Customise ordering. **Security contract:** spliced verbatim into `ORDER BY`, so it must already be safe SQL assuming the index table is aliased `i`; a non-string or empty return falls back to the default. |
| `course_discovery/indexed_course` | action | `CourseIndexer::indexCourse()` (`src/Index/CourseIndexer.php:105`) | `(int $courseId, CourseIndexer $indexer)` | React once built-in attribute rows are committed — typically `addAttributeValues()` to add a custom dimension. |
| `course_discovery/build_constraint_sql` | filter | `WhereClauseBuilder::buildUnknown()` (`src/Query/WhereClauseBuilder.php:177`) | `(?string $sql, Constraint $c, wpdb $db, Schema $schema) → ?string` | Teach the builder a third-party `Constraint` type. **Security contract:** used verbatim and unescaped, so it must be `prepare()`-safe and internally parenthesised if it uses a top-level `OR`. |

`plugins/course-discovery-example-extension/` is an independent plugin: one
filter class (`InstructorFilter`) plus a bootstrap wiring two of those hooks,
`register_filters` and `indexed_course`. It imports only public classes and
changes nothing under `course-discovery/src/`; deactivating it removes the
Instructor filter from the UI, reactivating restores it.

## 6. Known limitations

- **Two text domains, deliberately** — `course-discovery` and
  `course-discovery-example`, kept apart so the extension reads as third-party.
- **`cd_location` archive slug** — the taxonomy sets `'rewrite' => ['slug' =>
  'location']`, but WordPress falls back to the internal name in some contexts,
  so `/cd_location/` still surfaces. Cosmetic: filtering keys on term ids.
- **Extension folder vs. text domain** — `course-discovery-example-extension/`
  vs. `course-discovery-example`; WordPress.org expects a match, but this
  extension is not distributed there.

Performance, `EXPLAIN` evidence and caching: `docs/performance.md`.
Test layers and the shared filter contract test: `docs/testing-strategy.md`.
