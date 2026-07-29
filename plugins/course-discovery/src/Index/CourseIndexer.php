<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\CourseMeta;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\Taxonomies;
use RuntimeException;
use wpdb;

/**
 * Projects a course's scattered WordPress data into flat, indexed rows.
 *
 * This exists because a course's location is derived from its providers —
 * a course -> provider -> term traversal that WP_Query's meta_query cannot
 * express. Resolving it once at write time turns an impossible read query
 * into a single indexed lookup.
 */
final class CourseIndexer
{
    /** Savepoint name for the nested-transaction case; see startWrite(). */
    private const SAVEPOINT = 'cd_course_indexer';

    /**
     * Course ids currently mid-index; guards indexCourse() against
     * re-entrancy from a listener that re-triggers indexing of the same
     * course. Static so the guard holds across the whole call stack, not
     * just one instance.
     *
     * @var array<int, true>
     */
    private static array $indexing = [];

    /**
     * Cached engine check for inAmbientTransaction(); null until first
     * resolved. Derived from the connection's own version string, so it
     * cannot change for the life of this instance.
     */
    private ?bool $isMariaDb = null;

    public function __construct(
        private readonly wpdb $db,
        private readonly Schema $schema,
    ) {
    }

    public function indexCourse(int $courseId): void
    {
        if (isset(self::$indexing[$courseId])) {
            return;
        }

        self::$indexing[$courseId] = true;

        try {
            $post = get_post($courseId);

            // Covers both "no such post" and "no longer a course": either
            // way, any index/attribute rows left over from when it WAS a course
            // (or from a stale id) must not persist forever.
            if (! $post instanceof \WP_Post || $post->post_type !== PostTypes::COURSE) {
                $this->removeCourse($courseId);

                return;
            }

            if ($post->post_status !== 'publish') {
                $this->removeCourse($courseId);

                return;
            }

            $providerIds = CourseMeta::relationshipIds($courseId, AcfFields::FIELD_PROVIDERS);
            $locationIds = $this->locationsForProviders($providerIds);
            $categoryIds = $this->categoryIdsWithAncestors($courseId);
            $startKeys = StartDates::storedKeys($courseId);

            // Must land as a single unit: a process death partway through
            // must never leave an index row with partial attribute rows (see
            // startWrite()).
            $nested = $this->startWrite();

            try {
                $this->writeIndexRow($courseId, $post, $startKeys);

                $this->replaceAttributes($courseId, [
                    Attribute::Provider->value => $providerIds,
                    Attribute::Location->value => $locationIds,
                    Attribute::Category->value => $categoryIds,
                    Attribute::Start->value    => $startKeys,
                ]);
            } catch (\Throwable $e) {
                $this->rollbackWrite($nested);

                throw $e;
            }

            $this->commitWrite($nested);

            /**
             * Fires after a course has been reindexed. Third-party code can
             * add its own attribute rows via addAttributeValues(). Deliberately
             * outside the write transaction so a misbehaving listener can't
             * hold it open or roll back an already-committed write, but
             * still inside the re-entrancy guard, so self-reindexing is a
             * no-op rather than infinite recursion.
             */
            do_action('course_discovery/indexed_course', $courseId, $this);
        } finally {
            unset(self::$indexing[$courseId]);
        }
    }

    public function removeCourse(int $courseId): void
    {
        $this->db->delete($this->schema->metaLookupTable(), ['course_id' => $courseId], ['%d']);
        $this->db->delete($this->schema->attributeLookupTable(), ['course_id' => $courseId], ['%d']);
    }

    /**
     * Rebuilds the whole projection from scratch. Returns the number of
     * published courses processed.
     *
     * The post ids are collected BEFORE the tables are emptied, so a failure
     * to read them leaves the existing index untouched rather than wiped.
     */
    public function indexAll(): int
    {
        $ids = get_posts([
            'post_type'      => PostTypes::COURSE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $this->truncateLookupTables();

        $count = 0;

        foreach ($ids as $id) {
            $this->indexCourse((int) $id);
            $count++;
        }

        return $count;
    }

    /**
     * Empties both lookup tables ahead of a full rebuild.
     *
     * TRUNCATE, not DELETE, and the difference is not cosmetic. InnoDB gives
     * every row in a FULLTEXT index a hidden FTS_DOC_ID and keeps the ids of
     * deleted rows in an internal FTS_DELETED list, whose entries are
     * filtered out of the result of every MATCH. Emptying the table with
     * DELETE leaves those auxiliary tables in place while the doc-id counter
     * can restart from the now-empty index, so the next generation of rows
     * inherits ids the engine still considers deleted: the row is present,
     * its search_text is correct, and no keyword will ever match it.
     * (Observed on this project — a seeded course invisible to search while
     * `LIKE` found it happily. OPTIMIZE TABLE with
     * innodb_optimize_fulltext_only=ON did NOT clear the list.) TRUNCATE
     * drops and recreates the table together with its FTS auxiliary tables,
     * which resets both halves of that state consistently.
     *
     * The cost is that TRUNCATE is DDL: it causes an implicit commit, so a
     * rebuild cannot be wrapped in a transaction and searches run against an
     * empty index for as long as the rebuild takes. That is the accepted
     * trade for a recovery command — `wp course-discovery reindex` is
     * deliberate, occasional, and the alternative is an index that cannot be
     * repaired at all.
     */
    private function truncateLookupTables(): void
    {
        $tables = [
            $this->schema->metaLookupTable(),
            $this->schema->attributeLookupTable(),
        ];

        foreach ($tables as $table) {
            // $table is never user input — $wpdb->prefix plus a fixed Schema
            // suffix — and TRUNCATE takes no bindable parameters, so there is
            // nothing here for prepare() to do.
            if ($this->db->query("TRUNCATE TABLE {$table}") === false) {
                throw new RuntimeException(sprintf(
                    'Failed to truncate %s before a full reindex: %s',
                    $table,
                    $this->db->last_error
                ));
            }
        }
    }

    /**
     * Courses referencing a given provider — the set needing reindexing when
     * its location changes. ACF relationship meta serialises as either
     * numeric strings (admin-saved) or plain ints (API/WP-CLI writes), so
     * both forms are matched here — matching only one would leave
     * int-stored courses silently stale on a provider location change.
     *
     * @return list<int>
     */
    public function coursesForProvider(int $providerId): array
    {
        $ids = get_posts([
            'post_type'      => PostTypes::COURSE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => AcfFields::FIELD_PROVIDERS,
                    'value'   => sprintf('"%d"', $providerId),
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => AcfFields::FIELD_PROVIDERS,
                    'value'   => sprintf('i:%d;', $providerId),
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        return array_values(array_map('intval', $ids));
    }

    /**
     * @param  list<int> $providerIds
     * @return list<int>
     */
    private function locationsForProviders(array $providerIds): array
    {
        if ($providerIds === []) {
            return [];
        }

        $termIds = wp_get_object_terms($providerIds, Taxonomies::LOCATION, ['fields' => 'ids']);

        if (is_wp_error($termIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $termIds)));
    }

    /**
     * A course is indexed against its categories AND all their ancestors, so
     * selecting a parent matches a course filed only under a child. Doing it
     * here keeps the read query a flat IN () lookup with no recursion.
     *
     * @return list<int>
     */
    private function categoryIdsWithAncestors(int $courseId): array
    {
        $termIds = wp_get_object_terms($courseId, Taxonomies::CATEGORY, ['fields' => 'ids']);

        if (is_wp_error($termIds)) {
            return [];
        }

        $all = [];

        foreach ($termIds as $termId) {
            $id = (int) $termId;
            $all[] = $id;

            foreach (get_ancestors($id, Taxonomies::CATEGORY, 'taxonomy') as $ancestorId) {
                $all[] = (int) $ancestorId;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * @param list<int> $startKeys
     */
    private function writeIndexRow(int $courseId, \WP_Post $post, array $startKeys): void
    {
        $priceMinor = CourseMeta::priceMinor($courseId, AcfFields::FIELD_PRICE);

        $searchText = trim(implode(' ', [
            $post->post_title,
            $post->post_excerpt,
            wp_strip_all_tags($post->post_content),
        ]));

        $written = $this->db->replace(
            $this->schema->metaLookupTable(),
            [
                'course_id'         => $courseId,
                'price_minor'       => $priceMinor,
                'earliest_start_ym' => $startKeys === [] ? null : min($startKeys),
                'search_text'       => $searchText,
                'title'             => $post->post_title,
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );

        if ($written === false) {
            throw new RuntimeException(sprintf(
                'Failed to write index row for course %d: %s',
                $courseId,
                $this->db->last_error
            ));
        }
    }

    /**
     * Delete-then-insert rather than diffing — simpler and cannot leave
     * stale rows behind for the handful of attributes a course has. Writes all
     * attribute values in one multi-row INSERT rather than per-value INSERT
     * IGNORE (previously ~28 round trips per course); IGNORE is dropped
     * too since it hid genuine write failures as silent warnings and was
     * redundant given the DELETE above and the composite primary key.
     *
     * @param array<string, list<int>> $attributes
     */
    private function replaceAttributes(int $courseId, array $attributes): void
    {
        $table = $this->schema->attributeLookupTable();

        $deleted = $this->db->delete($table, ['course_id' => $courseId], ['%d']);

        if ($deleted === false) {
            throw new RuntimeException(sprintf(
                'Failed to clear attribute rows for course %d: %s',
                $courseId,
                $this->db->last_error
            ));
        }

        $this->insertAttributeRows($courseId, $attributes);
    }

    /**
     * Public seam for third-party code to add its own attribute rows from a
     * `course_discovery/indexed_course` listener, using the same prepared,
     * batched insert path as the indexer's built-in attributes, e.g.:
     *
     *     add_action('course_discovery/indexed_course', function (int $courseId, CourseIndexer $indexer): void {
     *         $indexer->addAttributeValues($courseId, 'skill_level', [3]);
     *     }, 10, 2);
     *
     * Safe to call repeatedly: only rows for the given attribute are cleared
     * first, so this never accumulates duplicates and cannot clobber the
     * built-in attributes or another listener's attribute.
     *
     * @param list<int> $valueIds
     */
    public function addAttributeValues(int $courseId, string $attribute, array $valueIds): void
    {
        $deleted = $this->db->delete(
            $this->schema->attributeLookupTable(),
            ['course_id' => $courseId, 'attribute' => $attribute],
            ['%d', '%s']
        );

        if ($deleted === false) {
            throw new RuntimeException(sprintf(
                'Failed to clear attribute rows for course %d, attribute "%s": %s',
                $courseId,
                $attribute,
                $this->db->last_error
            ));
        }

        $this->insertAttributeRows($courseId, [$attribute => $valueIds]);
    }

    /**
     * The single prepared, batched INSERT path shared by replaceAttributes() and
     * addAttributeValues(). One multi-row INSERT rather than per-value INSERT
     * IGNORE (previously ~28 round trips per course); IGNORE is dropped too
     * since it hid genuine write failures and was redundant — the caller's
     * DELETE already ran and the composite primary key prevents duplicates.
     *
     * @param array<string, list<int>> $attributes
     */
    private function insertAttributeRows(int $courseId, array $attributes): void
    {
        $table = $this->schema->attributeLookupTable();

        $rows = [];

        foreach ($attributes as $attribute => $valueIds) {
            foreach ($valueIds as $valueId) {
                $rows[] = [$courseId, $attribute, $valueId];
            }
        }

        if ($rows === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($rows), '(%d, %s, %d)'));
        $values = array_merge(...$rows);

        // %i keeps the query template a literal string (no {$table}
        // interpolation). {$placeholders} is built only from repeated
        // %d/%s specifiers, never interpolated values, so splicing it in
        // ahead of prepare() stays safe.
        $sql = $this->db->prepare(
            "INSERT INTO %i (course_id, attribute, value_id) VALUES {$placeholders}",
            $table,
            ...$values
        );

        if ($sql === null) {
            throw new RuntimeException(sprintf('Failed to prepare attribute insert for course %d', $courseId));
        }

        if ($this->db->query($sql) === false) {
            throw new RuntimeException(sprintf(
                'Failed to write attribute rows for course %d: %s',
                $courseId,
                $this->db->last_error
            ));
        }
    }

    /**
     * Starts the atomic write region for writeIndexRow() + replaceAttributes()
     * so a process death partway through never leaves a course with an
     * index row but stale/partial attribute rows.
     *
     * A nested bare `START TRANSACTION` implicitly COMMITs a MySQL/MariaDB
     * transaction that's already open (e.g. wp-phpunit's test-harness
     * wrapper), which would corrupt test isolation — so a SAVEPOINT is used
     * instead whenever a transaction is already open, leaving the caller's
     * transaction boundary untouched.
     *
     * @return bool whether a SAVEPOINT was used (i.e. a transaction was
     *              already open) rather than a real transaction
     */
    private function startWrite(): bool
    {
        $nested = $this->inAmbientTransaction();

        $this->db->query($nested ? ('SAVEPOINT ' . self::SAVEPOINT) : 'START TRANSACTION');

        return $nested;
    }

    /**
     * Whether a transaction is already open on this connection.
     *
     * `SELECT @@in_transaction` answers this on MariaDB and nowhere else --
     * MySQL has no such system variable and raises "Unknown system variable"
     * (error 1193). That is not a harmless miss: wpdb prints the failure,
     * which on WP-CLI corrupts the stdout of any command being read for its
     * value. `wp post create --porcelain` returned an error page instead of
     * a post ID and the seed died mid-run with "Could not find the post with
     * ID 0", leaving a half-populated site.
     *
     * There is no portable substitute usable here.
     * information_schema.INNODB_TRX exposes the same fact on both engines,
     * but MySQL gates it behind the PROCESS privilege, which the application
     * user of a managed database does not get (verified against MySQL 9.4:
     * "Access denied; you need (at least one of) the PROCESS privilege(s)").
     * So the engine is detected instead, from the version string wpdb
     * already holds -- no query, and no server-side error to suppress or
     * accidentally print.
     *
     * On MySQL this reports false, which is correct for every context this
     * code actually runs in: WordPress opens no ambient transaction around a
     * request, so a real START TRANSACTION is what atomicity requires. The
     * nesting case exists solely for wp-phpunit's per-test transaction
     * wrapper, and the suite runs on MariaDB (README §3). Running the suite
     * against MySQL would silently lose that isolation -- hence this note
     * rather than a quiet assumption.
     */
    private function inAmbientTransaction(): bool
    {
        $this->isMariaDb ??= stripos($this->db->db_server_info(), 'mariadb') !== false;

        if (!$this->isMariaDb) {
            return false;
        }

        return (int) $this->db->get_var('SELECT @@in_transaction') === 1;
    }

    private function commitWrite(bool $nested): void
    {
        $this->db->query($nested ? ('RELEASE SAVEPOINT ' . self::SAVEPOINT) : 'COMMIT');
    }

    private function rollbackWrite(bool $nested): void
    {
        $this->db->query($nested ? ('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT) : 'ROLLBACK');
    }
}
