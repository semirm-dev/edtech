<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use WP_CLI;

/**
 * `wp course-discovery reindex`
 *
 * Recovery for an index that has drifted — after a bulk import, a direct
 * database edit, or a schema change. Also the fastest way to populate the
 * index on a site that had content before the plugin was activated.
 *
 * A true rebuild: indexAll() empties both lookup tables before repopulating
 * them, so it also clears rows for courses that no longer exist and resets
 * InnoDB's FULLTEXT auxiliary state (see truncateLookupTables() for why the
 * latter is not optional). Searches therefore return nothing for the few
 * seconds a rebuild takes — run it deliberately, not on a schedule.
 */
final class ReindexCommand
{
    public function __construct(private readonly CourseIndexer $indexer)
    {
    }

    /**
     * Rebuilds the course index from scratch.
     *
     * ## EXAMPLES
     *
     *     wp course-discovery reindex
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $count = $this->indexer->indexAll();

        $message = sprintf(
            /* translators: %d: number of courses reindexed. */
            _n('Reindexed %d course.', 'Reindexed %d courses.', $count, 'course-discovery'),
            $count
        );

        WP_CLI::success($message);
    }
}
