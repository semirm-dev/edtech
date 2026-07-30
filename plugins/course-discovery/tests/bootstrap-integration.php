<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

require_once $projectRoot . '/vendor/autoload.php';

// wp-phpunit's own bootstrap.php checks this PHP constant (not an env var)
// to locate wp-tests-config.php. Defining it here means `composer
// test:integration` works standalone, with no out-of-band env var required
// from the caller.
if (! defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', $projectRoot . '/wp-tests-config.php');
}

$wpTests = getenv('WP_TESTS_DIR') ?: $projectRoot . '/vendor/wp-phpunit/wp-phpunit';

require_once $wpTests . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    // Requires the entry file through WP_PLUGIN_DIR (defined by WordPress
    // core well before 'muplugins_loaded' fires -- see
    // wp_plugin_directory_constants()), the same path a real WordPress
    // request loads it from, rather than a path relative to this file
    // (which sits at the Composer project root, a different absolute path
    // from WP_PLUGIN_DIR in both DDEV and a production container --
    // see course-discovery.php's own docblock on that split). __FILE__
    // inside course-discovery.php must resolve under WP_PLUGIN_DIR for
    // plugins_url()/plugin_basename() to work at all (see Assets.php and
    // Plugin::boot()); requiring it from the "wrong" path here would make
    // every test exercise a plugin instance that could never occur outside
    // this test suite, silently hiding the exact bug this class of test
    // exists to catch.
    require WP_PLUGIN_DIR . '/course-discovery/course-discovery.php';
});

require $wpTests . '/includes/bootstrap.php';

/*
 * Create the index tables once, before the first test runs.
 *
 * In production MigrationRunner runs on activation and on every admin_init;
 * neither fires under wp-phpunit. So on a *fresh* wordpress_test database the
 * lookup tables do not exist until some test class creates them through
 * UsesIndexTables::prepareIndexTables() -- and any test that merely saves a
 * course before that point (IndexInvalidator listens on wp_after_insert_post)
 * makes wpdb print "Table ... doesn't exist", which failOnWarning turns into a
 * failed run.
 *
 * Worse, it fails only once: the tables that first run does create survive it
 * (DDL commits, and nothing drops them between runs), so run two passes and
 * the failure reads as flakiness rather than as a missing setup step. That is
 * what a fresh clone hits.
 *
 * Doing it here is safe in a way the per-test path is not: at bootstrap no
 * test has called start_transaction() yet, so wp-phpunit's CREATE TABLE ->
 * CREATE TEMPORARY TABLE rewrite is not installed and the FULLTEXT index
 * M001CreateLookupTables needs is legal. This adds no global `query` filter --
 * the warning in UsesIndexTables against that still stands, and the trait is
 * still what gives an individual test class real DDL and empty tables.
 */
(static function (): void {
    global $wpdb;

    $schema = new CourseDiscovery\Index\Schema($wpdb);

    (new CourseDiscovery\Index\MigrationRunner($wpdb, $schema))->run();
})();
