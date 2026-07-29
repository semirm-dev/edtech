<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\AdminColumns;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDatesMetaBox;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\IndexInvalidator;
use CourseDiscovery\Index\ReindexCommand;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Plugin;
use CourseDiscovery\Tests\Integration\Support\WpCliCommandRecorder;

// WP_CLI is unavailable in the integration test environment (see
// Support/WpCliStub.php's docblock); required here, not autoloaded, since a
// global-namespace class cannot be found via this file's PSR-4 mapping.
require_once __DIR__ . '/Support/WpCliStub.php';

final class PluginTest extends IntegrationTestCase
{
    /**
     * Records the stub instance test_container_a_... registers, so
     * test_container_b_... (declared immediately after it) can assert it
     * did not survive the tearDown() between them.
     */
    private static ?Schema $leakCanary = null;

    public function test_it_registers_post_types_on_init_at_priority_zero(): void
    {
        self::assertTrue(
            self::hasCallback('init', PostTypes::class, 'register', 0),
            'Expected PostTypes::register to be registered on "init" at priority 0.'
        );
    }

    public function test_it_registers_taxonomies_on_init(): void
    {
        self::assertTrue(
            self::hasCallback('init', Taxonomies::class, 'register'),
            'Expected Taxonomies::register to be registered on "init".'
        );
    }

    public function test_it_registers_start_dates_meta_box(): void
    {
        self::assertTrue(
            self::hasCallback('add_meta_boxes', StartDatesMetaBox::class, 'register'),
            'Expected StartDatesMetaBox::register to be registered on "add_meta_boxes".'
        );
    }

    public function test_it_registers_start_dates_save(): void
    {
        self::assertTrue(
            self::hasCallback('save_post_' . PostTypes::COURSE, StartDatesMetaBox::class, 'save'),
            sprintf('Expected StartDatesMetaBox::save to be registered on "save_post_%s".', PostTypes::COURSE)
        );
    }

    /**
     * StartDatesMetaBox::save() runs on save_post_cd_course (priority 10),
     * and ACF writes its own fields on the GENERIC save_post action -- both
     * of which fire strictly before wp_after_insert_post. Listening there,
     * rather than on any save_post_* action, is what guarantees the indexer
     * always sees data those two have already written, with no priority
     * coordination needed.
     */
    public function test_it_reindexes_courses_after_start_dates_and_acf_are_saved(): void
    {
        self::assertTrue(
            self::hasCallback('wp_after_insert_post', IndexInvalidator::class, 'onCourseSaved'),
            'Expected IndexInvalidator::onCourseSaved to be registered on "wp_after_insert_post".'
        );
    }

    public function test_it_registers_acf_fields(): void
    {
        self::assertTrue(
            self::hasCallback('acf/init', AcfFields::class, 'register'),
            'Expected AcfFields::register to be registered on "acf/init".'
        );
    }

    public function test_it_registers_admin_columns(): void
    {
        self::assertTrue(
            self::hasCallback('admin_init', AdminColumns::class, 'register'),
            'Expected AdminColumns::register to be registered on "admin_init".'
        );
    }

    public function test_it_exposes_a_semver_version(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', \CourseDiscovery\Plugin::VERSION);
    }

    public function test_it_declares_a_text_domain(): void
    {
        self::assertSame('course-discovery', \CourseDiscovery\Plugin::TEXT_DOMAIN);
    }

    /**
     * The asset-URL fix (see AssetsTest::test_the_enqueued_urls_are_well_
     * formed) also fixed a second, previously untested bug: boot()'s
     * load_plugin_textdomain() call used to derive its languages directory
     * from Plugin's own `src/`-relative __DIR__ instead of the entry file --
     * see languagesPath()'s own docblock for why that resolves outside
     * WP_PLUGIN_DIR. That failure mode is silent: load_plugin_textdomain()
     * just never finds a .mo file and returns false, so nothing before this
     * test caught it. Pins the fix directly: the computed path must be
     * WP_PLUGIN_DIR-relative ("course-discovery/languages"), not an
     * absolute filesystem path built from this class's own location.
     */
    public function test_languages_path_is_plugin_relative_not_absolute(): void
    {
        $pluginFile = realpath(WP_PLUGIN_DIR . '/course-discovery/course-discovery.php');
        self::assertIsString($pluginFile, 'Could not resolve course-discovery.php.');

        $path = Plugin::languagesPath($pluginFile);

        self::assertSame(
            'course-discovery/languages',
            $path,
            'Expected the languages path to be WP_PLUGIN_DIR-relative, e.g. "course-discovery/languages".'
        );

        self::assertStringNotContainsString(
            'src',
            $path,
            'The languages path must not carry the `src/` directory a `src/`-relative __DIR__ would leak in.'
        );

        self::assertDoesNotMatchRegularExpression(
            '#^/#',
            $path,
            sprintf('Expected a WP_PLUGIN_DIR-relative path, got what looks like an absolute path: "%s".', $path)
        );
    }

    /**
     * boot() has exactly one production caller (course-discovery.php's own
     * entry-file `require`, itself loaded once per request by WordPress),
     * but every add_action() inside it is unconditional -- nothing stopped
     * a second call from double-registering every hook boot() contains.
     * Plugin::$booted (see its own docblock) guards against that. The real,
     * unguarded FIRST call already happened at process bootstrap (see
     * tests/bootstrap-integration.php's muplugins_loaded filter, which
     * requires the entry file and so calls Plugin::boot() itself) -- this
     * test's own call is therefore already the SECOND call for the whole
     * process, and it asserts that call added nothing further.
     */
    public function test_a_second_boot_call_does_not_double_register(): void
    {
        $countBefore = self::countCallbacks('acf/init', AcfFields::class, 'register');
        self::assertSame(
            1,
            $countBefore,
            'Precondition failed: expected exactly one AcfFields::register callback before re-booting.'
        );

        $pluginFile = realpath(WP_PLUGIN_DIR . '/course-discovery/course-discovery.php');
        self::assertIsString($pluginFile, 'Could not resolve course-discovery.php.');

        Plugin::boot($pluginFile);

        self::assertSame(
            1,
            self::countCallbacks('acf/init', AcfFields::class, 'register'),
            'A second Plugin::boot() call must not double-register hooks -- the AcfFields::register '
                . 'callback count on "acf/init" must stay at 1.'
        );
    }

    /**
     * The original version of this test only asserted that the
     * literal 'Courses' passed through __() -- despite its "every label"
     * name, a brand-new unwrapped label added anywhere else in PostTypes or
     * Taxonomies would ship green. Strengthened to (a) exercise BOTH
     * PostTypes AND Taxonomies, (b) assert several distinct known labels by
     * name so a renamed/dropped __() call is caught specifically, and
     * (c) assert a floor on the total number of translated strings seen, so
     * removing a wrap from a label this test does not name individually is
     * still caught.
     */
    public function test_every_registered_label_is_translated(): void
    {
        $courseType = get_post_type_object(\CourseDiscovery\ContentModel\PostTypes::COURSE);

        self::assertNotNull($courseType);

        // A translated string round-trips through the l10n layer even with no
        // .mo file loaded; an untranslated literal never reaches it. Swapping
        // the filter proves the call site actually uses __().
        $seen = [];

        add_filter('gettext', static function (string $translated, string $original, string $domain) use (&$seen): string {
            if ($domain === \CourseDiscovery\Plugin::TEXT_DOMAIN) {
                $seen[] = $original;
            }

            return $translated;
        }, 10, 3);

        (new \CourseDiscovery\ContentModel\PostTypes())->register();
        (new \CourseDiscovery\ContentModel\Taxonomies())->register();

        foreach ([
            'Course', 'Courses', 'Instructor', 'Instructors', 'Provider', 'Providers',
            'Course Categories', 'Course Category', 'Locations', 'Location',
        ] as $expectedLabel) {
            self::assertContains(
                $expectedLabel,
                $seen,
                sprintf('Expected "%s" to be passed through __().', $expectedLabel)
            );
        }

        // Exact count as of this writing: 3 post types x (2 singular/plural
        // + 4 sprintf(__(...)) label templates) = 18, plus 2 taxonomies x 2
        // = 4, totalling 22. Asserted as a floor (>=), not an equality, so
        // legitimately adding more translated labels later never needs this
        // test touched -- but dropping a __() wrap from any existing label
        // reduces the count below 22 and fails loudly here, even though
        // that label isn't named individually above.
        self::assertGreaterThanOrEqual(
            22,
            count($seen),
            'Expected at least 22 distinct __() calls from registering post types and taxonomies; '
                . 'a label may have been left unwrapped.'
        );
    }

    public function test_the_container_returns_the_same_instance_each_time(): void
    {
        $container = \CourseDiscovery\Plugin::container();

        self::assertSame(
            $container->get(\CourseDiscovery\Index\Schema::class),
            $container->get(\CourseDiscovery\Index\Schema::class)
        );
    }

    public function test_the_container_rejects_an_unregistered_service(): void
    {
        $this->expectException(\RuntimeException::class);

        \CourseDiscovery\Plugin::container()->get(\stdClass::class);
    }

    /**
     * Pair, part 1 of 2: registers a stub in place of the real
     * Schema service and confirms it takes effect. Relies on PHPUnit's
     * default declaration-order test execution (not configured to randomise
     * in phpunit-integration.xml.dist) so that the sibling test directly
     * below runs immediately afterwards, with a real tearDown() in between
     * -- see that test's docblock for what it proves.
     */
    public function test_container_a_a_stub_registered_here_takes_effect_immediately(): void
    {
        global $wpdb;

        $override = new Schema($wpdb);
        Plugin::container()->set(Schema::class, static fn (): Schema => $override);

        self::assertSame(
            $override,
            Plugin::container()->get(Schema::class),
            'Sanity check: overriding a service via Container::set() must take effect within the same test.'
        );

        self::$leakCanary = $override;
    }

    /**
     * Pair, part 2 of 2: without Plugin::resetContainer() wired
     * into IntegrationTestCase::tearDown(), Plugin::$container is a static,
     * process-wide singleton that outlives any single test, so the stub the
     * previous test registered would still be sitting there and this
     * assertion would fail. Passing proves the reset runs between tests.
     */
    public function test_container_b_the_previous_tests_stub_does_not_leak_into_this_test(): void
    {
        self::assertNotNull(
            self::$leakCanary,
            'Precondition failed: test_container_a_... must run before this test and record its stub.'
        );

        $resolved = Plugin::container()->get(Schema::class);

        self::assertNotSame(
            self::$leakCanary,
            $resolved,
            'A Container::set() override from a previous test leaked into this one -- '
                . 'Plugin::resetContainer() must run in IntegrationTestCase::tearDown().'
        );
    }

    /**
     * Nothing previously tested that the WP-CLI command is
     * registered under the right name -- a typo in 'course-discovery
     * reindex' would ship green, since boot()'s own
     * `defined('WP_CLI') && WP_CLI` guard is never true in this test
     * environment (the real `wp` process is unavailable) and so the
     * registration call never ran at all under test. This exercises the
     * real registration call directly, under the WP_CLI stand-in required
     * at the top of this file, and pins the exact command name.
     */
    public function test_the_reindex_wp_cli_command_is_registered_under_the_right_name(): void
    {
        global $wpdb;

        $before = count(WpCliCommandRecorder::$commands);

        $schema = new Schema($wpdb);
        $indexer = new CourseIndexer($wpdb, $schema);

        Plugin::registerCliCommand($indexer);

        $registered = WpCliCommandRecorder::$commands;

        self::assertCount($before + 1, $registered, 'Expected exactly one additional WP-CLI command to be registered.');

        [$name, $callback] = $registered[count($registered) - 1];

        self::assertSame('course-discovery reindex', $name);
        self::assertSame(Plugin::CLI_COMMAND_NAME, $name);
        self::assertInstanceOf(ReindexCommand::class, $callback);
    }

    /**
     * Walks the global $wp_filter registry directly, since has_action()
     * cannot match an object callback ([$instance, 'method']) unless the
     * caller holds the exact same instance that Plugin::boot() created.
     *
     * Asserting on $wp_filter also means a deleted add_action() call in
     * Plugin::boot() makes this test fail for the right reason, rather than
     * silently passing because WordPress core happens to use the same hook.
     *
     * @param class-string $expectedClass
     */
    private static function hasCallback(
        string $hook,
        string $expectedClass,
        string $expectedMethod,
        ?int $expectedPriority = null
    ): bool {
        global $wp_filter;

        if (! is_array($wp_filter) || ! isset($wp_filter[$hook])) {
            self::fail(sprintf('No callbacks are registered at all for hook "%s".', $hook));
        }

        $hookObject = $wp_filter[$hook];

        if (! is_object($hookObject) || ! property_exists($hookObject, 'callbacks')) {
            self::fail(sprintf('Hook "%s" is not a valid WP_Hook instance.', $hook));
        }

        /** @var mixed $callbacks */
        $callbacks = $hookObject->callbacks;

        if (! is_array($callbacks)) {
            self::fail(sprintf('Callbacks for hook "%s" have an unexpected shape.', $hook));
        }

        foreach ($callbacks as $priority => $callbacksAtPriority) {
            if (! is_array($callbacksAtPriority)) {
                continue;
            }

            foreach ($callbacksAtPriority as $registration) {
                if (! is_array($registration) || ! isset($registration['function'])) {
                    continue;
                }

                $function = $registration['function'];

                if (! is_array($function) || count($function) !== 2) {
                    continue;
                }

                [$target, $method] = $function;

                if (! is_object($target) || ! ($target instanceof $expectedClass)) {
                    continue;
                }

                if ($method !== $expectedMethod) {
                    continue;
                }

                if (null !== $expectedPriority && $priority !== $expectedPriority) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Counts registrations of [instance of $expectedClass, $expectedMethod]
     * across every priority on $hook, the same way hasCallback() above
     * matches by class/method rather than instance identity (WP_CLI's
     * add_action() callbacks are fresh objects each time boot() runs, so
     * two different instances of the same class/method pair are still the
     * "same" registration for this purpose). Used by
     * test_a_second_boot_call_does_not_double_register to prove a repeat
     * boot() call adds no further callback, rather than merely checking
     * "at least one" the way hasCallback()'s boolean result would.
     *
     * @param class-string $expectedClass
     */
    private static function countCallbacks(string $hook, string $expectedClass, string $expectedMethod): int
    {
        global $wp_filter;

        if (! is_array($wp_filter) || ! isset($wp_filter[$hook])) {
            return 0;
        }

        $hookObject = $wp_filter[$hook];

        if (! is_object($hookObject) || ! property_exists($hookObject, 'callbacks')) {
            return 0;
        }

        /** @var mixed $callbacks */
        $callbacks = $hookObject->callbacks;

        if (! is_array($callbacks)) {
            return 0;
        }

        $count = 0;

        foreach ($callbacks as $callbacksAtPriority) {
            if (! is_array($callbacksAtPriority)) {
                continue;
            }

            foreach ($callbacksAtPriority as $registration) {
                if (! is_array($registration) || ! isset($registration['function'])) {
                    continue;
                }

                $function = $registration['function'];

                if (! is_array($function) || count($function) !== 2) {
                    continue;
                }

                [$target, $method] = $function;

                if (! is_object($target) || ! ($target instanceof $expectedClass)) {
                    continue;
                }

                if ($method !== $expectedMethod) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }
}
