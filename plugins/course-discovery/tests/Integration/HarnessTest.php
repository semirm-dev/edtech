<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

final class HarnessTest extends IntegrationTestCase
{
    public function test_wordpress_is_loaded(): void
    {
        self::assertTrue(function_exists('wp_insert_post'));
    }

    public function test_the_plugin_is_active(): void
    {
        // class_exists() alone would pass purely from Composer's PSR-4
        // autoloader, regardless of whether WordPress ever loaded the
        // plugin. Assert the entry file was actually require()'d by the
        // bootstrap's `muplugins_loaded` filter instead.
        //
        // Computed via WP_PLUGIN_DIR, not a path relative to this test
        // file's own __DIR__: bootstrap-integration.php requires the entry
        // file through WP_PLUGIN_DIR . '/course-discovery/course-
        // discovery.php' -- the same path a real WordPress request loads it
        // from -- so that __FILE__ resolves under WP_PLUGIN_DIR the way
        // plugins_url() requires (see that file's own docblock). Under
        // DDEV, WP_PLUGIN_DIR . '/course-discovery' and this test file's
        // own project-root-relative plugin directory are two different
        // bind mounts of the SAME host directory, not the same absolute
        // path -- get_included_files() records whichever one was actually
        // require()'d, so this must match the bootstrap, not this file's
        // own location.
        $pluginFile = realpath(WP_PLUGIN_DIR . '/course-discovery/course-discovery.php');

        self::assertIsString($pluginFile, 'Could not resolve course-discovery.php.');
        self::assertContains($pluginFile, get_included_files());
    }
}
