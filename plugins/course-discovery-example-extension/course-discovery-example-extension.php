<?php

/**
 * Plugin Name: Course Discovery Example Extension
 * Description: Adds an Instructor filter to Course Discovery entirely through its public hooks -- proof that a new filter needs zero changes to the core plugin.
 * Version: 0.1.0
 * Requires PHP: 8.3
 * Text Domain: course-discovery-example
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whether the main plugin is loaded and can be depended on.
 *
 * Extracted from course_discovery_example_bootstrap() as a tiny, named,
 * behaviour-preserving seam purely for testability (see
 * ExampleExtensionTest): the main plugin is genuinely active
 * in the integration test environment and cannot be un-loaded from the same
 * PHP process once its classes are declared, so a test cannot exercise the
 * TRUE bail branch of course_discovery_example_bootstrap() end-to-end. What
 * this DOES let a test prove directly is that the guard's own boolean logic
 * correctly reports "missing" for a class that genuinely does not exist --
 * $requiredClass is a plain string (not `class-string`) specifically so a
 * name that names no real class, like the test's own probe value, is a
 * legal argument, not a type error. Every real caller below uses the
 * default, so production behaviour --
 * class_exists(\CourseDiscovery\Plugin::class) -- is unchanged.
 *
 * @param string $requiredClass
 */
function course_discovery_example_main_plugin_missing(string $requiredClass = \CourseDiscovery\Plugin::class): bool
{
    return ! class_exists($requiredClass);
}

/**
 * The admin notice shown when the guard above bails.
 *
 * Extracted to a named function, rather than left as the inline closure
 * add_action() received before, for the same testability reason as the
 * guard above: a named function can be called directly from a test and its
 * escaped output asserted on, without needing admin_notices to actually
 * fire. WordPress dedupes a hook registration by (tag, priority, callable
 * identity) for a string callable exactly as it does for a closure, so
 * passing the function name to add_action() below is no less idempotent
 * than the closure it replaces.
 */
function course_discovery_example_missing_dependency_notice(): void
{
    echo '<div class="notice notice-error"><p>'
        . esc_html__(
            'Course Discovery Example Extension requires the Course Discovery plugin to be active.',
            'course-discovery-example'
        )
        . '</p></div>';
}

/**
 * Guards, loads, and wires up this extension. The whole of its integration
 * with Course Discovery is two hook registrations:
 *
 * - course_discovery/register_filters: the extension seam FilterRegistry::
 *   boot() fires, with the registry as its argument, AFTER every core
 *   filter is already registered (see FilterRegistry::boot()'s own
 *   docblock). Adding a filter here requires no change to FilterRegistry,
 *   nor to any core filter file.
 * - course_discovery/indexed_course: the indexer's public extension seam,
 *   fired after a course's built-in attribute rows are committed, with the
 *   SAME CourseIndexer instance the core indexer just used.
 *   addAttributeValues() writes through the exact same prepared, batched
 *   insert path the indexer's own built-in attributes use (see
 *   CourseIndexer::addAttributeValues()'s docblock) -- so 'instructor' becomes
 *   a queryable attribute with no schema migration, because the attribute table is
 *   keyed by attribute NAME rather than one column per dimension.
 *
 * Deliberately a named, idempotent function -- called once from
 * `plugins_loaded` below for a real request, but safe to call again --
 * rather than bare top-level code, for two independent reasons:
 *
 * 1. The class_exists() dependency guard cannot run unconditionally at the
 *    top level of this file. WordPress includes every active plugin's main
 *    file in the order stored in the `active_plugins` option, which is NOT
 *    guaranteed to place a dependency before a dependent -- observed here
 *    first-hand: `wp plugin activate` alone was enough to leave this
 *    extension positioned BEFORE course-discovery in that option. A
 *    top-level class_exists(\CourseDiscovery\Plugin::class) check would
 *    then intermittently, and silently, see the main plugin as "not yet
 *    loaded" and bail via the admin notice below, even though it is
 *    genuinely active and finishes loading moments later in the SAME
 *    request. `plugins_loaded` is the one point WordPress core guarantees
 *    fires only after EVERY active plugin's main file has already been
 *    included, regardless of their relative order in that option -- see
 *    https://developer.wordpress.org/reference/hooks/plugins_loaded/.
 * 2. wp-phpunit's WP_UnitTestCase restores $wp_filter to a process-wide
 *    snapshot on every test's tear_down() (see
 *    WP_UnitTestCase::_restore_hooks()), taken at the very first test's
 *    set_up() across the WHOLE integration suite -- which runs before this
 *    file is ever required when another test class happens to run first,
 *    so that snapshot never includes this function's registrations. Since
 *    `plugins_loaded` has already fired long before any test runs, the
 *    add_action('plugins_loaded', ...) below never fires again inside the
 *    test process -- so ExampleExtensionTest instead calls this function
 *    directly, from its own setUp(), before every test. The static
 *    closures make that safe: the SAME closure object is passed to
 *    add_action() on every call, and WordPress dedupes a hook registration
 *    by (tag, priority, callable identity), so repeat calls re-add exactly
 *    one listener per hook, never two.
 */
function course_discovery_example_bootstrap(): void
{
    static $onRegisterFilters = null;
    static $onIndexedCourse = null;

    if (course_discovery_example_main_plugin_missing()) {
        add_action('admin_notices', 'course_discovery_example_missing_dependency_notice');

        return;
    }

    // This plugin's one class. No Composer autoloader of its own -- a
    // single require is simpler than a PSR-4 addition and avoids touching
    // the main plugin's composer.json for one file. Deliberately AFTER the
    // guard above: InstructorFilter `implements Filter`, so PHP must
    // resolve CourseDiscovery\Domain\Filter\Filter the moment the class is
    // declared -- requiring it while the main plugin's autoloader is not
    // yet registered would fatal on that interface lookup instead of
    // failing gracefully above.
    require_once __DIR__ . '/src/InstructorFilter.php';

    if ($onRegisterFilters === null) {
        $onRegisterFilters = static function (\CourseDiscovery\Filter\FilterRegistry $registry): void {
            $registry->register(new \CourseDiscoveryExample\InstructorFilter());
        };
    }

    if ($onIndexedCourse === null) {
        $onIndexedCourse = static function (int $courseId, \CourseDiscovery\Index\CourseIndexer $indexer): void {
            $ids = \CourseDiscovery\ContentModel\CourseMeta::relationshipIds(
                $courseId,
                \CourseDiscovery\ContentModel\AcfFields::FIELD_INSTRUCTORS
            );

            $indexer->addAttributeValues($courseId, \CourseDiscoveryExample\InstructorFilter::ATTRIBUTE, $ids);
        };
    }

    add_action('course_discovery/register_filters', $onRegisterFilters);
    add_action('course_discovery/indexed_course', $onIndexedCourse, 10, 2);
}

add_action('plugins_loaded', 'course_discovery_example_bootstrap');
