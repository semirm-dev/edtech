<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Index;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

/**
 * Pins the behaviour `wp course-discovery reindex` depends on:
 * CourseIndexer::indexAll() must rebuild every published course's index row
 * from scratch and must skip drafts. ReindexCommand itself is a thin WP_CLI
 * adapter with no branching logic of its own -- see the class docblock --
 * so the command's correctness rests entirely on indexAll(), exercised here.
 */
final class ReindexCommandTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private Schema $schema;
    private CourseIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);

        // Runs migrations and truncates both index tables. Also removes
        // wp-phpunit's temporary-table filter, without which MariaDB rejects
        // the FULLTEXT index on cd_course_meta_lookup. See the trait's docblock.
        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, $this->schema);

        // The CREATE/TRUNCATE TABLE statements prepareIndexTables() issues
        // are DDL, which causes an implicit COMMIT in MariaDB -- exactly the
        // consequence the trait's own docblock warns about. That implicit
        // commit voids wp-phpunit's per-test SAVEPOINT, so courses created
        // by an EARLIER test in this class are never rolled back and leak
        // into this one. indexAll()'s return value is an absolute count of
        // every published course, so leaked posts corrupt it silently
        // (observed: test_index_all_skips_drafts failed with 4 instead of 1
        // when run after test_index_all_rebuilds_every_published_course,
        // but passed in isolation). Deleting any pre-existing courses here,
        // regardless of status, makes each test start from a known-zero
        // baseline no matter what ran before it.
        $leftoverCourseIds = $wpdb->get_col(
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", PostTypes::COURSE)
        );

        foreach ($leftoverCourseIds as $leftoverCourseId) {
            wp_delete_post((int) $leftoverCourseId, true);
        }
    }

    public function test_index_all_rebuilds_every_published_course(): void
    {
        global $wpdb;

        for ($i = 0; $i < 3; $i++) {
            self::factory()->post->create([
                'post_type'   => PostTypes::COURSE,
                'post_status' => 'publish',
            ]);
        }

        // Wipe the index behind the indexer's back, simulating corruption
        // (or a schema change, or content authored before the plugin was
        // active) -- exactly the scenario `wp course-discovery reindex`
        // exists to recover from.
        $wpdb->query('TRUNCATE TABLE ' . $this->schema->metaLookupTable());
        $wpdb->query('TRUNCATE TABLE ' . $this->schema->attributeLookupTable());

        $count = $this->indexer->indexAll();

        self::assertGreaterThanOrEqual(3, $count);

        $table = $this->schema->metaLookupTable();
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        self::assertSame($count, $rows, 'Every reindexed course must have an index row.');
    }

    public function test_index_all_skips_drafts(): void
    {
        self::factory()->post->create(['post_type' => PostTypes::COURSE, 'post_status' => 'publish']);
        self::factory()->post->create(['post_type' => PostTypes::COURSE, 'post_status' => 'draft']);

        $count = $this->indexer->indexAll();

        self::assertSame(1, $count, 'Drafts must not be indexed.');
    }
}
