<?php

/**
 * Plugin Name: Course Discovery
 * Description: Extensible course discovery system.
 * Version: 0.1.0
 * Requires PHP: 8.3
 * Text Domain: course-discovery
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Autoloads this plugin's own classes, PSR-4, from its own directory.
 *
 * Deliberately not Composer's autoloader. That map lives in the repository's
 * root `vendor/`, four levels above this file under DDEV and a different depth
 * again in the deployment image, so this file used to locate it by walking
 * parent directories. That works in this repository and nowhere else: unzip
 * the plugin into an ordinary WordPress install's `wp-content/plugins/` and
 * there is no `vendor/` above it at all, so the plugin would disable itself
 * behind a "run composer install" notice. On a Bedrock-style site the walk
 * fails worse than that -- it FINDS `vendor/autoload.php` at the web root, an
 * autoloader that has never heard of `CourseDiscovery\`, and the first class
 * reference fatals rather than degrading.
 *
 * Nothing is given up by dropping it. The plugin has no runtime Composer
 * dependencies -- `require` is `php >= 8.3`, with PHPUnit and PHPStan dev-only
 * -- so the only thing Composer's autoloader ever did at runtime was map
 * `CourseDiscovery\` onto `src/`, which is what this closure does. Tests and
 * static analysis still use the root autoloader (they require it themselves,
 * before this file), and the two agree on every class they both resolve.
 *
 * Rooted at __DIR__ rather than at a repository path, so the classes come
 * from the same directory WordPress loaded this file from -- see AssetsTest's
 * note on `plugins_url()` and the two paths this plugin exists at under DDEV.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'CourseDiscovery\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    // Checked rather than required blindly: `CourseDiscovery\Tests\` maps to
    // tests/, not src/, so it resolves here to a path that does not exist.
    // Returning quietly leaves those to the root autoloader's autoload-dev
    // section instead of fataling on a missing file.
    if (is_readable($path)) {
        require $path;
    }
});

/**
 * Registers post types and taxonomies then flushes rewrite rules, so
 * /courses/, /instructors/ and /course-category/... work immediately on
 * activation instead of 404ing until an admin re-saves Settings ->
 * Permalinks. Runs once on activation only -- flushing on every request
 * is a well-known WordPress performance mistake.
 */
register_activation_hook(__FILE__, static function (): void {
    (new CourseDiscovery\ContentModel\PostTypes())->register();
    (new CourseDiscovery\ContentModel\Taxonomies())->register();

    global $wpdb;

    $schema = new CourseDiscovery\Index\Schema($wpdb);
    (new CourseDiscovery\Index\MigrationRunner($wpdb, $schema))->run();

    flush_rewrite_rules();
});

CourseDiscovery\Plugin::boot(__FILE__);
