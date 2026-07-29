# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

This file covers only what is specific to working here and easy to get wrong.
**[README.md](README.md) is the source of truth** for setup (§2), environment
versions (§3), database setup (§4), commands (§5), testing (§6), architectural
decisions (§7) and assumptions (§8). Deliberately not duplicated here —
duplicated facts drift and end up contradicting each other.

## What this is

A WordPress site (`edtech`) running in DDEV, hosting a custom course-discovery
plugin built to prioritise architecture, extensibility and type safety over UI
polish. Site: `https://edtech.ddev.site`.

## Repo layout — read before editing anything

Project code lives in **`plugins/`** at the repo root:

```
plugins/course-discovery/                    ← the plugin; this is the project
plugins/course-discovery-example-extension/  ← second plugin proving the hook API
wp/                                          ← WordPress core (DDEV docroot)
```

`wp/` is the docroot and is **gitignored wholesale** (`.gitignore` has `/wp/`),
along with `/vendor/`, `/node_modules/` and `/.env`.

`.ddev/docker-compose.plugins.yaml` **bind-mounts** the two `plugins/`
directories into `wp/wp-content/plugins/`. On the host those mount points are
**empty directories** — editing there silently does nothing. Always edit under
`plugins/`.

## Hard rules

- **Never run `php`, `composer`, `wp`, or `mysql` on the host.** A host `php`
  binary exists, which makes this an easy mistake — it is the wrong PHP and has
  no database access. Always go through DDEV: `ddev composer …`, `ddev wp …`,
  `ddev mysql`, `ddev exec …`.
- **Never edit WordPress core** under `wp/` — gitignored, and destroyed by every
  core update.
- **`wp-config.php` and `wp-config-ddev.php` carry a `#ddev-generated` header**
  and are rewritten on `ddev start`. DB credentials, `WP_HOME`, `WP_SITEURL`,
  `$table_prefix` and `WP_DEBUG` all come from container env vars — keep it that
  way so the project stays portable. Environment changes go in
  `.ddev/config.yaml`, then `ddev restart`.
- **Take a snapshot before destructive database work**: `ddev snapshot`,
  restore with `ddev snapshot restore --latest`.

## Verification gate

Run before claiming any code change is done, and quote real output:

```bash
ddev composer test    # unit -> integration -> architecture
ddev composer stan    # PHPStan level 9, must report "No errors"
```

The integration and architecture suites end with `OK, but there were issues!` —
that is PHPUnit deprecation noise from Yoast Polyfills' doc-comment annotations,
not a failure. Judge by the test/assertion counts and the absence of `FAILURES!`
or `ERRORS!`.

After schema or index changes also rebuild and smoke-test the real site:

```bash
ddev wp course-discovery reindex
```

E2E (Playwright, needs a running seeded site) is separate — see README §6.

## Conventions

- **Plugin PHP is 4-space indented, PSR-12-ish**, with PSR-4 `PascalCase` classes
  under `plugins/course-discovery/src/`. It is *not* WordPress-core style — no
  tabs, no Yoda conditions. Match the surrounding file.
- **Prefix everything public** to avoid collisions: post types and taxonomies
  (`cd_course`, `cd_location`), DB tables (`cd_course_meta_lookup`), options
  (`cd_schema_version`) and hooks (`course_discovery/…`).
- **Escape on output** (`esc_html`, `esc_attr`, `esc_url`), **sanitize on input**,
  use `$wpdb->prepare()` for all SQL, and verify **nonces + capabilities** in
  every form and REST/AJAX handler.
- **`src/Domain/` must contain no WordPress** — no `$wpdb`, hooks, or `WP_*`
  classes. The architecture test suite enforces this; keep WordPress in the
  adapter layers (`ContentModel/`, `Index/`, `Query/`, `Frontend/`).
- The lookup tables (`cd_course_meta_lookup`, `cd_course_attribute_lookup`) are a
  **derived projection**, never a source of truth — safe to drop and rebuild with
  `reindex`. Canonical data is always `wp_posts` / `wp_postmeta` / the term tables.
