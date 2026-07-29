<?php

declare(strict_types=1);

namespace CourseDiscovery\Index\Migrations;

use CourseDiscovery\Index\Migration;
use CourseDiscovery\Index\Schema;
use wpdb;

/**
 * The denormalised projection that makes filtered course queries possible.
 *
 * dbDelta() is deliberately not used: it is a schema-diffing tool with known
 * limitations around FULLTEXT and composite keys, whereas versioned
 * migrations want exact, explicit DDL applied exactly once.
 */
final class M001CreateLookupTables implements Migration
{
    public function version(): int
    {
        return 1;
    }

    public function describe(): string
    {
        return 'Create course meta and attribute lookup tables';
    }

    public function up(wpdb $db, Schema $schema, callable $execute): void
    {
        $charsetCollate = $schema->charsetCollate();
        $metaLookupTable = $schema->metaLookupTable();
        $attributeLookupTable = $schema->attributeLookupTable();

        // value_id is signed here by oversight -- see M002AlterAttributeValueUnsigned,
        // which corrects it. A versioned migration's own statements never
        // change after release; only a later migration alters what came
        // before. (The table NAMES here did change once, pre-release, to the
        // WordPress-idiomatic "_lookup" suffix -- safe only because nothing
        // had shipped and both tables were empty.)
        $execute(
            "CREATE TABLE IF NOT EXISTS {$metaLookupTable} (
                course_id BIGINT UNSIGNED NOT NULL,
                price_minor BIGINT NOT NULL DEFAULT 0,
                earliest_start_ym INT UNSIGNED NULL,
                search_text MEDIUMTEXT NOT NULL,
                PRIMARY KEY (course_id),
                KEY price_minor (price_minor),
                KEY earliest_start_ym (earliest_start_ym),
                FULLTEXT KEY search_text (search_text)
            ) ENGINE=InnoDB {$charsetCollate}"
        );

        $execute(
            "CREATE TABLE IF NOT EXISTS {$attributeLookupTable} (
                course_id BIGINT UNSIGNED NOT NULL,
                attribute VARCHAR(32) NOT NULL,
                value_id BIGINT NOT NULL,
                PRIMARY KEY (course_id, attribute, value_id),
                KEY attribute_lookup (attribute, value_id, course_id)
            ) ENGINE=InnoDB {$charsetCollate}"
        );
    }
}
