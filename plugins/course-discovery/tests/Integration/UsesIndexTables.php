<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

use CourseDiscovery\Index\MigrationRunner;
use CourseDiscovery\Index\Schema;

/**
 * Gives an integration test class access to the plugin's real (non-temporary)
 * index tables.
 *
 * wp-phpunit wraps every test in a transaction and, for isolation, rewrites
 * any query starting with CREATE/DROP TABLE into CREATE/DROP TEMPORARY TABLE
 * (WP_UnitTestCase::_create_temporary_tables() / _drop_temporary_tables(),
 * registered as `query` filters in every set_up() via start_transaction()).
 * MariaDB's InnoDB engine forbids a FULLTEXT index on a temporary table, and
 * this plugin's own migration (M001CreateLookupTables) relies on exactly that
 * index on cd_course_meta_lookup.search_text -- so under the unmodified harness the
 * migration's CREATE TABLE fails for every test that runs it.
 *
 * The fix is WordPress core's own documented escape hatch: remove the two
 * filters for the duration of a test class, scoped only to the classes that
 * actually need real DDL. This is deliberately a per-class trait rather than
 * a global bootstrap filter:
 *
 * - A global filter matched by table-name substring but rewrote by keyword
 *   (stripping any "TEMPORARY" token near those table names). A legitimate
 *   future `CREATE TEMPORARY TABLE tmp AS SELECT ... FROM
 *   {$wpdb->prefix}cd_course_meta_lookup` would have its TEMPORARY silently
 *   stripped and create a real table instead of a scratch one.
 * - It ran for every integration test, whether or not that test touched
 *   these tables.
 * - DDL causes an implicit COMMIT in MariaDB, so its effects leaked: tables
 *   created under it persisted across unrelated test classes and runs, and
 *   DML issued earlier in the same "mini transaction" (e.g. a delete_option()
 *   call right before a CREATE TABLE) got permanently committed instead of
 *   rolled back at tear_down().
 *
 * Do not "simplify" this back into a bootstrap-global filter -- the FULLTEXT-
 * on-temporary-table restriction is exactly why this exists, and the
 * consequences above are why it must stay scoped per class.
 */
trait UsesIndexTables
{
    /**
     * Removes wp-phpunit's temporary-table rewrite for this test, runs the
     * schema migrations so both index tables exist, and truncates them so
     * each test starts from a known-empty state.
     */
    protected function prepareIndexTables(): void
    {
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        global $wpdb;

        $schema = new Schema($wpdb);
        (new MigrationRunner($wpdb, $schema))->run();

        $wpdb->query('TRUNCATE TABLE ' . $schema->metaLookupTable());
        $wpdb->query('TRUNCATE TABLE ' . $schema->attributeLookupTable());
    }
}
