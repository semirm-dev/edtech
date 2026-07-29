<?php

declare(strict_types=1);

namespace CourseDiscovery\Index\Migrations;

use CourseDiscovery\Index\Migration;
use CourseDiscovery\Index\Schema;
use wpdb;

/**
 * value_id stores a WordPress post or term ID depending on the attribute, and
 * both are always unsigned -- M001 shipped the column as signed BIGINT by
 * oversight. Migrations only ever run once per site, so correcting M001's
 * own DDL would do nothing on any install where it already applied; a new
 * migration is what actually changes the column on those installs.
 */
final class M002AlterAttributeValueUnsigned implements Migration
{
    public function version(): int
    {
        return 2;
    }

    public function describe(): string
    {
        return 'Make course attribute lookup value_id unsigned';
    }

    public function up(wpdb $db, Schema $schema, callable $execute): void
    {
        $attributeTable = $schema->attributeLookupTable();

        $execute("ALTER TABLE {$attributeTable} MODIFY value_id BIGINT UNSIGNED NOT NULL");
    }
}
