<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SortOrder;
use CourseDiscovery\Filter\CategoryFilter;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Filter\KeywordFilter;
use CourseDiscovery\Filter\LocationFilter;
use CourseDiscovery\Filter\ProviderFilter;
use CourseDiscovery\Filter\StartDateFilter;
use CourseDiscovery\Frontend\FormRenderer;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Plugin;
use CourseDiscovery\Query\WhereClauseBuilder;
use CourseDiscovery\Query\WpCourseRepository;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;
use CourseDiscoveryExample\InstructorFilter;

/**
 * Proves the Open/Closed claim mechanically: a SEPARATE plugin
 * (course-discovery-example-extension) adds a sixth "instructor" filter
 * through course-discovery's public hooks alone, with ZERO changes to any
 * file under plugins/course-discovery/src/. See
 * course-discovery-example-extension.php and
 * CourseDiscoveryExample\InstructorFilter for the implementation.
 *
 * The extension's bootstrap is loaded here by requiring it directly from
 * its real filesystem location, mirroring exactly how
 * bootstrap-integration.php loads the MAIN plugin (a bare `require` of its
 * root file on muplugins_loaded) -- the wp-phpunit harness does not model
 * WordPress's own plugin activation flow, so this is the equivalent for a
 * second plugin. require_once in setUpBeforeClass() means the require
 * itself -- loading the InstructorFilter class and declaring the
 * course_discovery_example_bootstrap() function -- runs exactly once for
 * the whole class, however many test methods run in this single PHP
 * process. That function's guard, class require, and hook registrations
 * are re-run from THIS class's own setUp() below; see that method's
 * docblock for why.
 */
final class ExampleExtensionTest extends IntegrationTestCase
{
    use UsesIndexTables;

    /**
     * Whether add_action('plugins_loaded', 'course_discovery_example_bootstrap')
     * -- the literal top-level line at the bottom of the extension's main
     * file -- actually ran when that file was required below.
     *
     * Captured here, inside setUpBeforeClass(), rather than read fresh
     * inside a test method: WP_UnitTestCase's hook backup/restore (see
     * abstract-testcase.php's _backup_hooks()/_restore_hooks()) snapshots
     * $wp_filter ONCE, at the very first test's set_up() across the WHOLE
     * integration suite, and every test's tear_down() restores $wp_filter
     * to that one snapshot. When some other test class happens to run
     * first, that snapshot predates this file ever being required, so a
     * has_action() check made from inside a test METHOD (after its own
     * tear_down(), or any earlier test's tear_down(), has already run)
     * would unpredictably read as unregistered even though the add_action()
     * call genuinely executed. setUpBeforeClass() runs once, immediately
     * after the require below and before this class's own first set_up() /
     * tear_down() cycle has any chance to restore hooks -- the one point in
     * the whole run where has_action() reflects the require's real,
     * unmolested effect regardless of suite ordering.
     */
    private static bool|int $pluginsLoadedHookState = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 4)
            . '/course-discovery-example-extension/course-discovery-example-extension.php';

        self::$pluginsLoadedHookState = has_action('plugins_loaded', 'course_discovery_example_bootstrap');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Two harness quirks, both worked around here rather than in the
        // extension itself:
        //
        // 1. wp-phpunit's WP_UnitTestCase takes ONE process-wide $wp_filter
        //    snapshot, at the very first test's set_up() across the WHOLE
        //    suite, and every test's tear_down() restores $wp_filter to
        //    it (see WP_UnitTestCase::_backup_hooks()/_restore_hooks()).
        //    When another test class runs first, that snapshot is taken
        //    before this class's setUpBeforeClass() ever required the
        //    extension, so it never contains this extension's listeners --
        //    and every tear_down() in the suite wipes them straight back
        //    out again. course_discovery_example_bootstrap() is written to
        //    be idempotent (see its own docblock) specifically so it is
        //    safe to call again, here, before every single test.
        //
        // 2. Plugin::$container is a static, process-wide singleton (see
        //    its own docblock) that IntegrationTestCase::tearDown() resets
        //    after every test -- but not before the FIRST one. If some
        //    earlier code already resolved FilterRegistry::class into the
        //    container (e.g. Plugin::boot()'s `init` callback resolving
        //    Search\SearchService, which resolves FilterRegistry too) while
        //    this extension's hook was not yet registered, that stale,
        //    pre-extension registry stays cached until the next reset.
        //    Resetting here as well guarantees every test in this class
        //    forces a fresh FilterRegistry::boot() call against whatever
        //    hooks are ACTUALLY registered right now.
        //
        // course_discovery_example_bootstrap() is called directly rather
        // than relying on its add_action('plugins_loaded', ...) — that
        // hook already fired once, during wp-tests' initial bootstrap,
        // long before this file was ever required. See that function's
        // own docblock for why it is safe (idempotent) to call again here.
        course_discovery_example_bootstrap();
        \CourseDiscovery\Plugin::resetContainer();

        $this->prepareIndexTables();
    }

    /**
     * @return int the created instructor's post id
     */
    private function makeInstructor(string $title = 'Ada Lovelace'): int
    {
        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'   => PostTypes::INSTRUCTOR,
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);

        return $id;
    }

    /**
     * @param list<int> $instructorIds
     */
    private function makeCourse(array $instructorIds = [], string $title = 'Test Course'): int
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);

        // ACF stores relationship values as numeric STRINGS, not ints --
        // see CourseMeta::relationshipIds()'s docblock.
        update_post_meta($courseId, AcfFields::FIELD_INSTRUCTORS, array_map('strval', $instructorIds));

        return $courseId;
    }

    /**
     * The registry built the same way production code builds it --
     * Plugin::container()->get(FilterRegistry::class) -- so this exercises
     * the real core-filter list from Plugin::container(), not a hand-copied
     * duplicate of it that could silently drift out of sync.
     */
    public function test_the_registry_contains_a_filter_keyed_instructor(): void
    {
        $registry = Plugin::container()->get(FilterRegistry::class);

        self::assertTrue(
            $registry->has(InstructorFilter::KEY),
            'FilterRegistry::boot(), fired with the extension bootstrap loaded, must contain "instructor".'
        );
        self::assertInstanceOf(InstructorFilter::class, $registry->get(InstructorFilter::KEY));
    }

    /**
     * Step 1's mechanical "zero changes to the main plugin" check: every
     * core key still resolves to its ORIGINAL core class, and the registry
     * holds exactly the five core filters plus the one the extension added
     * -- nothing was renamed, replaced, or dropped by the extension being
     * present.
     */
    public function test_the_extension_leaves_every_core_filters_key_and_class_unchanged(): void
    {
        $registry = Plugin::container()->get(FilterRegistry::class);

        self::assertCount(
            6,
            $registry,
            'Five core filters plus the extension\'s instructor filter, no more, no fewer.'
        );

        self::assertInstanceOf(KeywordFilter::class, $registry->get(KeywordFilter::KEY));
        self::assertInstanceOf(ProviderFilter::class, $registry->get(ProviderFilter::KEY));
        self::assertInstanceOf(LocationFilter::class, $registry->get(LocationFilter::KEY));
        self::assertInstanceOf(StartDateFilter::class, $registry->get(StartDateFilter::KEY));
        self::assertInstanceOf(CategoryFilter::class, $registry->get(CategoryFilter::KEY));
        self::assertInstanceOf(InstructorFilter::class, $registry->get(InstructorFilter::KEY));
    }

    public function test_it_offers_published_instructors_as_options(): void
    {
        $this->makeInstructor('Grace Hopper');

        $options = (new InstructorFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains('Grace Hopper', $labels);
    }

    public function test_constrain_builds_a_attribute_in_constraint_on_the_instructor_attribute(): void
    {
        $constraint = (new InstructorFilter())->constrain(FilterValues::fromInts([12, 47]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('instructor', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    /**
     * The attribute-guard TRAP called out on Filter::constrain()'s own
     * docblock: guarding on $values->isEmpty() rather than the CONVERTED
     * ids throws the moment garbage-only input arrives, because
     * AttributeInConstraint rejects an empty value list. Pins that
     * InstructorFilter uses the correct guard (toInts() === []).
     */
    public function test_garbage_values_do_not_throw(): void
    {
        $filter = new InstructorFilter();

        foreach (['0', '-1', 'abc', '<script>', "' OR 1=1"] as $garbage) {
            self::assertNull($filter->constrain(FilterValues::fromStrings([$garbage])));
        }
    }

    /**
     * End to end proof that `course_discovery/indexed_course` plus the
     * PUBLIC CourseIndexer::addAttributeValues() genuinely produces queryable
     * attribute rows for an attribute dimension the core plugin never named -- read
     * back through the same public repository API a real search uses.
     */
    public function test_indexing_a_course_writes_instructor_attribute_rows_via_the_public_api(): void
    {
        global $wpdb;

        $instructor = $this->makeInstructor();
        $courseId = $this->makeCourse([$instructor]);

        $schema = new Schema($wpdb);
        (new CourseIndexer($wpdb, $schema))->indexCourse($courseId);

        $repository = new WpCourseRepository($wpdb, $schema, new WhereClauseBuilder($wpdb, $schema));

        self::assertSame(
            [$instructor],
            $repository->attributeValues('instructor'),
            'The indexed_course listener must have written the instructor attribute row via addAttributeValues().'
        );
    }

    /**
     * The full pipeline: InstructorFilter::constrain() feeds a real
     * WpCourseRepository::search(), and only the course carrying the
     * selected instructor comes back.
     */
    public function test_the_filters_constraint_narrows_search_results_to_that_instructor(): void
    {
        global $wpdb;

        $ada = $this->makeInstructor('Ada Lovelace');
        $grace = $this->makeInstructor('Grace Hopper');

        $adaCourse = $this->makeCourse([$ada], 'Analytical Engines 101');
        $graceCourse = $this->makeCourse([$grace], 'COBOL Foundations');

        $schema = new Schema($wpdb);
        $indexer = new CourseIndexer($wpdb, $schema);
        $indexer->indexCourse($adaCourse);
        $indexer->indexCourse($graceCourse);

        $constraint = (new InstructorFilter())->constrain(FilterValues::fromInts([$ada]));
        self::assertNotNull($constraint);

        $repository = new WpCourseRepository($wpdb, $schema, new WhereClauseBuilder($wpdb, $schema));
        $result = $repository->search(ConstraintSet::of($constraint), Pagination::default(), SortOrder::Soonest);

        self::assertSame(1, $result->total, 'Only the course carrying the selected instructor must match.');

        $ids = [];

        foreach ($result->courses as $course) {
            $ids[] = $course->id->value;
        }

        self::assertSame([$adaCourse], $ids);
    }

    /**
     * Every other test in this class calls
     * course_discovery_example_bootstrap() DIRECTLY from setUp() -- a
     * documented test-isolation workaround, see setUp()'s own docblock --
     * which would still pass even if the real
     * add_action('plugins_loaded', 'course_discovery_example_bootstrap')
     * line at the bottom of the extension file were deleted. This is the
     * one test that pins the wiring itself: that requiring the file
     * actually registers the bootstrap function on 'plugins_loaded', so a
     * real WordPress request (where nothing calls the function directly)
     * genuinely fires it. See self::$pluginsLoadedHookState's own docblock
     * for why this is asserted from a value captured in setUpBeforeClass()
     * rather than recomputed here.
     */
    public function test_the_bootstrap_function_is_registered_on_plugins_loaded(): void
    {
        self::assertNotFalse(
            self::$pluginsLoadedHookState,
            "course_discovery_example_bootstrap() must be wired via add_action('plugins_loaded', ...) "
            . 'so it runs on a real request -- not only when a test calls it directly.'
        );
    }

    /**
     * The main plugin is genuinely active in this test environment
     * and cannot be un-loaded from the same PHP process once its classes
     * are declared, so the TRUE bail branch inside
     * course_discovery_example_bootstrap() cannot be exercised end-to-end
     * without faking global state. What this pins instead: the extracted
     * guard's boolean logic is correct for a class that genuinely does not
     * exist -- see course_discovery_example_main_plugin_missing()'s own
     * docblock for why it takes a parameter at all.
     */
    public function test_the_dependency_guard_reports_missing_for_a_nonexistent_class(): void
    {
        self::assertTrue(
            \course_discovery_example_main_plugin_missing('CourseDiscoveryExample\\NoSuchClassEver'),
            'The guard must report the main plugin missing for a class that genuinely does not exist.'
        );
    }

    /**
     * The other half: the guard's default argument is the real
     * dependency, and the main plugin IS active in this environment, so
     * this pins that the guard does NOT falsely report it missing --
     * exactly the condition every other test in this class already relies
     * on implicitly (the registry contains "instructor" at all).
     */
    public function test_the_dependency_guard_finds_the_real_main_plugin(): void
    {
        self::assertFalse(
            \course_discovery_example_main_plugin_missing(),
            'The main plugin is active in this test environment; the guard must not report it missing.'
        );
    }

    /**
     * The bail branch itself is unreachable here, so this pins the one part
     * of it that IS directly observable -- the admin notice it registers
     * renders escaped,
     * correctly classed markup when invoked, the same way WordPress would
     * invoke it from admin_notices if the bail branch really fired.
     */
    public function test_the_missing_dependency_notice_renders_escaped_admin_notice_markup(): void
    {
        ob_start();
        \course_discovery_example_missing_dependency_notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<div class="notice notice-error">', $html);
        self::assertStringContainsString(
            'Course Discovery Example Extension requires the Course Discovery plugin to be active.',
            $html
        );
    }

    /**
     * FilterRegistryTest pins that a filter reaches the registry;
     * FormRenderer's own tests pin that it renders whatever a registry
     * holds. Neither proves the two compose for THIS extension's filter
     * specifically -- it is currently only ever inferred. This builds a
     * registry the same way FilterRegistryTest does (register() called
     * directly, no do_action() involved) and renders it through the exact
     * same FormRenderer::render($registry, $criteria) call
     * Shortcode::render() makes, so the instructor fieldset genuinely
     * reaching the page is asserted directly, not inferred.
     */
    public function test_the_instructor_filter_renders_into_the_search_form(): void
    {
        $ada = $this->makeInstructor('Ada Lovelace');

        $registry = new FilterRegistry();
        $registry->register(new InstructorFilter());

        $html = (new FormRenderer())->render($registry, SearchCriteria::empty());

        self::assertStringContainsString('<fieldset class="cd-filter cd-filter-instructor">', $html);
        self::assertStringContainsString('<legend>Instructor</legend>', $html);
        self::assertStringContainsString('name="instructor[]"', $html);
        self::assertStringContainsString('value="' . $ada . '"', $html);
        self::assertStringContainsString('Ada Lovelace', $html);
    }

    /**
     * Documents the extension's self-cleaning guarantee for the
     * eventual README -- deactivating the extension does not itself delete
     * anything; its course_discovery/indexed_course listener simply stops
     * firing. But CourseIndexer::replaceAttributes() (which indexCourse()
     * always calls) blanket-deletes EVERY attribute row for a course before
     * reinserting only the built-in ones (provider/location/category/
     * start) -- so the very next reindex after deactivation purges the
     * stale "instructor" rows automatically, with no cleanup code anywhere
     * in either plugin. remove_all_actions() stands in for "the extension
     * is deactivated": it is the only way to stop the listener firing
     * without un-loading the extension's class the rest of this suite
     * still depends on, and this class's own setUp() re-registers it
     * before every test regardless, so nothing leaks to later tests.
     */
    public function test_deactivating_the_extension_then_reindexing_purges_stale_instructor_rows(): void
    {
        global $wpdb;

        $instructor = $this->makeInstructor();
        $courseId = $this->makeCourse([$instructor]);

        $schema = new Schema($wpdb);
        $indexer = new CourseIndexer($wpdb, $schema);
        $repository = new WpCourseRepository($wpdb, $schema, new WhereClauseBuilder($wpdb, $schema));

        // With the extension's listener active (this class's setUp() wires
        // it before every test).
        $indexer->indexCourse($courseId);

        self::assertSame(
            [$instructor],
            $repository->attributeValues('instructor'),
            'Indexing with the extension active must write instructor attribute rows.'
        );

        // Simulates deactivation: the listener that writes 'instructor'
        // attribute rows stops firing.
        remove_all_actions('course_discovery/indexed_course');

        // The next reindex after deactivation -- e.g. `wp course-discovery
        // reindex` -- with no listener present.
        $indexer->indexCourse($courseId);

        self::assertSame(
            [],
            $repository->attributeValues('instructor'),
            "CourseIndexer::replaceAttributes() blanket-deletes a course's attribute rows before reinserting the "
            . 'built-in ones -- stale instructor rows must not survive a reindex once the extension is gone.'
        );
    }
}
