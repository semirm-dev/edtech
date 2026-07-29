# Course Discovery

## 1. What & why

A searchable course-finder built as a WordPress plugin
(`plugins/course-discovery/`) on WordPress + the free edition of Advanced
Custom Fields, plus a second, independent plugin
(`plugins/course-discovery-example-extension/`) that adds a filter through the
first's public hooks alone. Courses are filterable by provider, location (derived
from provider), category, start date and free text, composed as
AND-between-groups / OR-within-a-group over a small denormalised index rather
than `WP_Query` meta queries.

It is built for **architecture, extensibility and type safety** ahead of UI
polish. Design rationale: [`docs/architecture.md`](docs/architecture.md).

### Extensibility

**A new search filter can be added with zero changes to the core plugin.**
The example extension adds an **Instructor** filter using only
`course-discovery`'s public hooks (`course_discovery/register_filters`,
`course_discovery/indexed_course`) and public API
(`CourseIndexer::addAttributeValues()`). It never `require`s anything under
`plugins/course-discovery/src/`.

```bash
ddev wp plugin activate course-discovery-example-extension && ddev wp course-discovery reindex
# the Instructor filter now appears at /find-courses/; deactivating removes it
```

See [`docs/architecture.md` §5](docs/architecture.md) for all seven public
hooks, with signatures and security contracts.

## 2. Setup

Everything runs through [DDEV](https://ddev.com) — WP-CLI, Xdebug and a
MariaDB shell come with it.

```bash
ddev start
ddev composer install
./bin/seed.sh
ddev wp post create --post_type=page --post_title="Find Courses" \
    --post_name=find-courses --post_content='[course_discovery]' --post_status=publish
ddev launch /find-courses/
```

A fresh DDEV database has no demo content and no `/find-courses/` page, so
both the seed and the `wp post create` line are required. `ddev composer
install` is only needed once, or after a `composer.lock` change.

## 3. Environment requirements

| Requirement | Notes |
|---|---|
| PHP 8.4 | What DDEV runs (`.ddev/config.yaml`); the plugin itself requires ≥ 8.3. |
| MariaDB 10.11 | Managed by DDEV. The lookup tables' `FULLTEXT` index relies on it. |
| Composer 2 | Always `ddev composer …` — a host `php` binary exists but is the wrong version and has no database access. |
| Node.js 18+ | Playwright E2E only (`e2e/`, a standalone Node project). |
| Docker | Required by DDEV. |
| ACF free edition | Pinned at 6.8.6: `ddev wp plugin install advanced-custom-fields --activate`. PRO is licence-gated and can't be redistributed with this repository, so repeating start dates use a hand-built meta box instead of PRO's Repeater field. |

## 4. Database setup

No manual schema step — `Index\MigrationRunner` applies a version-ordered
list of `Migration` objects (`src/Index/Migrations/`) and records the applied
version in `cd_schema_version`. It runs on `register_activation_hook` **and**
on every `admin_init` via `runIfPending()`, so a site activated before a
later migration shipped still catches up instead of silently drifting.

Two tables, both resolved through `$wpdb->prefix`:

- **`{prefix}cd_course_meta_lookup`** — one row per course: `course_id`,
  `price_minor`, `earliest_start_ym`, `title`, and a `FULLTEXT` `search_text`.
- **`{prefix}cd_course_attribute_lookup`** — many rows per course:
  `(course_id, attribute, value_id)`, plus covering index
  `attribute_lookup (attribute, value_id, course_id)`. `attribute` is a plain
  string column, not a fixed enum — which is exactly what lets the example
  extension add a whole new filterable dimension with **no migration**.

Both tables are a derived projection, never a source of truth: rebuild any
time with `ddev wp course-discovery reindex`.

`./bin/seed.sh` re-runs safely, but **deletes every existing
`cd_course`/`cd_instructor`/`cd_provider` post first**, including
hand-authored ones, before recreating fixtures.

## 5. Development commands

| Purpose | Command |
|---|---|
| Start / stop / restart | `ddev start` / `ddev stop` / `ddev restart` |
| Status, URLs, ports | `ddev describe` |
| Tail PHP/nginx errors | `ddev logs -s web -f` |
| Install PHP dependencies | `ddev composer install` |
| Unit / integration / architecture suites | `ddev composer test:unit` / `test:integration` / `test:arch` |
| Everything (unit → integration → arch) | `ddev composer test` |
| Static analysis (PHPStan level 9) | `ddev composer stan` |
| A single test class | `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ReindexCommandTest` (use `-c phpunit.xml.dist` for a unit-suite class) |
| Rebuild the search index | `ddev wp course-discovery reindex` |
| Seed demo content | `./bin/seed.sh` |
| Regenerate translation templates | `ddev wp i18n make-pot plugins/course-discovery plugins/course-discovery/languages/course-discovery.pot --domain=course-discovery` (and the equivalent for the extension) |
| Playwright E2E | `cd e2e && npm install && npx playwright install --with-deps chromium && CD_E2E_URL=https://edtech.ddev.site npx playwright test` |

## 6. Testing

Full write-up — high-risk areas, regression strategy, the shared filter
contract test case — in [`docs/testing-strategy.md`](docs/testing-strategy.md).

| Layer | Command | Requires | Result (2026-07-29) |
|---|---|---|---|
| Unit | `ddev composer test:unit` | Nothing | **136 tests, 260 assertions** |
| Integration | `ddev composer test:integration` | WordPress + MariaDB (wp-phpunit) | **268 tests, 527 assertions** |
| Architecture | `ddev composer test:arch` | Nothing | **59 tests, 59 assertions** |
| Static analysis | `ddev composer stan` | Nothing | **No errors**, PHPStan level 9 |
| E2E (Playwright) | `cd e2e && CD_E2E_URL=https://edtech.ddev.site npx playwright test` | Node 18+, a running seeded site | **2 specs** |

Total across the PHPUnit layers: **463 tests, 846 assertions**, all green.
The integration and architecture suites end with `OK, but there were issues!`
— PHPUnit deprecation noise from Yoast Polyfills' doc-comment annotations,
not a failure.

The two E2E specs cover what PHPUnit structurally cannot: `no-js.spec.ts`
submits the plain `<form method="get">` with JavaScript disabled entirely and
asserts a real page load narrows results; `keyboard.spec.ts` drives the
location combobox with the keyboard only, asserting `aria-selected`, the
hidden native `<select>` and focus return all stay in sync. See
[`e2e/README.md`](e2e/README.md).

## 7. Architectural decisions

Each folder under `plugins/course-discovery/src/`:

| Folder | Represents |
|---|---|
| `Domain/` | The problem as plain PHP — `Course`, `Money`, `StartDate`, `SearchCriteria`. **No WordPress at all**; the architecture suite fails the build if any creeps in. |
| `Domain/Constraint/` | What to restrict, declaratively. Nothing here knows SQL exists. |
| `Domain/Filter/` | What a filter *is*: the `Filter` interface third parties implement, plus its key, options, values and input type. |
| `ContentModel/` | The WordPress data model — post types, taxonomies, ACF fields, admin screens. |
| `Index/` | **The write side.** Schema, versioned migrations, the indexer, the hooks deciding when to re-run it, the `reindex` CLI command. |
| `Query/` | **The only place SQL is generated.** Compiles a `ConstraintSet` into a prepared `WHERE`, hydrates rows into `Course` objects. |
| `Filter/` | The five shipped filters and the registry that holds them — the seam third-party plugins hook. |
| `Search/` | `SearchService`: `SearchCriteria` in, `SearchResult` out. Delivery-agnostic. |
| `Frontend/` | One delivery mechanism — the `[course_discovery]` shortcode, renderers, assets. |
| `Plugin.php` / `Container.php` | Composition root, and a deliberately tiny service locator. |

Dependency direction is inward: `Frontend/` and `Search/` depend on
`Domain/`; `Domain/` depends on nothing. Swapping the front door (a block, a
REST route) touches no other layer.

The decisions most worth knowing before reading code:

- **Lookup tables over `meta_query`.** ACF relationship fields serialise as
  PHP arrays (forcing an unindexed `LIKE` scan), each extra filter is another
  `postmeta` self-join, and — decisively — a course's location isn't stored
  on the course at all, it's derived from its provider, a two-hop lookup
  `meta_query` cannot express in one query. See
  [`docs/performance.md`](docs/performance.md).
- **ACF free plus a custom meta box, not ACF PRO.** PRO can't be
  redistributed, so start dates are a hand-built meta box — which also gives
  full control over the storage format chronological sorting depends on.
- **The filter registry.** `FilterRegistry::boot()` registers the core
  filters then fires `course_discovery/register_filters`. A duplicate key
  doesn't throw (a public hook callback throwing would fatal every visitor's
  page); the first registration wins.
- **No `AbstractFilter`.** The five core filters implement `Filter` directly;
  shared behaviour is held as a collaborator (`TermOptions`,
  `PostTypeOptions`), not inherited — composition applied concretely rather
  than stated as a principle.
- **Bedrock — considered, rejected.** Better reproducibility, but its
  non-standard layout complicates deployment and spends effort on
  infrastructure that isn't the point here. Plain WordPress, with DDEV on top.

**Optional: a JSON endpoint.** Search is served entirely by a plain
`<form method="get">`, which meets the requirements, so no REST route ships. The
seam is already in place if in-place filtering is wanted later:
`SearchService` returns a `SearchResult` from query parameters and
`ResultsRenderer` turns one into HTML, so an endpoint is a thin wrapper over
both, not a second implementation. `ResultsRenderer::render()` takes an
explicit `$baseUrl` precisely so pagination links don't resolve against the
JSON route's own `REQUEST_URI`.

## 8. Assumptions

- **A1 — Provider location storage.** Location derives from provider, but the
  storage isn't specified; assumed a `cd_location` taxonomy on the provider
  post type (stable term ids, managed vocabulary, free admin UI).
- **A2 — Start date granularity.** Month and year only, per the specified
  `{month}-{year}` format.
- **A3 — Single currency.** `Currency` is modelled as a type but only one is
  ever configured.
- **A4 — Course-provider cardinality.** A course may have several providers
  and so several locations; it matches a location filter if *any* provider is
  in that location.
- **A5 — Category hierarchy matching.** Selecting a parent matches courses
  filed only under a child. Implemented by indexing each course against its
  full ancestor chain, so reads stay a flat `IN` lookup.
- **A6 — Past start dates.** Excluded from the dropdown — a presentation
  rule only; historical rows stay indexed, reversible via
  `course_discovery/filter_options/{key}`.
- **A7 — Prefixed keys, clean URLs.** Internal keys are prefixed
  (`cd_course`, `cd_provider`, …) while public URLs stay clean
  (`/courses/`, `/providers/`). `course` is the exact slug Sensei LMS and
  LifterLMS use, and `register_post_type()` silently no-ops on a taken slug —
  an unprefixed registration would break silently on those sites.

**Known limitations:**

- **Two text domains, deliberately** — `course-discovery` and
  `course-discovery-example`. Sharing one would blur the boundary the
  extension exists to prove.
- **`cd_location` archive slug.** Registered with `slug => 'location'`, but
  WordPress falls back to the internal name in some generated contexts.
  Cosmetic — filtering and indexing key on term ids, never URLs.
- **Extension folder name vs. text domain.** Directory is
  `course-discovery-example-extension/`, text domain is
  `course-discovery-example`. Harmless here (it ships inside this repo, not
  via WordPress.org), noted rather than hidden.
