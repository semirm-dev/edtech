<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use wpdb;

interface Migration
{
    /**
     * Monotonically increasing. Applied in ascending order, once each.
     */
    public function version(): int;

    public function describe(): string;

    /**
     * @param callable(string): void $execute Runs a single DDL/DML statement
     *        against $db and throws if it fails to apply. Migrations use
     *        this instead of calling $db->query() directly so that a failed
     *        statement always aborts the migration -- MigrationRunner is
     *        then the one place that decides a failure means the version
     *        must not be recorded, rather than every migration author
     *        needing to remember to check $db->query()'s return value.
     */
    public function up(wpdb $db, Schema $schema, callable $execute): void;
}
