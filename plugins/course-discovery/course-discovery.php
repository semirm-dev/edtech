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
 * Locate Composer's autoloader by walking upward.
 *
 * The plugin sits at a different depth under DDEV (docroot `wp/`) than under
 * a typical production container (docroot `/var/www/html`), so a fixed
 * relative path would work in one environment and silently fail in the other.
 */
$courseDiscoveryAutoload = null;
$courseDiscoverySearchDir = __DIR__;

for ($i = 0; $i < 6; $i++) {
    $candidate = $courseDiscoverySearchDir . '/vendor/autoload.php';

    if (is_readable($candidate)) {
        $courseDiscoveryAutoload = $candidate;
        break;
    }

    $courseDiscoverySearchDir = dirname($courseDiscoverySearchDir);
}

if ($courseDiscoveryAutoload === null) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Course Discovery: run <code>composer install</code>.</p></div>';
    });

    return;
}

require_once $courseDiscoveryAutoload;

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
