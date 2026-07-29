<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Index;

use CourseDiscovery\Index\Schema;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

/**
 * Pins the exact index shapes the migration creates.
 *
 * Without these, deleting FULLTEXT KEY search_text or KEY attribute_lookup from
 * the migration leaves every other test passing -- nobody would notice
 * until full-text search or an attribute lookup query silently misbehaved in a
 * later task. These tests exist to fail loudly the moment either index
 * disappears or changes shape.
 */
final class SchemaIndexesTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);
        $this->prepareIndexTables();
    }

    public function test_index_table_has_a_fulltext_index_on_search_text(): void
    {
        global $wpdb;

        $indexType = $wpdb->get_var($wpdb->prepare(
            'SELECT INDEX_TYPE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $this->schema->metaLookupTable(),
            'search_text'
        ));

        self::assertSame('FULLTEXT', $indexType);
    }

    public function test_index_table_has_a_key_on_title(): void
    {
        global $wpdb;

        $indexType = $wpdb->get_var($wpdb->prepare(
            'SELECT INDEX_TYPE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $this->schema->metaLookupTable(),
            'title'
        ));

        self::assertSame('BTREE', $indexType, 'SortOrder::Title sorts on this column; without an index that sort scans the whole table.');
    }

    public function test_attribute_lookup_index_covers_attribute_value_id_course_id_in_order(): void
    {
        global $wpdb;

        $columns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             ORDER BY SEQ_IN_INDEX',
            $this->schema->attributeLookupTable(),
            'attribute_lookup'
        ));

        self::assertSame(['attribute', 'value_id', 'course_id'], $columns);
    }

    public function test_attribute_table_primary_key_covers_course_id_attribute_value_id_in_order(): void
    {
        global $wpdb;

        $columns = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX",
            $this->schema->attributeLookupTable()
        ));

        self::assertSame(['course_id', 'attribute', 'value_id'], $columns);
    }
}
