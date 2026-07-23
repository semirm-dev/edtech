# Course Discovery System — Design

**Date:** 2026-07-24
**Context:** Oxford International pre-interview task
**Time budget:** 7 days

---

## 1. What is being graded

The brief states its own priority: *"The primary focus of this exercise is software architecture, domain modelling, extensibility, and filter composition, rather than delivering a feature-complete or highly polished user interface."*

Nearly every requirement is a proxy for extensibility:

| Requirement | What it tests |
|---|---|
| "new filters without modification to existing filter implementation" | Open/Closed — registry plus interface, not a switch statement |
| "not pass primitives where richer domain concepts can be applied" | Value objects |
| "abstractions over WP_Query" | Keeping WordPress out of the domain layer |
| "composition favoured over inheritance" | No abstract base classes in the filter hierarchy |
| "document bottlenecks" (not fix them) | Understanding WordPress's limits |

Several sections ask for **prose, not code** — the performance and testing sections are documentation deliverables. They are cheap to score well on and easy to lose under time pressure.

**Priority order:** domain model and filter architecture → index tables and migrations → README (decisions, assumptions, performance, testing) → tests on filter composition → accessible frontend → polish.

---

## 2. Glossary

Terms used throughout, disambiguated because two of them collide.

- **Search filter** — a control the user interacts with to narrow results (provider, location, category). This is what the *task* means by "filter".
- **Hook** — WordPress's callback mechanism allowing third-party code to observe or modify behaviour. Two forms: `do_action()` ("this happened") and `apply_filters()` ("here is a value, you may change it"). This is what *WordPress* means by "filter". Unrelated to the above.
- **Post type** — a kind of content. WordPress ships Post and Page; custom ones (course, instructor, provider) each get generated admin screens.
- **Taxonomy** — a classification vocabulary applied to a post type (e.g. categories). Backed by an indexed many-to-many table.
- **Postmeta** — WordPress's generic key/value store for custom data attached to content.

---

## 3. Constraints and decisions

| Decision | Choice | Rationale |
|---|---|---|
| ACF edition | Free only, plus a custom meta box for start dates | ACF PRO is licence-gated and cannot be redistributed. A submission requiring a paid licence to run is not reproducible for the reviewer. |
| Repeating start dates | Custom meta box | Repeater is PRO-only. Building it ourselves also gives control over storage format, which matters for chronological ordering. |
| Frontend model | Progressive enhancement, URL-driven state | Native form controls are keyboard-accessible by default. Filter state in the URL gives shareable results and working back-button behaviour. `SearchCriteria` serialises both ways. |
| Storage and query | Denormalised index tables plus a custom repository | Location is derived from provider — a two-hop lookup WordPress meta queries cannot express. See §6. |
| Environment | Plain `docker-compose.yml` as the documented entry point | The brief names Docker Compose. Requiring DDEV adds an install step for the reviewer. DDEV may remain for local convenience. |
| Dependency management | Composer at project root | Needed regardless for PSR-4 autoloading, PHPUnit, PHPStan. Pins WordPress core and ACF versions. |
| Bedrock | Considered, rejected | Composer-managed WordPress would improve reproducibility, but the non-standard layout complicates the required public deployment and spends budget on infrastructure that is not being graded. Recorded in the README as a considered alternative. |
| Hook naming | Slash-namespaced (`course_discovery/criteria`) | Collision-proof and readable. A departure from classic underscore convention, so stated explicitly rather than silently. |

---

## 4. Content model

### Post types

| Post type | Purpose |
|---|---|
| `course` | Primary content |
| `instructor` | Required by the brief |
| `provider` | Required by the brief |

### Taxonomies

| Taxonomy | Applied to | Notes |
|---|---|---|
| `course_category` | `course` | Hierarchical, per the brief |
| `location` | `provider` | See assumption A1 |

### Course fields

| Data point | Storage |
|---|---|
| Name | Post title (native) |
| Long description | Post content (native) |
| Short description | Post excerpt (native) |
| Price | ACF number field |
| Instructors | ACF Relationship → `instructor` |
| Providers | ACF Relationship → `provider` |
| Start dates | Custom meta box |
| Categories | `course_category` taxonomy |
| Locations | Not authored — derived from providers at index time |

Native title/content/excerpt are used in preference to ACF equivalents. This yields the editor, revisions and native search integration without additional configuration.

### Admin dashboard

Post type registration supplies most of the required admin interface. Added on top: custom columns on the course list (providers, next start date, categories) and admin-side filtering.

---

## 5. Domain model

**Invariant: no file in the domain layer imports WordPress.** No `WP_Query`, no `get_post`, no `wp_*` calls. WordPress appears only behind repository interfaces in the infrastructure layer. This is what makes the domain unit-testable without booting WordPress.

### Value objects

| Object | Notes |
|---|---|
| `StartDate` | Holds year and month. `sortKey(): int` returns `202603`; `toDisplay(): string` returns `March 2026`. The integer is stored; the display string is always derived. Chronological ordering is correct by construction. |
| `CoursePricing` (interface) | `format()` and `lowestMinor()`. One implementation, `SinglePrice`, holding an integer amount in minor units plus a `Currency`. Never a float. |
| `SearchCriteria` | Immutable. Optional `SearchTerm`, map of filter key → `FilterValues`, `SortOrder`, `Pagination`. Mutations return new instances. Carries `toQueryParams()` / `fromQueryParams()`. |
| `FilterKey`, `FilterValues`, `FilterOptions` | Typed wrappers around filter identity and selections |
| `CourseId`, `Slug`, `SearchTerm`, `Location`, `CategoryId`, `Pagination` | Supporting types |
| `SortOrder` | Backed enum |

`CoursePricing` is an interface with a single implementation deliberately. The brief notes price "can be extended to support range or multiple price points". Committing to the interface now means a future `PriceRange` is a new class rather than a breaking change to `Course` and every consumer. Ranges are **not** implemented — no requirement filters on price, so range-overlap query logic would be machinery nothing calls.

### Entities

`Course` is the aggregate root. `Instructor` and `Provider` are referenced entities. `Location` is exposed read-only on `Course` — it is resolved from providers during indexing, keeping the derivation rule in exactly one place.

### Type safety

`declare(strict_types=1)` in every file. All public APIs carry parameter and return types; `mixed` is permitted only where a value genuinely arrives untyped from WordPress, and is narrowed immediately at that boundary. PHPStan runs at **level 9** across the whole plugin, not just the domain layer, and is part of the verification gate rather than an advisory tool.

This is the enforcement mechanism behind the brief's "implementation should prioritise strong typing and all public APIs must be typed" — a claim that is only credible if a tool checks it.

### Collections

`CourseCollection` and `StartDateCollection` are concrete `final` classes implementing `IteratorAggregate` and `Countable`, annotated `@implements IteratorAggregate<int, Course>` for PHPStan.

**No abstract `TypedCollection` base class.** The brief asks for composition over inheritance; a shared base here would save a few lines at the cost of exactly the inheritance coupling being assessed. The generic annotations are verified artifacts once PHPStan runs at level 9, satisfying "collections and generics should also be documented wherever necessary".

---

## 6. Index tables

### Why WordPress's native storage is insufficient

WordPress stores custom data in `postmeta` as key/value rows. Three failures:

1. **Serialised arrays.** ACF stores relationship fields as serialised PHP arrays, forcing `meta_value LIKE '%"12"%'` matching. No index applies; every row is scanned.
2. **Join multiplication.** Each additional meta-based search filter adds another self-join onto `postmeta`.
3. **Derived location is inexpressible.** Location lives on the provider, not the course. Filtering courses by location requires course → provider → location, a two-hop traversal `meta_query` cannot represent. A second query and a PHP-side intersection would be the only alternative.

Point 3 is decisive: the requirement genuinely cannot be met within WordPress's regular database structure, which is the condition the brief sets for adding tables.

### Schema

```
wp_course_index          -- one row per course
  course_id           BIGINT UNSIGNED  PRIMARY KEY
  price_minor         BIGINT
  earliest_start_ym   INT UNSIGNED
  search_text         MEDIUMTEXT       FULLTEXT KEY

wp_course_facet          -- many rows per course
  course_id           BIGINT UNSIGNED
  facet               VARCHAR(32)      -- 'provider' | 'location' | 'start' | 'category'
  value_id            BIGINT
  PRIMARY KEY (course_id, facet, value_id)
  KEY facet_lookup (facet, value_id, course_id)
```

`facet_lookup` is a covering index for the `EXISTS` subquery that each active search filter contributes.

Table names are resolved through `$wpdb->prefix` at runtime; `wp_` above is illustrative. A site using a custom prefix must still work.

**Known FULLTEXT limitations**, to be carried into the performance documentation rather than discovered late:

- InnoDB's default `innodb_ft_min_token_size` is 3, so two-letter terms such as "AI" or "IT" are not indexed and will not match. Relevant for course names.
- Default stopword lists silently drop common words.
- Boolean mode syntax characters (`+`, `-`, `*`, `"`) must be escaped or deliberately supported, or user input can produce parse errors.

If these prove limiting, the documented escalation is a trigram or external search index — which is exactly the "search optimisation" discussion §10 requires.

### Why one facet table rather than one table per dimension

Separate tables (`course_provider`, `course_location`, …) would be marginally better typed, but a third-party search filter would then require a schema migration to store anything. With a single facet table, a new filter writes rows under its own `facet` value — no `ALTER TABLE`, no migration, no change to existing code. Given extensibility is the graded axis, that outweighs the typing benefit.

### Query shape

```sql
SELECT i.course_id
FROM wp_course_index i
WHERE EXISTS (SELECT 1 FROM wp_course_facet f
              WHERE f.course_id = i.course_id
                AND f.facet = 'provider' AND f.value_id IN (12, 47))
  AND EXISTS (SELECT 1 FROM wp_course_facet f
              WHERE f.course_id = i.course_id
                AND f.facet = 'location' AND f.value_id IN (3))
  AND MATCH(i.search_text) AGAINST (:term IN BOOLEAN MODE)
ORDER BY i.earliest_start_ym
```

AND between groups, OR within a group — the brief's stated semantics, structurally guaranteed.

### The indexer

Rebuilds a course's rows on save. Triggers:

| Event | Action |
|---|---|
| `save_post_course` | Reindex that course |
| `save_post_provider` | **Reindex every course attached to that provider** |
| Category/location term changes | Reindex affected courses |
| `deleted_post` | Remove rows |

The provider trigger is the subtle one. When a provider's location changes, every attached course holds a stale location row despite never being edited — a silent wrong-results bug. This is a designated high-risk area for the test suite.

A WP-CLI command (`wp course-discovery reindex`) performs a full rebuild for recovery and for the README's development commands.

### Migrations

A versioned runner. Each schema change is a numbered migration; the current version is stored in the options table; pending migrations run on plugin activation. This satisfies "write necessary migrations" and makes deployment reproducible rather than dependent on manual steps.

---

## 7. Filter architecture

### The interface

```php
interface Filter {
    public function key(): FilterKey;
    public function label(): string;
    public function inputType(): FilterInputType;   // TEXT | CHECKBOX_GROUP | COMBOBOX_MULTI
    public function options(): FilterOptions;
    public function parse(array $params): ?FilterValues;
    public function constrain(FilterValues $values): Constraint;
}
```

Every implementation is `final` and implements this directly. **There is no `AbstractFilter`.** Shared behaviour (parsing repeated query parameters) lives in collaborators such as `MultiValueParser`, which filters hold rather than inherit.

`inputType()` lets one abstraction serve differing UI demands: locations and start dates return `COMBOBOX_MULTI` per the brief's "must be dropdown combox"; providers and categories return `CHECKBOX_GROUP`. Filters stay presentation-agnostic; the renderer switches on the enum.

### Filters do not generate SQL

`constrain()` returns a `Constraint` — a declarative description:

```php
FacetInConstraint('provider', [12, 47])
```

The arguments map directly onto the facet table of §6: a facet name and the value IDs to match. A separate `ConstraintCompiler` translates constraints into SQL. Three benefits: filters are unit-testable with no database; SQL generation lives in one auditable place; and raw SQL never originates inside a filter by default.

Constraint types: `FacetInConstraint` (the workhorse), `SearchTextConstraint` (full-text across name, short and long description), and `RawConstraint` as an explicit escape hatch. `RawConstraint` does allow SQL through — the value is that it is opt-in and greppable rather than the ambient default, so any use of it is visible in review. Third parties may also introduce their own constraint type and register a compiler for it.

### Registry

```php
$registry = new FilterRegistry();
$registry->register(new KeywordFilter());
$registry->register(new ProviderFilter($providers));
do_action('course_discovery/register_filters', $registry);
```

Adding a search filter means writing a class and calling `register()` from another plugin. No existing file is modified — the Open/Closed requirement satisfied structurally rather than by convention.

### Hook surface

The brief's list of extension points maps one-to-one:

| Brief's requirement | Hook |
|---|---|
| register additional filters | `course_discovery/register_filters` (action) |
| alter available filter options | `course_discovery/filter_options/{key}` |
| transform search criteria | `course_discovery/criteria` |
| modify filter queries | `course_discovery/constraints` |
| customise result ordering | `course_discovery/order` |

### Pipeline

```
URL params
  → SearchCriteria::fromQueryParams()     each filter parses its own params
  → [criteria hook]
  → Filter::constrain() per active filter
  → [constraints hook]   [order hook]
  → ConstraintCompiler → one indexed SQL query
  → SearchResult (courses + total + pagination)
```

### The five filters

`KeywordFilter`, `ProviderFilter`, `LocationFilter`, `StartDateFilter`, `CategoryFilter`.

Free-text search is modelled as a filter like any other, keeping the pipeline uniform and making `register_filters` usable for text-style filters. The trade-off: `options()` returns empty for it. Documented rather than special-cased.

### Proving extensibility rather than asserting it

The brief's central requirement is that new filters can be added without modifying existing code. A claim like that in a README is unverifiable; a working example is not.

Ship a second, separate plugin — `course-discovery-example-extension` — living in its own directory and containing roughly one class. It registers an **instructor filter** purely through `course_discovery/register_filters`, adds its own facet rows through the indexer hook, and appears in the UI automatically.

Instructor is the natural choice: it is an existing post type and a required data point, but deliberately *not* in the brief's list of required filters. So it is genuinely additional functionality, added with zero changes to the main plugin.

The value is that a reviewer can delete that directory, watch the filter vanish, restore it, and watch it return. That demonstrates the Open/Closed claim in a way no amount of prose can. Cost is roughly an hour.

---

## 8. Request flow and frontend

A `[course_discovery]` shortcode placed on a page — theme-agnostic, no template overrides required.

The form is real HTML: `<form method="get">` with `<fieldset>`/`<legend>` grouping each search filter, checkboxes for providers and categories, comboboxes for locations and start dates. **It submits and works with JavaScript disabled**, which satisfies the keyboard requirement by default rather than reconstructing it.

JavaScript upgrades the experience: intercept submit, fetch, swap the results region, `pushState` to keep the URL authoritative. The server renders results markup for both full page loads and fetch responses — one template, no duplicated rendering.

Comboboxes implement the ARIA combobox pattern by hand (arrow keys, Enter, Escape, type-ahead) with no external library. An `aria-live` region announces the result count on update.

---

## 9. Testing strategy

| Layer | Scope | Requires |
|---|---|---|
| Unit | Domain objects, filters, criteria serialisation | Nothing — no WordPress, no database |
| Integration | Indexer correctness, repository SQL | MySQL |
| Feature | URL → rendered results | WordPress |
| E2E | Keyboard-only navigation, no-JS baseline, combobox behaviour | Playwright |

The unit layer running without a database is the direct payoff from separating constraints from SQL.

**Filter contract test.** The brief asks how new filters can be tested consistently. A reusable base test case verifies any filter parses parameters, handles multi-select, and produces the expected constraint. Opting a new filter in takes a few lines.

**Designated high-risk areas:** indexer staleness (the provider-location case), AND/OR composition correctness, chronological date ordering.

**Regression prevention strategy** — named explicitly because the brief asks for it by name:

- The brief's own worked example (`provider IN (uosd, dmu) AND location IN (india, china) AND category = graphic design`) becomes a permanent test case, so the specified semantics cannot silently drift.
- Every bug found during development gets a failing test written before the fix, so it cannot recur unnoticed.
- The filter contract test runs against every registered filter, including ones added later, so a new filter cannot skip the baseline guarantees.
- PHPStan level 9 and the test suite run together as a single verification gate.

---

## 10. Performance documentation

Implementation of large-scale optimisation is explicitly not required; documentation is. The brief's bullets become the section headings: expected bottlenecks, limitations of WordPress meta queries, indexing considerations, query performance, caching opportunities, pagination strategy, search optimisation, and evolution to hundreds of thousands or millions of courses — including when to introduce dedicated lookup tables, denormalised data, or external search.

Because the index exists, this section can cite evidence: `EXPLAIN` output for the naive `postmeta` query against the indexed one, measured on a seeded dataset of ~100k courses.

---

## 11. Deployment

A ~€4/month VPS running the project's `docker-compose.yml` behind Caddy for automatic HTTPS.

Free container tiers were rejected: Render's free services spin down and it offers no free MySQL, Railway's credits expire, Fly machines auto-stop by default. Each risks the reviewer opening a cold, empty or dead site.

The compose file therefore serves as the actual deployment artifact, making the README's setup instructions describe something true.

**Deploy on day 1–2 against an empty site**, not at the end. The common failure is exhausting the schedule and submitting without the live URL.

---

## 12. Assumptions

- **A1 — Provider location storage.** The brief states location is derived from provider but not how a provider stores it. Assumed: a `location` taxonomy on the provider post type. Rationale: stable term IDs (which the facet table stores), a managed vocabulary, free admin UI, and no duplicate-spelling drift.
- **A2 — Start date granularity.** Month and year only, per the stated `{month}-{year}` input format. No day component.
- **A3 — Single currency.** `Currency` is modelled but only one is configured. Multi-currency is out of scope.
- **A4 — Course-provider cardinality.** A course may have several providers, so it may resolve to several locations. A course matches a location filter if *any* of its providers is in that location.
- **A5 — Category hierarchy matching.** Categories are hierarchical, but the brief never says whether selecting a parent matches courses filed only under a child. Assumed: **yes, ancestors match descendants** — selecting "Design" returns courses in "Design > Graphic Design". This matches standard WordPress behaviour and user expectation. Implemented by indexing each course against its category *and all ancestors*, so the query stays a flat `IN` lookup with no recursion at read time.
- **A6 — Past start dates.** The brief requires the start date dropdown be chronological but is silent on past dates. Assumed: **dates earlier than the current month are excluded from the dropdown**, since a course that has already started is not discoverable in any useful sense. Historical rows remain in the index, so the rule is a presentation concern and reversible via the `filter_options` hook.

---

## 13. Out of scope

Deliberately excluded, with reasons:

- `PriceRange` / multi-price implementation — the extension seam exists; no requirement filters on price.
- Enrolment, payment, user accounts — the brief covers discovery only.
- Multilingual support.
- Large-scale optimisation work — explicitly not required; documented instead.
- Bedrock restructure — see §3.

---

## 14. Open items

1. **Deployment host** — decided in principle (small VPS plus Caddy) but not yet provisioned. Needs a domain or a DuckDNS subdomain before certificates can be issued. Scheduled for day 1–2.
2. **Repository identity** — the submission requires a *public* repository. The current repo is private and carries two early commits whose messages describe work that did not happen ("install Akismet plugin files" on a commit that removes them). Options: force-push a cleaned history, or start the submission in a fresh public repo. Decide before the first substantial commit, since the cost only rises.
3. **Reference layout** — the brief links to "Oxford International course finder — New" as an example layout. Worth reviewing before building the frontend, to align terminology and result-card content with what the assessors expect to see. It is an example, not a specification; the brief explicitly de-prioritises UI fidelity.

---

## 15. Submission checklist

The brief enumerates its deliverables. Restated here so none is discovered missing on the final day.

| Deliverable | Status |
|---|---|
| Publicly accessible deployment | §11 — provision day 1–2 |
| Public Git repository | Open item 2 |
| README: setup instructions | Derived from the compose file |
| README: environment requirements | PHP, MariaDB, Composer, Node versions |
| README: database setup | Migration runner, seeding, reindex command |
| README: development commands | Compose, Composer scripts, WP-CLI, test runners |
| README: testing instructions | §9 — how to run each layer |
| README: architectural decisions | §3 and §6 are the source material |
| README: assumptions | §12, all six |
| Testing documentation: what to test, high-risk areas, regression prevention, how new filters are tested | §9 |
| Performance documentation: eight enumerated topics | §10 |

The README is a graded artifact carrying seven required subsections, not an afterthought. Sections §3, §6, §9, §10 and §12 of this document are drafted with the intent of being adapted into it directly.
