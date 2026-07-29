# Course Discovery — performance

Query counts and `EXPLAIN` output below are real, run against this project's
dev DB — but it holds only 2 seeded courses, so claims about large volumes are
reasoning from the schema and query plan, not measurements.

## Why native `meta_query` can't do this

`meta_query` operates on `wp_postmeta`, a flat key/value table on one post:

1. **Serialised arrays force `LIKE`.** ACF relationship fields serialise as PHP
   arrays, so "course has provider 87" means `meta_value LIKE '%"87"%'` —
   leading wildcard, no index.
2. **Each extra filter adds a self-join.** `relation => 'AND'` joins
   `wp_postmeta` onto itself once per clause, each with that scan cost.
3. **Derived location is inexpressible** — the decisive one. Location lives on
   the *provider* (`cd_location` term), not the course, so filtering needs
   course → provider → location, a two-hop traversal `meta_query` has no syntax
   for. Not slow: impossible in one query.

`CourseIndexer::locationsForProviders()` resolves that traversal once at write
time into a `location` attribute row, making the read a flat single-hop
`EXISTS` like every other filter. `coursesForProvider()` still pays the point-1
`LIKE` scan at write time, and its docblock records the trap: ACF writes the
same value two
ways — `s:2:"87";` from the admin UI, `i:87;` from `update_post_meta()` or
`wp post meta update --format=json` — so both need an `OR`'d pair of `LIKE`
clauses, or courses saved the second way silently vanish from reindex.

## Index shape and `EXPLAIN` evidence

`wp_cd_course_attribute_lookup` holds one row per `(course_id, attribute,
value_id)` rather than a table per filter dimension, so a third-party filter
adds a dimension by writing rows under its own `attribute` string — no
`ALTER TABLE`, no builder change (proven from a separate plugin by
`ExampleExtensionTest::test_indexing_a_course_writes_instructor_attribute_rows_via_the_public_api()`).
`M001CreateLookupTables` gives it `KEY attribute_lookup (attribute, value_id,
course_id)` so the lookup is index-only. Re-run 2026-07-29 (MariaDB 10.11,
`wp_` prefix) with the page query `WpCourseRepository::search()` produces for
`AttributeInConstraint('provider', [87])`, default `Soonest` ordering:

```sql
EXPLAIN SELECT i.course_id
FROM wp_cd_course_meta_lookup i
WHERE (EXISTS (SELECT 1 FROM wp_cd_course_attribute_lookup f
               WHERE f.course_id = i.course_id
                 AND f.attribute = 'provider'
                 AND f.value_id IN (87)))
ORDER BY i.earliest_start_ym IS NULL, i.earliest_start_ym ASC, i.course_id ASC
LIMIT 12 OFFSET 0;
```

```
id  select_type  table  type    possible_keys             key               key_len  ref             rows  Extra
1   PRIMARY      f      ref     PRIMARY,attribute_lookup  attribute_lookup  138      const,const     1     Using where; Using index; Using temporary; Using filesort
1   PRIMARY      i      eq_ref  PRIMARY                   PRIMARY           8        db.f.course_id  1
```

- MariaDB flattens the correlated `EXISTS` into a semi-join, driving from the
  attribute table (`table: f`).
- `type: ref` + `Using index` — a covering-index read, no row fetch.
- `wp_cd_course_meta_lookup` joins back `eq_ref` on `PRIMARY`, the cheapest
  join type.
- `Using temporary; Using filesort` is the `ORDER BY`, not the lookup.

## Hydration: 17 → 3 queries per page

Naive per-course hydration (`get_post()` plus one attribute query per type per
course) measured **17 queries** beyond fixture setup for a 5-course page: 15
attribute queries (3 types × 5 courses), the page `SELECT`, and the COUNT query
— `get_post()` already free because fixtures had warmed the object cache, so an
uncached request would cost more. Batching all attribute rows into one grouped
query (`attributesForCourses()`) plus priming the post cache up front
(`_prime_post_caches()`) brings this to **3 queries** — page `SELECT`, COUNT,
one grouped attribute query — flat regardless of page size. Pinned by
`WpCourseRepositoryTest::test_hydrating_a_page_does_not_scale_queries_with_course_count()`,
asserting the delta stays `<= 6`: a ceiling above the measured 3, so the test
guards the *shape* of the cost, not a brittle exact count.

## Write-time reindex cost

Changing a *provider's* location term rewrites the `location` row of every
course attached to it, though no course was edited — caught by
`IndexInvalidator::onTermsSet()` on `set_object_terms`. `coursesForProvider()`
has no pagination (`posts_per_page => -1`) and each `indexCourse()` costs
several queries, so above `SYNC_REINDEX_LIMIT` (200 courses) the reindex defers
to a single WP-Cron event (`course_discovery/reindex_provider_courses`) — 200
being a judgement call below PHP timeout territory but above an ordinary
provider, so the common case stays synchronous.

## Caching opportunity

`WpCourseRepository::attributeValues()` runs `SELECT DISTINCT value_id …`
uncached — no `wp_cache_*` call exists anywhere in
`plugins/course-discovery/src/` — yet `StartDateFilter::options()` calls it on
every search-form render, though the attribute table only changes on reindex.
The object cache, invalidated from `CourseIndexer` on
`course_discovery/indexed_course`, would make it per-reindex rather than
per-render. Documented, deliberately not built.

## Pagination

`Pagination` bounds page size to 1–100 (`MAX_PER_PAGE`) and page to 1–10,000
(`MAX_PAGE`); `clamp()` coerces untrusted `?paged=`/`?per_page=` into range
rather than throwing, so a hostile request degrades to a sane page instead of
fataling a public endpoint. Offset pagination is used throughout (`LIMIT %d
OFFSET %d`): `OFFSET` counts past every skipped row, so cost grows with depth
and `MAX_PAGE` bounds that rather than making deep pages cheap. Keyset
pagination (`WHERE (earliest_start_ym, course_id) > (?, ?)`) removes that cost
but loses "jump to page N" — a trade-off, not a pure win, hence the default.

## FULLTEXT limits

`SearchTextConstraint` becomes `MATCH … AGAINST (%s IN BOOLEAN MODE)` on the
InnoDB `FULLTEXT KEY` from `M001CreateLookupTables`. `innodb_ft_min_token_size`
is 3 here (a server default, not plugin config), so "UX"/"AI" are never indexed
and miss *silently*. `WhereClauseBuilder::buildSearchText()` strips boolean
operators (`preg_replace('/[+\-><()~*"@;]+/', ' ', …)`) rather than escaping
them, and input stripping to nothing fails *closed* to `0=1` rather than the
whole catalogue — both pinned in `WhereClauseBuilderTest`. Relevance ranking,
typo tolerance, or acronym search are the triggers for a dedicated index
(Elasticsearch, Meilisearch, or MariaDB's `ngram` parser as a lighter step).

## What degrades first at scale

Reads stay `O(matched rows)` at 2 rows or 2 million — `attribute_lookup`'s
leading `(attribute, value_id, …)` confines each filter to a narrow index range,
joined back by primary key. In order of what breaks first:

1. **`ORDER BY` on a large matched set** — the filesort above is already there
   at 2 courses, and the driving table is the semi-join, so no index helps.
2. **The `COUNT(*)` query** — on a broad filter it still counts every matching
   row, so cost scales with matches, not the page returned; a capped or
   estimated count is the escalation.
3. **Provider reindex fan-out** — `SYNC_REINDEX_LIMIT` anticipates it, but one
   WP-Cron event is not a reliable job runner; a real queue is next.
4. **FULLTEXT's ceiling**, then denormalising further (materialising whole
   result rows, not just ids) or moving text search out entirely.

See also [architecture.md](architecture.md) for layering and extension points,
and [testing-strategy.md](testing-strategy.md) for how these tests fit.
