<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Index;

use CourseDiscovery\Index\MigrationRunner;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class MigrationRunnerTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);

        // Bring the tables to a known-good baseline first -- a prior test
        // class may have left them in any state -- then tear that baseline
        // straight back down: this class specifically exercises migrating
        // from scratch, so every test here wants neither table nor the
        // version option to exist yet.
        $this->prepareIndexTables();
        $wpdb->query('DROP TABLE IF EXISTS ' . $this->schema->attributeLookupTable());
        $wpdb->query('DROP TABLE IF EXISTS ' . $this->schema->metaLookupTable());
        delete_option(MigrationRunner::OPTION);
    }

    protected function tearDown(): void
    {
        // Not every test in this class re-runs the migration (see
        // test_table_names_respect_the_configured_prefix), so without this
        // a test could hand off to the next test class with the tables
        // dropped and the version option stale -- exactly the state a
        // class that only calls prepareIndexTables() (which assumes the
        // tables already exist and just truncates them) cannot recover
        // from. Restoring here guarantees a working baseline regardless of
        // what the test body itself did.
        $this->prepareIndexTables();

        parent::tearDown();
    }

    public function test_it_creates_both_tables_from_scratch(): void
    {
        global $wpdb;

        $applied = (new MigrationRunner($wpdb, $this->schema))->run();

        self::assertSame([1, 2, 3], $applied);
        self::assertSame(
            $this->schema->metaLookupTable(),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->schema->metaLookupTable()))
        );
        self::assertSame(
            $this->schema->attributeLookupTable(),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->schema->attributeLookupTable()))
        );
    }

    public function test_it_records_the_applied_version(): void
    {
        global $wpdb;

        $runner = new MigrationRunner($wpdb, $this->schema);
        $runner->run();

        self::assertSame(3, $runner->currentVersion());
    }

    public function test_running_twice_applies_nothing_the_second_time(): void
    {
        global $wpdb;

        (new MigrationRunner($wpdb, $this->schema))->run();

        // A fresh instance, not the one that just ran: this proves the
        // no-op comes from the persisted version option, not from
        // in-memory state on the first runner.
        $second = new MigrationRunner($wpdb, $this->schema);

        self::assertSame([], $second->run(), 'A second run must be a no-op.');
    }

    public function test_table_names_respect_the_configured_prefix(): void
    {
        global $wpdb;

        self::assertStringStartsWith($wpdb->prefix, $this->schema->metaLookupTable());
        self::assertStringStartsWith($wpdb->prefix, $this->schema->attributeLookupTable());
    }

    /**
     * A site whose plugin was activated before a later migration
     * shipped previously kept the old schema forever -- run() was only ever
     * called from register_activation_hook. Plugin::boot() now also
     * registers MigrationRunner::runIfPending() on `admin_init`; this test
     * locates that REAL globally-registered instance (see
     * registeredMigrationRunner()) and invokes it directly, to prove the
     * wiring actually works end to end rather than just that
     * runIfPending() is correct in isolation.
     *
     * do_action('admin_init') itself is deliberately NOT used here: it also
     * fires WordPress core's own admin_init-hooked functions (e.g.
     * wp_add_privacy_policy_content()), which assume a genuine wp-admin
     * request and trip WP_UnitTestCase's _doing_it_wrong assertions when
     * fired standalone like this.
     *
     * The schema is rolled back, not just the recorded version: dropping the
     * title column and setting the option back to 2 recreates exactly what
     * a site activated before M003 shipped looks like, so this test can
     * tell "the hook re-ran the pending migration" from "the schema was
     * already fine and nobody looked".
     */
    public function test_a_site_recorded_at_an_older_version_is_migrated_via_the_admin_init_hook(): void
    {
        global $wpdb;

        (new MigrationRunner($wpdb, $this->schema))->run();

        $wpdb->query('ALTER TABLE ' . $this->schema->metaLookupTable() . ' DROP COLUMN title');
        update_option(MigrationRunner::OPTION, 2, false);

        self::registeredMigrationRunner()->runIfPending();

        self::assertSame(3, (new MigrationRunner($wpdb, $this->schema))->currentVersion());

        $column = $wpdb->get_row(
            $wpdb->prepare('SHOW COLUMNS FROM ' . $this->schema->metaLookupTable() . ' LIKE %s', 'title')
        );

        self::assertNotNull(
            $column,
            'admin_init must have reapplied the pending migration and recreated the title column.'
        );
    }

    /**
     * Walks the global $wp_filter registry to find the actual MigrationRunner
     * instance Plugin::boot() registered on `admin_init` -- mirrors
     * PluginTest::hasCallback()'s approach (has_action() cannot match an
     * object callback unless the caller holds that exact instance), but
     * returns the instance itself rather than a bool, since this test needs
     * to call a method on it.
     */
    private static function registeredMigrationRunner(): MigrationRunner
    {
        global $wp_filter;

        self::assertIsArray($wp_filter);
        self::assertArrayHasKey('admin_init', $wp_filter, 'No callbacks are registered at all for admin_init.');

        $hookObject = $wp_filter['admin_init'];

        self::assertIsObject($hookObject);
        self::assertTrue(property_exists($hookObject, 'callbacks'));

        /** @var mixed $callbacks */
        $callbacks = $hookObject->callbacks;

        self::assertIsArray($callbacks);

        foreach ($callbacks as $callbacksAtPriority) {
            if (! is_array($callbacksAtPriority)) {
                continue;
            }

            foreach ($callbacksAtPriority as $registration) {
                if (! is_array($registration) || ! isset($registration['function'])) {
                    continue;
                }

                /** @var mixed $function */
                $function = $registration['function'];

                if (! is_array($function) || count($function) !== 2) {
                    continue;
                }

                [$target, $method] = $function;

                if ($target instanceof MigrationRunner && $method === 'runIfPending') {
                    return $target;
                }
            }
        }

        self::fail('MigrationRunner::runIfPending is not registered on admin_init.');
    }

    public function test_running_if_pending_is_a_no_op_when_already_at_the_latest_version(): void
    {
        global $wpdb;

        $runner = new MigrationRunner($wpdb, $this->schema);
        $applied = $runner->run();

        self::assertNotSame([], $applied, 'Sanity check: the baseline run must have applied something.');

        // A second runner, at the version the first one just recorded.
        $second = new MigrationRunner($wpdb, $this->schema);
        $second->runIfPending();

        self::assertSame(3, $second->currentVersion(), 'Must remain at the latest version, unchanged.');
    }

    /**
     * MigrationRunner::execute()'s throw-on-failure
     * ($db->query() === false) is the guarantee the whole migration design
     * rests on -- delete that check and every OTHER test in this suite
     * still passes, because nothing previously drove a real migration
     * statement to fail. This does, using a real migration (not a fake
     * one): after applying only M001, the attribute table is dropped out from
     * under the schema, so M002's `ALTER TABLE ... MODIFY value_id ...`
     * targets a table that no longer exists and fails for real.
     *
     * Without the === false check, $wpdb->query()'s failure would be
     * silent: run() would proceed straight to update_option() and record
     * version 2 as applied despite the schema change never having landed.
     */
    public function test_a_failing_migration_statement_throws_and_does_not_advance_the_recorded_version(): void
    {
        global $wpdb;

        // Baseline: M001 only, so the attribute table exists but the recorded
        // version is 1 -- exactly what M002's ALTER TABLE needs in order to
        // have something to fail against once that table is gone.
        (new MigrationRunner($wpdb, $this->schema))->run();
        $wpdb->query('DROP TABLE ' . $this->schema->attributeLookupTable());
        update_option(MigrationRunner::OPTION, 1, false);

        $runner = new MigrationRunner($wpdb, $this->schema);

        $threw = false;
        $versionAfterFailure = null;

        // The failure below is deliberately provoked, so the resulting
        // wpdb error is expected noise, not a real problem to surface.
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $runner->run();
        } catch (\RuntimeException $e) {
            $threw = true;
        } finally {
            $wpdb->suppress_errors($suppressed);
            $versionAfterFailure = $runner->currentVersion();

            // Restore a working baseline regardless of outcome, BEFORE the
            // assertions below (which could themselves throw): tearDown()'s
            // own prepareIndexTables() call assumes both tables already
            // exist, which is not true here until this runs. delete_option()
            // resets the recorded version to 0 so a fresh run() reapplies
            // every migration from scratch, recreating the attribute table via
            // M001's CREATE TABLE IF NOT EXISTS.
            delete_option(MigrationRunner::OPTION);
            (new MigrationRunner($wpdb, $this->schema))->run();
        }

        self::assertTrue($threw, 'A migration statement that fails must throw a RuntimeException.');
        self::assertSame(
            1,
            $versionAfterFailure,
            'A failed migration must not advance the recorded schema version.'
        );
    }

    public function test_the_attribute_table_rejects_duplicate_rows(): void
    {
        global $wpdb;

        (new MigrationRunner($wpdb, $this->schema))->run();

        $table = $this->schema->attributeLookupTable();
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (course_id, attribute, value_id) VALUES (%d, %s, %d)", 1, 'provider', 12));
        $suppressed = $wpdb->suppress_errors(true);
        $second = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (course_id, attribute, value_id) VALUES (%d, %s, %d)", 1, 'provider', 12));
        $wpdb->suppress_errors($suppressed);

        self::assertFalse($second, 'The composite primary key must prevent duplicates.');
    }
}
