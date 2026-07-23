# Course Discovery — Plan 1: Foundation and Content Model

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A reproducible Docker environment with a verified test/static-analysis gate and the complete content model, so courses, instructors and providers can be authored in wp-admin.

**Architecture:** The repository becomes a *plugin project* rather than a WordPress install. The official `wordpress` Docker image supplies core; our two plugins are bind-mounted into it. Composer manages autoloading and dev tooling only. All content registration is code, never database state — so a fresh clone reproduces the model exactly.

**Tech Stack:** PHP 8.4, WordPress (official image), MariaDB 11, Composer, PHPUnit 11, PHPStan level 9, ACF (free).

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- PHPStan **level 9**, zero errors, across both plugins. Part of the gate, not advisory.
- All public APIs carry parameter and return types. `mixed` only at WordPress boundaries, narrowed immediately.
- No external WordPress plugins except **ACF free**.
- No WordPress functions inside `src/Domain/` (enforced from Plan 2 onward; nothing in this plan puts code there).
- Namespace root: `CourseDiscovery\` → `plugins/course-discovery/src/`.
- Table names always via `$wpdb->prefix`, never hardcoded `wp_`.
- Hook names are slash-namespaced: `course_discovery/...`.
- Commit after every task. Conventional Commits, description under 50 chars.

## Plan Series

This is plan 1 of 4. Each produces working, testable software.

| Plan | Delivers |
|---|---|
| **1 — Foundation and content model** (this) | Environment, tooling gate, post types, taxonomies, fields, admin |
| 2 — Index and query engine | Migrations, index tables, indexer, repository, constraint compiler |
| 3 — Filters and frontend | Filter interface, registry, hooks, 5 filters, shortcode, accessible UI |
| 4 — Extension, docs, deploy | Example extension plugin, README, performance essay, VPS deployment |

## File Structure

```
docker-compose.yml                    WordPress + MariaDB services
.env.example                          documented environment variables
composer.json                         autoloading + dev tooling + scripts
phpunit.xml.dist                      two suites: unit (no WP), integration (WP)
phpstan.neon.dist                     level 9 config
plugins/course-discovery/
  course-discovery.php                plugin header + bootstrap
  src/
    Plugin.php                        wires subsystems to WordPress hooks
    ContentModel/PostTypes.php        course, instructor, provider
    ContentModel/Taxonomies.php       course_category, location
    ContentModel/AcfFields.php        price + relationships, registered in PHP
    ContentModel/StartDates.php       storage + normalisation (no WP UI)
    ContentModel/StartDatesMetaBox.php  admin UI for start dates
    ContentModel/AdminColumns.php     course list columns
  tests/
    bootstrap-unit.php
    bootstrap-integration.php
    Unit/ContentModel/StartDatesTest.php
    Integration/ContentModel/PostTypesTest.php
    Integration/ContentModel/TaxonomiesTest.php
    Integration/ContentModel/StartDatesMetaBoxTest.php
    Integration/ContentModel/AdminColumnsTest.php
```

`StartDates` is split from `StartDatesMetaBox` deliberately: parsing and normalising `{month}-{year}` is pure logic testable without WordPress, while the meta box is admin UI. Splitting them is what lets the tricky part be unit-tested.

---

### Task 1: Docker environment

**Files:**
- Create: `docker-compose.yml`
- Create: `.env.example`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: nothing
- Produces: a running WordPress at `http://localhost:8080`, database service named `db`, plugins directory bind-mounted from `./plugins`

- [ ] **Step 1: Move existing WordPress core out of the way**

The repo currently holds a WordPress install at root. Core files are gitignored, so this is invisible to git. Preserve rather than delete:

```bash
mkdir -p .archive
mv wp-admin wp-includes wp-content wp-*.php index.php license.txt readme.html xmlrpc.php .archive/ 2>/dev/null
ls
```

Expected: only `.archive`, `.claude`, `.ddev`, `.git`, `.gitignore`, `CLAUDE.md`, `docs`.

- [ ] **Step 2: Create `docker-compose.yml`**

```yaml
services:
  db:
    image: mariadb:11.4
    environment:
      MARIADB_DATABASE: ${DB_NAME:-wordpress}
      MARIADB_USER: ${DB_USER:-wordpress}
      MARIADB_PASSWORD: ${DB_PASSWORD:-wordpress}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-root}
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      retries: 20

  wordpress:
    image: wordpress:php8.4-apache
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "${HTTP_PORT:-8080}:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_NAME: ${DB_NAME:-wordpress}
      WORDPRESS_DB_USER: ${DB_USER:-wordpress}
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD:-wordpress}
      WORDPRESS_DEBUG: 1
    volumes:
      - wp_core:/var/www/html
      - ./plugins/course-discovery:/var/www/html/wp-content/plugins/course-discovery
      - ./plugins/course-discovery-example-extension:/var/www/html/wp-content/plugins/course-discovery-example-extension

volumes:
  db_data:
  wp_core:
```

- [ ] **Step 3: Create `.env.example`**

```bash
# Copy to .env and adjust. Compose reads .env automatically.
HTTP_PORT=8080
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=wordpress
DB_ROOT_PASSWORD=root
```

- [ ] **Step 4: Create the mounted directories so the bind mounts resolve**

```bash
mkdir -p plugins/course-discovery plugins/course-discovery-example-extension
touch plugins/course-discovery/.gitkeep plugins/course-discovery-example-extension/.gitkeep
```

- [ ] **Step 5: Replace `.gitignore`**

The current file ignores a WordPress install we no longer have at root.

```gitignore
/.archive/
/.env
/vendor/
/node_modules/
.phpunit.result.cache
*.log
```

- [ ] **Step 6: Boot and verify**

```bash
cp .env.example .env && docker compose up -d && sleep 20 && curl -sI http://localhost:8080/ | head -1
```

Expected: `HTTP/1.1 302 Found` (redirect to the installer) or `HTTP/1.1 200 OK`. If the image tag `wordpress:php8.4-apache` is unavailable, run `docker run --rm wordpress:php8.4-apache php -v` to confirm; fall back to `wordpress:php8.3-apache` and record the change here.

- [ ] **Step 7: Complete the WordPress install**

Visit `http://localhost:8080/` and complete the five-field installer. Site title `Course Discovery`, note the admin credentials — they go in the README later.

- [ ] **Step 8: Commit**

```bash
git add docker-compose.yml .env.example .gitignore plugins/
git commit -m "chore: add docker compose environment"
```

---

### Task 2: Composer, plugin skeleton, and the tooling gate

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`
- Create: `plugins/course-discovery/course-discovery.php`
- Create: `plugins/course-discovery/src/Plugin.php`
- Create: `plugins/course-discovery/tests/bootstrap-unit.php`
- Test: `plugins/course-discovery/tests/Unit/PluginTest.php`

**Interfaces:**
- Consumes: Task 1's directory layout
- Produces: `CourseDiscovery\Plugin::VERSION` (string), `CourseDiscovery\Plugin::boot(): void`; commands `composer test:unit` and `composer stan`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "semirm-dev/course-discovery",
    "description": "Course Discovery system for WordPress",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5",
        "yoast/phpunit-polyfills": "^4.0",
        "wp-phpunit/wp-phpunit": "*",
        "phpstan/phpstan": "^2.1",
        "szepeviktor/phpstan-wordpress": "^2.0",
        "php-stubs/acf-stubs": "^6.0"
    },
    "autoload": {
        "psr-4": {
            "CourseDiscovery\\": "plugins/course-discovery/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CourseDiscovery\\Tests\\": "plugins/course-discovery/tests/"
        }
    },
    "scripts": {
        "test:unit": "phpunit --testsuite unit",
        "stan": "phpstan analyse --memory-limit=512M"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": false
        },
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Install dependencies**

```bash
composer install
```

Expected: `vendor/` created, no errors. If `wp-phpunit/wp-phpunit` fails to resolve, pin it to `^6.8` and note it.

- [ ] **Step 3: Create `phpunit.xml.dist`**

The unit suite only. Integration gets its own config file in Task 3, because a single `bootstrap` attribute cannot serve both a WordPress-free and a WordPress-loaded suite.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="plugins/course-discovery/tests/bootstrap-unit.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>plugins/course-discovery/tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>plugins/course-discovery/src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Create `plugins/course-discovery/tests/bootstrap-unit.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
```

- [ ] **Step 5: Write the failing test**

Create `plugins/course-discovery/tests/Unit/PluginTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit;

use CourseDiscovery\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function test_it_exposes_a_semver_version(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Plugin::VERSION);
    }
}
```

- [ ] **Step 6: Run it and watch it fail**

```bash
composer test:unit
```

Expected: FAIL — `Class "CourseDiscovery\Plugin" not found`.

- [ ] **Step 7: Create `plugins/course-discovery/src/Plugin.php`**

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery;

/**
 * Composition root. Wires subsystems to WordPress hooks.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    public static function boot(): void
    {
        // Subsystems are registered here as later tasks add them.
    }
}
```

- [ ] **Step 8: Run it and watch it pass**

```bash
composer test:unit
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 9: Create the plugin entry file `plugins/course-discovery/course-discovery.php`**

```php
<?php

/**
 * Plugin Name: Course Discovery
 * Description: Extensible course discovery system.
 * Version: 0.1.0
 * Requires PHP: 8.3
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$courseDiscoveryAutoload = __DIR__ . '/../../vendor/autoload.php';

if (! is_readable($courseDiscoveryAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Course Discovery: run <code>composer install</code>.</p></div>';
    });

    return;
}

require_once $courseDiscoveryAutoload;

CourseDiscovery\Plugin::boot();
```

The autoloader path resolves out of the plugins directory to the project root, which the compose bind mount makes available. Verify in Step 11.

- [ ] **Step 10: Create `phpstan.neon.dist`**

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 9
    paths:
        - plugins/course-discovery/src
    bootstrapFiles:
        - vendor/php-stubs/acf-stubs/acf-stubs.php
```

- [ ] **Step 11: Verify the whole gate, including in-container activation**

```bash
composer stan && composer test:unit
```

Expected: `[OK] No errors` then `OK (1 test, 1 assertion)`.

The autoloader is outside the bind mount, so mount the project root too. Add to the `wordpress` service volumes in `docker-compose.yml`:

```yaml
      - ./vendor:/var/www/html/vendor
```

Then restart and activate:

```bash
docker compose up -d --force-recreate wordpress
docker compose exec wordpress bash -c "cd /var/www/html && php -r 'echo file_exists(\"vendor/autoload.php\") ? \"autoload OK\" : \"MISSING\";'"
```

Expected: `autoload OK`. Then activate "Course Discovery" in wp-admin → Plugins and confirm no error notice appears.

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist phpstan.neon.dist plugins/ docker-compose.yml
git commit -m "chore: add plugin skeleton and tooling gate"
```

---

### Task 3: Integration test harness

**Files:**
- Create: `plugins/course-discovery/tests/bootstrap-integration.php`
- Create: `plugins/course-discovery/tests/Integration/HarnessTest.php`
- Modify: `phpunit.xml.dist`, `composer.json`

**Interfaces:**
- Consumes: Task 2's autoloader and plugin entry file
- Produces: `composer test:integration`, running with WordPress fully loaded and the plugin active

- [ ] **Step 1: Add the integration bootstrap**

Create `plugins/course-discovery/tests/bootstrap-integration.php`:

```php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

require_once $projectRoot . '/vendor/autoload.php';

$wpTests = getenv('WP_TESTS_DIR') ?: $projectRoot . '/vendor/wp-phpunit/wp-phpunit';

require_once $wpTests . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/course-discovery.php';
});

require $wpTests . '/includes/bootstrap.php';
```

- [ ] **Step 2: Point the integration suite at it**

`phpunit.xml.dist` already bootstraps the unit suite. Add a second config, `phpunit-integration.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="plugins/course-discovery/tests/bootstrap-integration.php"
         colors="true">
    <testsuites>
        <testsuite name="integration">
            <directory>plugins/course-discovery/tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Add the composer script**

In `composer.json` `scripts`, add:

```json
        "test:integration": "phpunit -c phpunit-integration.xml.dist",
        "test": ["@test:unit", "@test:integration"]
```

- [ ] **Step 4: Write the failing harness test**

Create `plugins/course-discovery/tests/Integration/HarnessTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

use WP_UnitTestCase;

final class HarnessTest extends WP_UnitTestCase
{
    public function test_wordpress_is_loaded(): void
    {
        self::assertTrue(function_exists('wp_insert_post'));
    }

    public function test_the_plugin_is_active(): void
    {
        self::assertTrue(class_exists(\CourseDiscovery\Plugin::class));
    }
}
```

- [ ] **Step 5: Run it inside the container and watch it fail**

The test suite needs a database. Run it in the WordPress container against a scratch database:

```bash
docker compose exec db mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wordpress_test;"
docker compose exec -e WP_TESTS_DB_NAME=wordpress_test -e WP_TESTS_DB_USER=root -e WP_TESTS_DB_PASSWORD=root -e WP_TESTS_DB_HOST=db wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Expected: FAIL — the test config file is not visible in the container yet.

- [ ] **Step 6: Mount the project root and add the test config**

`wp-phpunit` reads database settings from a `wp-tests-config.php`. Create it at project root:

```php
<?php

declare(strict_types=1);

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'root');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'localhost');
define('WP_TESTS_EMAIL', 'admin@example.com');
define('WP_TESTS_TITLE', 'Course Discovery Tests');
define('WP_PHP_BINARY', 'php');
define('ABSPATH', '/var/www/html/');
```

Add to the `wordpress` service volumes so the whole project is reachable in-container:

```yaml
      - ./phpunit-integration.xml.dist:/var/www/html/phpunit-integration.xml.dist
      - ./wp-tests-config.php:/var/www/html/wp-tests-config.php
      - ./plugins/course-discovery/tests:/var/www/html/tests
```

Then `docker compose up -d --force-recreate wordpress`.

- [ ] **Step 7: Run it again and watch it pass**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && WP_TESTS_CONFIG_FILE_PATH=/var/www/html/wp-tests-config.php vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Expected: `OK (2 tests, 2 assertions)`.

If bootstrap fails to locate WordPress, set `WP_TESTS_DIR` explicitly and re-run. Record the working command — it becomes the README's testing instruction.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml.dist phpunit-integration.xml.dist wp-tests-config.php composer.json plugins/
git commit -m "test: add wordpress integration harness"
```

---

### Task 4: Post types

**Files:**
- Create: `plugins/course-discovery/src/ContentModel/PostTypes.php`
- Modify: `plugins/course-discovery/src/Plugin.php`
- Test: `plugins/course-discovery/tests/Integration/ContentModel/PostTypesTest.php`

**Interfaces:**
- Consumes: `Plugin::boot()`
- Produces: `PostTypes::COURSE = 'course'`, `PostTypes::INSTRUCTOR = 'instructor'`, `PostTypes::PROVIDER = 'provider'`, and `PostTypes::register(): void`

- [ ] **Step 1: Write the failing test**

Create `plugins/course-discovery/tests/Integration/ContentModel/PostTypesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use WP_UnitTestCase;

final class PostTypesTest extends WP_UnitTestCase
{
    public function test_all_three_post_types_are_registered(): void
    {
        self::assertTrue(post_type_exists(PostTypes::COURSE));
        self::assertTrue(post_type_exists(PostTypes::INSTRUCTOR));
        self::assertTrue(post_type_exists(PostTypes::PROVIDER));
    }

    public function test_course_is_public_and_supports_editor_and_excerpt(): void
    {
        $courseType = get_post_type_object(PostTypes::COURSE);

        self::assertNotNull($courseType);
        self::assertTrue($courseType->public);
        self::assertTrue(post_type_supports(PostTypes::COURSE, 'editor'));
        self::assertTrue(post_type_supports(PostTypes::COURSE, 'excerpt'));
    }

    public function test_a_course_can_be_created_and_read_back(): void
    {
        $courseId = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => 'Graphic Design Foundation',
            'post_excerpt' => 'A short description.',
        ]);

        self::assertSame('Graphic Design Foundation', get_the_title($courseId));
        self::assertSame('A short description.', get_post($courseId)->post_excerpt);
    }
}
```

The excerpt assertion matters: the spec maps "short description" onto the excerpt, and excerpt support is off by default for custom post types.

- [ ] **Step 2: Run it and watch it fail**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostTypesTest"
```

Expected: FAIL — `Class "CourseDiscovery\ContentModel\PostTypes" not found`.

- [ ] **Step 3: Implement**

Create `plugins/course-discovery/src/ContentModel/PostTypes.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Registers the three content types the discovery system operates on.
 */
final class PostTypes
{
    public const COURSE = 'course';
    public const INSTRUCTOR = 'instructor';
    public const PROVIDER = 'provider';

    public static function register(): void
    {
        register_post_type(self::COURSE, [
            'labels'       => self::labels('Course', 'Courses'),
            'public'       => true,
            'has_archive'  => true,
            'menu_icon'    => 'dashicons-welcome-learn-more',
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'rewrite'      => ['slug' => 'courses'],
            'show_in_rest' => true,
        ]);

        register_post_type(self::INSTRUCTOR, [
            'labels'       => self::labels('Instructor', 'Instructors'),
            'public'       => true,
            'menu_icon'    => 'dashicons-businessperson',
            'supports'     => ['title', 'editor', 'thumbnail'],
            'show_in_rest' => true,
        ]);

        register_post_type(self::PROVIDER, [
            'labels'       => self::labels('Provider', 'Providers'),
            'public'       => true,
            'menu_icon'    => 'dashicons-building',
            'supports'     => ['title', 'editor', 'thumbnail'],
            'show_in_rest' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function labels(string $singular, string $plural): array
    {
        return [
            'name'          => $plural,
            'singular_name' => $singular,
            'add_new_item'  => sprintf('Add New %s', $singular),
            'edit_item'     => sprintf('Edit %s', $singular),
            'search_items'  => sprintf('Search %s', $plural),
            'not_found'     => sprintf('No %s found', strtolower($plural)),
        ];
    }
}
```

- [ ] **Step 4: Wire it into the plugin**

In `plugins/course-discovery/src/Plugin.php`, replace the body of `boot()`:

```php
    public static function boot(): void
    {
        add_action('init', [ContentModel\PostTypes::class, 'register']);
    }
```

- [ ] **Step 5: Run it and watch it pass**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostTypesTest"
```

Expected: `OK (3 tests, 9 assertions)`.

- [ ] **Step 6: Verify the static analysis gate still passes**

```bash
composer stan
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add plugins/course-discovery/
git commit -m "feat: register course, instructor, provider types"
```

---

### Task 5: Taxonomies

**Files:**
- Create: `plugins/course-discovery/src/ContentModel/Taxonomies.php`
- Modify: `plugins/course-discovery/src/Plugin.php`
- Test: `plugins/course-discovery/tests/Integration/ContentModel/TaxonomiesTest.php`

**Interfaces:**
- Consumes: `PostTypes::COURSE`, `PostTypes::PROVIDER`
- Produces: `Taxonomies::CATEGORY = 'course_category'`, `Taxonomies::LOCATION = 'location'`, `Taxonomies::register(): void`

- [ ] **Step 1: Write the failing test**

Create `plugins/course-discovery/tests/Integration/ContentModel/TaxonomiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;
use WP_UnitTestCase;

final class TaxonomiesTest extends WP_UnitTestCase
{
    public function test_category_is_hierarchical_and_attached_to_courses(): void
    {
        self::assertTrue(taxonomy_exists(Taxonomies::CATEGORY));
        self::assertTrue(is_taxonomy_hierarchical(Taxonomies::CATEGORY));
        self::assertContains(
            Taxonomies::CATEGORY,
            get_object_taxonomies(PostTypes::COURSE)
        );
    }

    public function test_location_is_attached_to_providers_not_courses(): void
    {
        self::assertTrue(taxonomy_exists(Taxonomies::LOCATION));
        self::assertContains(Taxonomies::LOCATION, get_object_taxonomies(PostTypes::PROVIDER));
        self::assertNotContains(Taxonomies::LOCATION, get_object_taxonomies(PostTypes::COURSE));
    }

    public function test_categories_support_parent_child_nesting(): void
    {
        $parentId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Design',
        ]);
        $childId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Graphic Design',
            'parent'   => $parentId,
        ]);

        self::assertSame($parentId, get_term($childId)->parent);
    }
}
```

The second test encodes assumption A1 — location belongs to providers, and courses derive it. Asserting it is *absent* from courses prevents someone later "helpfully" attaching it directly and silently breaking the derivation.

- [ ] **Step 2: Run it and watch it fail**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TaxonomiesTest"
```

Expected: FAIL — `Class "CourseDiscovery\ContentModel\Taxonomies" not found`.

- [ ] **Step 3: Implement**

Create `plugins/course-discovery/src/ContentModel/Taxonomies.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Course categories, and provider locations which courses derive from.
 */
final class Taxonomies
{
    public const CATEGORY = 'course_category';
    public const LOCATION = 'location';

    public static function register(): void
    {
        register_taxonomy(self::CATEGORY, [PostTypes::COURSE], [
            'labels'            => [
                'name'          => 'Course Categories',
                'singular_name' => 'Course Category',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'course-category'],
        ]);

        register_taxonomy(self::LOCATION, [PostTypes::PROVIDER], [
            'labels'            => [
                'name'          => 'Locations',
                'singular_name' => 'Location',
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
        ]);
    }
}
```

- [ ] **Step 4: Wire it in**

In `Plugin::boot()`, add below the post types line:

```php
        add_action('init', [ContentModel\Taxonomies::class, 'register']);
```

Post types register first because taxonomies reference them.

- [ ] **Step 5: Run it and watch it pass**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TaxonomiesTest"
```

Expected: `OK (3 tests, 7 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add plugins/course-discovery/
git commit -m "feat: register category and location taxonomies"
```

---

### Task 6: Start date normalisation (pure logic)

**Files:**
- Create: `plugins/course-discovery/src/ContentModel/StartDates.php`
- Test: `plugins/course-discovery/tests/Unit/ContentModel/StartDatesTest.php`

**Interfaces:**
- Consumes: nothing — no WordPress
- Produces: `StartDates::META_KEY = '_course_start_dates'`; `StartDates::parse(string $raw): ?int` returning a `YYYYMM` sort key or null; `StartDates::format(int $sortKey): string` returning `March 2026`; `StartDates::normaliseList(array $raw): list<int>` returning sorted unique sort keys

- [ ] **Step 1: Write the failing test**

Create `plugins/course-discovery/tests/Unit/ContentModel/StartDatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\ContentModel;

use CourseDiscovery\ContentModel\StartDates;
use PHPUnit\Framework\TestCase;

final class StartDatesTest extends TestCase
{
    public function test_it_parses_numeric_month_year(): void
    {
        self::assertSame(202603, StartDates::parse('03-2026'));
    }

    public function test_it_parses_month_names(): void
    {
        self::assertSame(202603, StartDates::parse('March-2026'));
        self::assertSame(202601, StartDates::parse('january-2026'));
    }

    public function test_it_rejects_malformed_input(): void
    {
        self::assertNull(StartDates::parse('not a date'));
        self::assertNull(StartDates::parse('13-2026'));
        self::assertNull(StartDates::parse('00-2026'));
        self::assertNull(StartDates::parse(''));
    }

    public function test_it_formats_for_display(): void
    {
        self::assertSame('March 2026', StartDates::format(202603));
    }

    public function test_it_sorts_chronologically_not_alphabetically(): void
    {
        $normalised = StartDates::normaliseList(['March-2026', 'January-2026', 'April-2026']);

        self::assertSame([202601, 202603, 202604], $normalised);
    }

    public function test_it_removes_duplicates(): void
    {
        self::assertSame([202603], StartDates::normaliseList(['03-2026', 'March-2026']));
    }

    public function test_it_discards_invalid_entries_from_a_list(): void
    {
        self::assertSame([202603], StartDates::normaliseList(['03-2026', 'rubbish', '']));
    }
}
```

`test_it_sorts_chronologically_not_alphabetically` is the regression guard for the spec's trap: sorted as strings, April precedes January.

- [ ] **Step 2: Run it and watch it fail**

```bash
composer test:unit
```

Expected: FAIL — `Class "CourseDiscovery\ContentModel\StartDates" not found`.

- [ ] **Step 3: Implement**

Create `plugins/course-discovery/src/ContentModel/StartDates.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Parsing, normalisation and display of {month}-{year} start dates.
 *
 * Dates are stored as an integer sort key (YYYYMM) so that ordering is
 * chronological by construction rather than by an easily-forgotten callback.
 */
final class StartDates
{
    public const META_KEY = '_course_start_dates';

    private const MONTH_NAMES = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * Parses "03-2026" or "March-2026" into the sort key 202603.
     */
    public static function parse(string $raw): ?int
    {
        $trimmed = trim($raw);

        if (! preg_match('/^([A-Za-z]+|\d{1,2})-(\d{4})$/', $trimmed, $matches)) {
            return null;
        }

        $monthPart = $matches[1];
        $year = (int) $matches[2];

        $month = ctype_digit($monthPart)
            ? (int) $monthPart
            : (self::MONTH_NAMES[strtolower($monthPart)] ?? 0);

        if ($month < 1 || $month > 12) {
            return null;
        }

        return $year * 100 + $month;
    }

    /**
     * Renders the sort key 202603 as "March 2026".
     */
    public static function format(int $sortKey): string
    {
        $year = intdiv($sortKey, 100);
        $month = $sortKey % 100;

        $names = array_keys(self::MONTH_NAMES);

        return ucfirst($names[$month - 1]) . ' ' . $year;
    }

    /**
     * @param  list<string> $raw
     * @return list<int>    unique sort keys, chronologically ascending
     */
    public static function normaliseList(array $raw): array
    {
        $keys = [];

        foreach ($raw as $entry) {
            $parsed = self::parse($entry);

            if ($parsed !== null) {
                $keys[] = $parsed;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

```bash
composer test:unit
```

Expected: `OK (7 tests, 11 assertions)`.

- [ ] **Step 5: Verify PHPStan**

```bash
composer stan
```

Expected: `[OK] No errors`. Level 9 will reject a loose `array` annotation here — the `list<string>` and `list<int>` shapes above are required.

- [ ] **Step 6: Commit**

```bash
git add plugins/course-discovery/
git commit -m "feat: add start date parsing and normalisation"
```

---

### Task 7: Start dates meta box

**Files:**
- Create: `plugins/course-discovery/src/ContentModel/StartDatesMetaBox.php`
- Modify: `plugins/course-discovery/src/Plugin.php`
- Test: `plugins/course-discovery/tests/Integration/ContentModel/StartDatesMetaBoxTest.php`

**Interfaces:**
- Consumes: `StartDates::META_KEY`, `StartDates::normaliseList()`, `PostTypes::COURSE`
- Produces: `StartDatesMetaBox::register(): void`, `StartDatesMetaBox::save(int $postId): void`

- [ ] **Step 1: Write the failing test**

Create `plugins/course-discovery/tests/Integration/ContentModel/StartDatesMetaBoxTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\StartDatesMetaBox;
use WP_UnitTestCase;

final class StartDatesMetaBoxTest extends WP_UnitTestCase
{
    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_it_saves_normalised_sorted_dates(): void
    {
        $_POST['course_start_dates'] = ['March-2026', 'January-2026'];
        $_POST['course_start_dates_nonce'] = wp_create_nonce('course_start_dates_save');

        StartDatesMetaBox::save($this->courseId);

        self::assertSame(
            [202601, 202603],
            get_post_meta($this->courseId, StartDates::META_KEY, true)
        );
    }

    public function test_it_ignores_the_request_without_a_valid_nonce(): void
    {
        $_POST['course_start_dates'] = ['March-2026'];
        $_POST['course_start_dates_nonce'] = 'forged';

        StartDatesMetaBox::save($this->courseId);

        self::assertSame('', get_post_meta($this->courseId, StartDates::META_KEY, true));
    }

    protected function tearDown(): void
    {
        unset($_POST['course_start_dates'], $_POST['course_start_dates_nonce']);

        parent::tearDown();
    }
}
```

The nonce test is deliberate: an unauthenticated write path into post meta is a real vulnerability, and the spec's brief mentions sound engineering practice. Writing the failing security test before the implementation is the discipline being demonstrated.

- [ ] **Step 2: Run it and watch it fail**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter StartDatesMetaBoxTest"
```

Expected: FAIL — `Class "CourseDiscovery\ContentModel\StartDatesMetaBox" not found`.

- [ ] **Step 3: Implement**

Create `plugins/course-discovery/src/ContentModel/StartDatesMetaBox.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

use WP_Post;

/**
 * Admin UI for the repeating start-date list.
 *
 * ACF's Repeater is PRO-only, so this is hand-rolled. The upside is full
 * control over the stored format, which is what makes chronological
 * ordering cheap at query time.
 */
final class StartDatesMetaBox
{
    private const NONCE_ACTION = 'course_start_dates_save';
    private const NONCE_NAME = 'course_start_dates_nonce';
    private const FIELD_NAME = 'course_start_dates';

    public static function register(): void
    {
        add_meta_box(
            'course-start-dates',
            'Start Dates',
            [self::class, 'render'],
            PostTypes::COURSE,
            'normal',
            'default'
        );
    }

    public static function render(WP_Post $post): void
    {
        /** @var list<int>|string $stored */
        $stored = get_post_meta($post->ID, StartDates::META_KEY, true);
        $keys = is_array($stored) ? $stored : [];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        echo '<p class="description">One per line, as <code>MM-YYYY</code> or <code>Month-YYYY</code>.</p>';
        echo '<div id="course-start-dates-rows">';

        foreach ($keys as $key) {
            printf(
                '<p><input type="text" name="%s[]" value="%s" class="regular-text" /></p>',
                esc_attr(self::FIELD_NAME),
                esc_attr(self::toInputValue($key))
            );
        }

        printf(
            '<p><input type="text" name="%s[]" value="" class="regular-text" placeholder="03-2026" /></p>',
            esc_attr(self::FIELD_NAME)
        );

        echo '</div>';
    }

    public static function save(int $postId): void
    {
        $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_NAME])) : '';

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $raw = isset($_POST[self::FIELD_NAME]) && is_array($_POST[self::FIELD_NAME])
            ? array_map(static fn (mixed $v): string => sanitize_text_field(wp_unslash((string) $v)), $_POST[self::FIELD_NAME])
            : [];

        $normalised = StartDates::normaliseList(array_values($raw));

        update_post_meta($postId, StartDates::META_KEY, $normalised);
    }

    private static function toInputValue(int $sortKey): string
    {
        return sprintf('%02d-%d', $sortKey % 100, intdiv($sortKey, 100));
    }
}
```

- [ ] **Step 4: Wire it in**

In `Plugin::boot()`, add:

```php
        add_action('add_meta_boxes', [ContentModel\StartDatesMetaBox::class, 'register']);
        add_action('save_post_' . ContentModel\PostTypes::COURSE, [ContentModel\StartDatesMetaBox::class, 'save']);
```

- [ ] **Step 5: Run it and watch it pass**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter StartDatesMetaBoxTest"
```

Expected: `OK (2 tests, 2 assertions)`.

- [ ] **Step 6: Verify by hand in the admin**

Open a course in wp-admin, enter `03-2026` and `January-2026` in the Start Dates box, save, and reopen. Expected: both persist, displayed as `01-2026` first then `03-2026` — chronological, not input order.

- [ ] **Step 7: Run the full gate and commit**

```bash
composer stan && composer test:unit
git add plugins/course-discovery/
git commit -m "feat: add start dates meta box"
```

---

### Task 8: ACF fields and admin columns

**Files:**
- Create: `plugins/course-discovery/src/ContentModel/AcfFields.php`
- Create: `plugins/course-discovery/src/ContentModel/AdminColumns.php`
- Modify: `plugins/course-discovery/src/Plugin.php`
- Test: `plugins/course-discovery/tests/Integration/ContentModel/AdminColumnsTest.php`

**Interfaces:**
- Consumes: `PostTypes::*`, `StartDates::format()`, `StartDates::META_KEY`
- Produces: `AcfFields::FIELD_PRICE = 'course_price'`, `AcfFields::FIELD_INSTRUCTORS = 'course_instructors'`, `AcfFields::FIELD_PROVIDERS = 'course_providers'`, `AcfFields::register(): void`; `AdminColumns::register(): void`

- [ ] **Step 1: Install ACF**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && curl -sL https://downloads.wordpress.org/plugin/advanced-custom-fields.latest-stable.zip -o /tmp/acf.zip && unzip -oq /tmp/acf.zip -d wp-content/plugins/"
```

Activate "Advanced Custom Fields" in wp-admin → Plugins. Field *groups* are defined in code below, so nothing lives only in the database.

- [ ] **Step 2: Implement the field group**

Create `plugins/course-discovery/src/ContentModel/AcfFields.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * ACF field groups declared in PHP rather than stored in the database,
 * so a fresh clone reproduces the content model exactly.
 */
final class AcfFields
{
    public const FIELD_PRICE = 'course_price';
    public const FIELD_INSTRUCTORS = 'course_instructors';
    public const FIELD_PROVIDERS = 'course_providers';

    public static function register(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => 'group_course_details',
            'title'    => 'Course Details',
            'location' => [[[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => PostTypes::COURSE,
            ]]],
            'fields'   => [
                [
                    'key'           => 'field_' . self::FIELD_PRICE,
                    'label'         => 'Price',
                    'name'          => self::FIELD_PRICE,
                    'type'          => 'number',
                    'min'           => 0,
                    'instructions'  => 'Whole currency units.',
                ],
                [
                    'key'           => 'field_' . self::FIELD_INSTRUCTORS,
                    'label'         => 'Instructors',
                    'name'          => self::FIELD_INSTRUCTORS,
                    'type'          => 'relationship',
                    'post_type'     => [PostTypes::INSTRUCTOR],
                    'return_format' => 'id',
                ],
                [
                    'key'           => 'field_' . self::FIELD_PROVIDERS,
                    'label'         => 'Providers',
                    'name'          => self::FIELD_PROVIDERS,
                    'type'          => 'relationship',
                    'post_type'     => [PostTypes::PROVIDER],
                    'return_format' => 'id',
                ],
            ],
        ]);
    }
}
```

`return_format => 'id'` matters — it keeps stored values as post IDs, which the Plan 2 indexer reads directly.

- [ ] **Step 3: Write the failing admin columns test**

Create `plugins/course-discovery/tests/Integration/ContentModel/AdminColumnsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\AdminColumns;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use WP_UnitTestCase;

final class AdminColumnsTest extends WP_UnitTestCase
{
    public function test_it_adds_a_next_start_column(): void
    {
        $columns = AdminColumns::columns(['title' => 'Title', 'date' => 'Date']);

        self::assertArrayHasKey('next_start', $columns);
        self::assertSame('Title', $columns['title']);
    }

    public function test_it_renders_the_earliest_start_date(): void
    {
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, [202601, 202603]);

        ob_start();
        AdminColumns::render('next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('January 2026', $output);
    }

    public function test_it_renders_a_dash_when_no_dates_are_set(): void
    {
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);

        ob_start();
        AdminColumns::render('next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('—', $output);
    }
}
```

- [ ] **Step 4: Run it and watch it fail**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminColumnsTest"
```

Expected: FAIL — `Class "CourseDiscovery\ContentModel\AdminColumns" not found`.

- [ ] **Step 5: Implement**

Create `plugins/course-discovery/src/ContentModel/AdminColumns.php`:

```php
<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Extra columns on the course list screen, satisfying the brief's
 * "admin dashboard for managing and administering courses".
 */
final class AdminColumns
{
    public static function register(): void
    {
        add_filter('manage_' . PostTypes::COURSE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . PostTypes::COURSE . '_posts_custom_column', [self::class, 'render'], 10, 2);
    }

    /**
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $reordered = [];

        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $reordered['next_start'] = 'Next Start';
            }

            $reordered[$key] = $label;
        }

        if (! isset($reordered['next_start'])) {
            $reordered['next_start'] = 'Next Start';
        }

        return $reordered;
    }

    public static function render(string $column, int $postId): void
    {
        if ($column !== 'next_start') {
            return;
        }

        /** @var list<int>|string $stored */
        $stored = get_post_meta($postId, StartDates::META_KEY, true);

        if (! is_array($stored) || $stored === []) {
            echo '—';

            return;
        }

        echo esc_html(StartDates::format(min($stored)));
    }
}
```

- [ ] **Step 6: Wire both into the plugin**

In `Plugin::boot()`, add:

```php
        add_action('acf/init', [ContentModel\AcfFields::class, 'register']);
        add_action('admin_init', [ContentModel\AdminColumns::class, 'register']);
```

- [ ] **Step 7: Run it and watch it pass**

```bash
docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminColumnsTest"
```

Expected: `OK (3 tests, 4 assertions)`.

- [ ] **Step 8: Verify the complete gate**

```bash
composer stan && composer test:unit && docker compose exec wordpress bash -c "cd /var/www/html && vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Expected: no PHPStan errors, all unit tests pass, all integration tests pass.

- [ ] **Step 9: Author real content by hand**

In wp-admin create two providers (each with a location term), two instructors, and three courses linking them, with start dates and categories including a nested `Design > Graphic Design`. This is the fixture data Plan 2's indexer will be developed against.

- [ ] **Step 10: Commit**

```bash
git add plugins/course-discovery/
git commit -m "feat: add acf fields and admin columns"
```

---

## Definition of Done

- [ ] `docker compose up -d` yields a working WordPress at `http://localhost:8080`
- [ ] `composer stan` reports zero errors at level 9
- [ ] `composer test:unit` passes
- [ ] The integration suite passes inside the container
- [ ] Course, instructor and provider appear in wp-admin with working editors
- [ ] Categories nest; locations attach to providers only
- [ ] Start dates persist, normalise, and display chronologically
- [ ] The course list shows a Next Start column
- [ ] Sample content exists for Plan 2
