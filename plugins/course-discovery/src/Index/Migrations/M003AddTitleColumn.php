<?php

declare(strict_types=1);

namespace CourseDiscovery\Index\Migrations;

use CourseDiscovery\Index\Migration;
use CourseDiscovery\Index\Schema;
use wpdb;

/**
 * Adds the column SortOrder::Title sorts on.
 *
 * The index table deliberately stores only what is filtered and sorted on
 * (see CourseIndexer's class docblock) -- title-ordering could not exist
 * without a column to sort by, so this adds one and indexes it.
 * CourseIndexer::writeIndexRow() writes the post title into it on every
 * reindex; existing rows get the column's default ('') until their course
 * is next reindexed.
 *
 * Both statements must tolerate being reapplied to an already-migrated
 * table. MigrationRunner's version bookkeeping lives in wp_options, and DDL
 * causes an implicit COMMIT (see UsesIndexTables' docblock) that can commit
 * this migration's schema change while the *next* statement --
 * update_option() recording that version 3 applied -- still lands inside a
 * test-harness transaction that later gets rolled back. That leaves the
 * schema changed but the recorded version stale, so MigrationRunner will
 * attempt to reapply this migration; a bare ADD COLUMN would then fail with
 * "Duplicate column name".
 *
 * That reapply-safety is achieved by querying information_schema first,
 * NOT with `ADD COLUMN IF NOT EXISTS`. `IF NOT EXISTS` on ALTER TABLE is a
 * MariaDB extension: MySQL does not support it on ADD COLUMN or ADD INDEX
 * in any version, and fails with a syntax error rather than degrading. That
 * cost a real deployment -- the plugin is developed against MariaDB 10.11
 * but a managed database is as likely to be MySQL, and this migration is
 * reached during plugin activation, so the failure took the whole site down
 * on first boot rather than surfacing as a degraded feature.
 *
 * information_schema.COLUMNS and information_schema.STATISTICS are standard
 * and behave identically on both engines, so this is portable in a way the
 * previous form was not. M001's `CREATE TABLE IF NOT EXISTS` needs no such
 * treatment -- `IF NOT EXISTS` on CREATE TABLE is standard and supported by
 * both.
 */
final class M003AddTitleColumn implements Migration
{
    public function version(): int
    {
        return 3;
    }

    public function describe(): string
    {
        return 'Add and index course meta lookup title for title ordering';
    }

    public function up(wpdb $db, Schema $schema, callable $execute): void
    {
        $indexTable = $schema->metaLookupTable();

        if (!$this->columnExists($db, $indexTable, 'title')) {
            $execute("ALTER TABLE {$indexTable} ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''");
        }

        if (!$this->indexExists($db, $indexTable, 'title')) {
            $execute("ALTER TABLE {$indexTable} ADD INDEX title (title)");
        }
    }

    /**
     * DATABASE() rather than a configured schema name so this follows
     * whichever database the connection is actually using, matching how
     * Schema derives table names from $wpdb->prefix instead of assuming.
     */
    private function columnExists(wpdb $db, string $table, string $column): bool
    {
        $sql = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column
        );

        return $db->get_var($sql) !== null;
    }

    private function indexExists(wpdb $db, string $table, string $index): bool
    {
        $sql = $db->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $table,
            $index
        );

        return $db->get_var($sql) !== null;
    }
}
