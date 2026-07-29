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
 * `IF NOT EXISTS` on both statements (a MariaDB extension, confirmed
 * supported on the project's MariaDB 10.11) rather than a bare `ADD COLUMN`
 * / `ADD KEY`, matching M001's own `CREATE TABLE IF NOT EXISTS` idiom:
 * MigrationRunner's version bookkeeping lives in wp_options, and DDL causes
 * an implicit COMMIT in MariaDB (see UsesIndexTables' docblock) that can
 * commit this migration's schema change while the *next* statement --
 * update_option() recording that version 3 applied -- still lands inside a
 * test-harness transaction that later gets rolled back. That leaves the
 * schema changed but the recorded version stale, so MigrationRunner will
 * attempt to reapply this migration; without IF NOT EXISTS that reapply
 * would fail with "Duplicate column name".
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

        $execute("ALTER TABLE {$indexTable} ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT ''");
        $execute("ALTER TABLE {$indexTable} ADD INDEX IF NOT EXISTS title (title)");
    }
}
