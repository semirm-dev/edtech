<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

use CourseDiscovery\Plugin;
use WP_UnitTestCase;

/**
 * Shared base for every WordPress integration test class.
 *
 * wp-phpunit 7.0.2's WP_UnitTestCase::set_up() unconditionally calls a
 * method that no longer exists under PHPUnit 11 (see Phpunit11CompatTrait
 * for the full explanation); any class extending WP_UnitTestCase directly
 * fatals in set_up() unless it also mixes in that trait. Centralising both
 * "extends WP_UnitTestCase" and "use Phpunit11CompatTrait" here means later
 * test classes only need to extend IntegrationTestCase and can't forget the
 * trait.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    use Phpunit11CompatTrait;

    /**
     * Plugin::$container is a static, process-wide singleton,
     * but wp-phpunit runs every test in the same PHP process. Without this
     * reset, a stub registered via Container::set() in one test (a fake
     * repository, a test registry) would silently
     * leak into every test that runs after it. See
     * Plugin::resetContainer()'s own docblock, and
     * PluginTest::test_container_a_.../test_container_b_... for a test
     * that pins this behaviour.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        Plugin::resetContainer();
    }
}
