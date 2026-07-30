<?php

declare(strict_types=1);

namespace CourseDiscovery;

/**
 * Composition root. Wires subsystems to WordPress hooks.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    /**
     * WordPress text domain for every translatable string this plugin
     * registers (post type/taxonomy labels, admin UI copy). Must match the
     * "Text Domain" header in course-discovery.php and the directory passed
     * to load_plugin_textdomain() below.
     */
    public const TEXT_DOMAIN = 'course-discovery';

    /**
     * The command name run as `wp course-discovery reindex`. Named once here
     * rather than retyped at the WP_CLI::add_command() call site, so a typo
     * cannot diverge the two copies. See registerCliCommand().
     */
    public const CLI_COMMAND_NAME = 'course-discovery reindex';

    private static ?Container $container = null;

    /**
     * Guards boot() against a second call. Every add_action()/add_filter() in
     * boot() is unconditional, so a second call would double-register every
     * hook; this makes idempotency structural rather than relying on there
     * only ever being one caller.
     */
    private static bool $booted = false;

    /**
     * @param string $pluginFile Absolute path to the plugin entry file
     *                           (course-discovery.php's own __FILE__).
     *                           WordPress loads that file from WP_PLUGIN_DIR
     *                           in every environment, so it is the only path
     *                           plugins_url()/plugin_basename() can reliably
     *                           resolve. A `src/` class's __DIR__ is NOT a
     *                           safe substitute: Composer autoloads classes
     *                           from a different absolute path than the plugin
     *                           copy WordPress scans, so deriving a URL/path
     *                           from __DIR__ silently points outside
     *                           WP_PLUGIN_DIR (the bug behind Assets.php's
     *                           404'd CSS/JS, caught by the E2E suite).
     */
    public static function boot(string $pluginFile): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $container = self::container();

        add_action('init', static function () use ($pluginFile): void {
            load_plugin_textdomain(
                self::TEXT_DOMAIN,
                false,
                self::languagesPath($pluginFile)
            );
        }, 0);

        // Post types first, so anything attaching to them has a stable order.
        add_action('init', [new ContentModel\PostTypes(), 'register'], 0);
        add_action('init', [new ContentModel\Taxonomies(), 'register']);

        $startDates = new ContentModel\StartDatesMetaBox();
        add_action('add_meta_boxes', [$startDates, 'register']);
        add_action('save_post_' . ContentModel\PostTypes::COURSE, [$startDates, 'save']);

        add_action('acf/init', [new ContentModel\AcfFields(), 'register']);
        add_action('admin_init', [new ContentModel\AdminColumns(), 'register']);

        // IndexInvalidator listens on wp_after_insert_post, not
        // save_post_cd_course -- it fires after every save_post_* action,
        // so it always sees data ACF and StartDatesMetaBox::save() (above)
        // have already written. No priority coordination is needed here;
        // see IndexInvalidator::register() for the full reasoning.
        (new Index\IndexInvalidator($container->get(Index\CourseIndexer::class)))->register();

        // Deferred to 'init': resolving SearchService eagerly builds every
        // core Filter, whose constructors translate labels via __(), and the
        // text domain only loads on 'init' priority 0 (above). Resolving
        // earlier would ship untranslated labels.
        add_action('init', static function () use ($container): void {
            (new Frontend\Shortcode(
                $container->get(Search\SearchService::class),
                new Frontend\FormRenderer(new Frontend\SearchUrls()),
                new Frontend\ResultsRenderer(new Frontend\AttributeLabels()),
                new Frontend\ActiveFiltersRenderer(new Frontend\SearchUrls()),
            ))->register();
        });

        // Enqueues only on pages that render the shortcode (see
        // Assets::maybeEnqueue()). $pluginFile is what lets Assets resolve
        // plugins_url() correctly -- a `src/` __DIR__ would not (see boot()).
        (new Frontend\Assets($pluginFile))->register();

        // Also run on every admin load, not just activation: a site activated
        // before a later migration shipped would otherwise keep the old
        // schema forever. runIfPending() is a cheap version comparison on the
        // common (up-to-date) path -- see MigrationRunner::runIfPending().
        add_action('admin_init', [$container->get(Index\MigrationRunner::class), 'runIfPending']);

        // Registered only under WP-CLI: WP_CLI::add_command() does not
        // exist during a normal web request, and there is no reason to
        // pay for the class load or registration outside a CLI process.
        if (defined('WP_CLI') && constant('WP_CLI')) {
            self::registerCliCommand($container->get(Index\CourseIndexer::class));
        }
    }

    /**
     * The WP_PLUGIN_DIR-relative languages directory for
     * load_plugin_textdomain() -- e.g. "course-discovery/languages".
     *
     * MUST derive from $pluginFile, never this class's __DIR__ (see boot()):
     * a `src/`-relative path resolves outside WP_PLUGIN_DIR, so
     * plugin_basename() cannot rewrite it and load_plugin_textdomain()
     * silently finds no .mo file -- translations load without error but never
     * take effect. Split out so the computation can be pinned by a test.
     */
    public static function languagesPath(string $pluginFile): string
    {
        return dirname(plugin_basename($pluginFile)) . '/languages';
    }

    public static function container(): Container
    {
        if (self::$container instanceof Container) {
            return self::$container;
        }

        $container = new Container();

        $container->set(Index\Schema::class, static function (): Index\Schema {
            global $wpdb;

            return new Index\Schema($wpdb);
        });

        $container->set(Index\CourseIndexer::class, static function (Container $c): Index\CourseIndexer {
            global $wpdb;

            return new Index\CourseIndexer($wpdb, $c->get(Index\Schema::class));
        });

        $container->set(Index\MigrationRunner::class, static function (Container $c): Index\MigrationRunner {
            global $wpdb;

            return new Index\MigrationRunner($wpdb, $c->get(Index\Schema::class));
        });

        $container->set(Query\WhereClauseBuilder::class, static function (Container $c): Query\WhereClauseBuilder {
            global $wpdb;

            return new Query\WhereClauseBuilder($wpdb, $c->get(Index\Schema::class));
        });

        $container->set(Query\WpCourseRepository::class, static function (Container $c): Query\WpCourseRepository {
            global $wpdb;

            return new Query\WpCourseRepository(
                $wpdb,
                $c->get(Index\Schema::class),
                $c->get(Query\WhereClauseBuilder::class)
            );
        });

        // One shared FilterRegistry per request (so course_discovery/
        // register_filters fires exactly once), with the core filters
        // registered before that hook fires -- a third party listening on it
        // sees them all already present. Lazy like every factory here: it
        // must not resolve before 'init', because building a Filter
        // translates its label via __() and the text domain only loads on
        // 'init' priority 0. Do not add an eager get() of this in boot().
        $container->set(Filter\FilterRegistry::class, static fn (Container $c): Filter\FilterRegistry
            => Filter\FilterRegistry::boot(
                new Filter\KeywordFilter(),
                new Filter\ProviderFilter(),
                new Filter\LocationFilter(),
                new Filter\StartDateFilter($c->get(Query\WpCourseRepository::class)),
                new Filter\CategoryFilter(),
            ));

        $container->set(Search\SearchService::class, static fn (Container $c): Search\SearchService
            => new Search\SearchService(
                $c->get(Filter\FilterRegistry::class),
                $c->get(Query\WpCourseRepository::class)
            ));

        self::$container = $container;

        return $container;
    }

    /**
     * Discards the process-wide container so the next call to container()
     * builds a fresh service graph from scratch.
     *
     * FOR TEST ISOLATION ONLY -- never call from production code. The
     * integration suite runs every test in one PHP process, so without this
     * a stub registered via Container::set() in one test would leak into
     * every later test. Called from IntegrationTestCase::tearDown().
     */
    public static function resetContainer(): void
    {
        self::$container = null;
    }

    /**
     * Split out from boot() so the registration can be exercised under a test
     * stand-in for WP_CLI: the real `wp` process is unavailable in the
     * integration environment, so boot()'s `defined('WP_CLI')` guard is never
     * true there and a typo in the command name would otherwise ship green.
     */
    public static function registerCliCommand(Index\CourseIndexer $indexer): void
    {
        \WP_CLI::add_command(self::CLI_COMMAND_NAME, new Index\ReindexCommand($indexer));
    }
}
