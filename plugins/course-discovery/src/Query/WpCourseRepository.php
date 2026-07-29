<?php

declare(strict_types=1);

namespace CourseDiscovery\Query;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\CourseMeta;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Course;
use CourseDiscovery\Domain\CourseCollection;
use CourseDiscovery\Domain\CourseId;
use CourseDiscovery\Domain\Money;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SearchResult;
use CourseDiscovery\Domain\SinglePrice;
use CourseDiscovery\Domain\SortOrder;
use CourseDiscovery\Domain\StartDateCollection;
use CourseDiscovery\Index\Attribute;
use CourseDiscovery\Index\Schema;
use RuntimeException;
use WP_Post;
use wpdb;

/**
 * Answers filtered course queries from the denormalised index.
 *
 * An indexed statement selects the page of matching ids, a second counts the
 * full match set for pagination, and those ids are then hydrated into domain
 * objects. Hydration reads WordPress posts rather than the index because the
 * index deliberately stores only what is filtered and sorted on, not
 * everything needed for display.
 *
 * Hydration is batched, not per-course: search() primes the post cache for
 * the whole page in one call and fetches every course's attribute rows in a
 * single grouped query, so the query count per page is fixed rather than
 * scaling with the number of courses (a naive get_post()-plus-attribute-query
 * per course would be ~60 queries for a page of 12).
 */
final class WpCourseRepository implements CourseRepository
{
    private const CURRENCY = 'GBP';

    public function __construct(
        private readonly wpdb $db,
        private readonly Schema $schema,
        private readonly WhereClauseBuilder $whereBuilder,
    ) {}

    public function search(ConstraintSet $constraints, Pagination $pagination, SortOrder $orderBy): SearchResult
    {
        $indexTable = $this->schema->metaLookupTable();

        // Already prepared and parenthesised by WhereClauseBuilder -- must
        // NOT be prepared or escaped again. Any literal `%` in the user's
        // search text is already a per-request placeholder-escape token (see
        // that method's docblock); the token is stable across the request and
        // is resolved back to `%` by the `query` filter $wpdb runs on every
        // query, including the get_col() below.
        $where = $this->whereBuilder->build($constraints);

        $defaultOrderSql = $this->orderSql($orderBy);

        /**
         * Lets third-party code alter ordering without replacing the
         * repository: the seam for customising result ordering.
         *
         * Security contract for `course_discovery/order` -- the return value
         * is spliced verbatim into `ORDER BY`, so a callback MUST:
         *  - return a complete, already-safe ORDER BY expression (no
         *    unescaped user input, nothing still needing $wpdb->prepare());
         *  - assume the outer query aliases the index table `i`.
         * Anything other than a non-empty string falls back to the
         * whitelisted default below, so a misbehaving filter degrades one
         * search's ordering rather than fataling or opening an injection seam.
         */
        $filteredOrderSql = apply_filters(
            'course_discovery/order',
            $defaultOrderSql,
            $orderBy,
            $constraints
        );

        $orderSql = is_string($filteredOrderSql) && $filteredOrderSql !== ''
            ? $filteredOrderSql
            : $defaultOrderSql;

        // LIMIT/OFFSET go through prepare() with a literal template;
        // Pagination already validates both as ints, so this honours the
        // project's "interpolate values only via prepare()" rule rather than
        // closing a live hole. $indexTable is never user input ($wpdb->prefix
        // plus a fixed Schema suffix), so it is interpolated directly.
        $limitSql = $this->db->prepare('LIMIT %d OFFSET %d', $pagination->perPage, $pagination->offset());

        if ($limitSql === null) {
            throw new RuntimeException('Failed to prepare LIMIT/OFFSET SQL.');
        }

        /**
         * The two statements below, for ?q=design&provider[]=117&provider[]=118:
         *
         * SELECT i.course_id
         * FROM wp_cd_course_meta_lookup i
         *
         * -- WhereClauseBuilder output starts
         * WHERE (MATCH (i.search_text) AGAINST ('design' IN BOOLEAN MODE))
         *   AND (EXISTS (SELECT 1 FROM wp_cd_course_attribute_lookup f
         *                WHERE f.course_id = i.course_id
         *                  AND f.attribute = 'provider'
         *                  AND f.value_id IN (117, 118)))
         * -- WhereClauseBuilder output ends
         *
         * ORDER BY i.earliest_start_ym IS NULL, i.earliest_start_ym ASC, i.course_id ASC
         * LIMIT 12 OFFSET 0
         *
         * SELECT COUNT(*) FROM wp_cd_course_meta_lookup i WHERE <same clause>
         *
         * Fragment order follows registry order, so the keyword filter's MATCH
         * comes first; each additional filter adds one more AND-ed fragment.
         */

        $sql = "SELECT i.course_id
                FROM {$indexTable} i
                WHERE {$where}
                ORDER BY {$orderSql}
                {$limitSql}";

        /** @var list<string> $ids */
        $ids = $this->db->get_col($sql);

        // $where is already prepared -- reused verbatim, never prepared again.
        $total = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$indexTable} i WHERE {$where}"
        );

        $courseIds = array_map('intval', $ids);

        // Primes the post (and meta) cache for the whole page in one pass,
        // so get_post()/get_post_meta() inside hydrate() below hit the
        // cache instead of issuing a query per course. Term cache is
        // skipped: hydrate() never reads terms directly, only attribute rows
        // (fetched in bulk immediately below).
        if ($courseIds !== []) {
            _prime_post_caches($courseIds, false, true);
        }

        $attributesByCourse = $this->attributesForCourses($courseIds);

        $courses = array_values(array_filter(array_map(
            fn(int $id): ?Course => $this->hydrate($id, $attributesByCourse[$id] ?? []),
            $courseIds
        )));

        return new SearchResult(CourseCollection::fromArray($courses), $total, $pagination);
    }

    /**
     * @return list<int>
     */
    public function attributeValues(string $attribute): array
    {
        $prepared = $this->db->prepare(
            'SELECT DISTINCT value_id FROM %i WHERE attribute = %s ORDER BY value_id',
            $this->schema->attributeLookupTable(),
            $attribute
        );

        if ($prepared === null) {
            throw new RuntimeException('Failed to prepare attribute-values SQL.');
        }

        /** @var list<string> $values */
        $values = $this->db->get_col($prepared);

        return array_map('intval', $values);
    }

    /**
     * Whitelisted, never interpolated from user input. The match is
     * exhaustive over SortOrder's cases -- this is the one place in the
     * codebase allowed to know a sort order maps to SQL at all (see
     * SortOrder's docblock).
     */
    private function orderSql(SortOrder $orderBy): string
    {
        return match ($orderBy) {
            SortOrder::PriceAscending => 'i.price_minor ASC, i.course_id ASC',
            SortOrder::Title          => 'i.title ASC, i.course_id ASC',
            SortOrder::Soonest        => 'i.earliest_start_ym IS NULL, i.earliest_start_ym ASC, i.course_id ASC',
        };
    }

    /**
     * @param array<string, list<int>> $attributesByType this course's attribute
     *        rows, already grouped by attribute type -- see attributesForCourses(),
     *        which fetches the whole page's attribute rows in a single query
     *        rather than one query per attribute type per course.
     */
    private function hydrate(int $courseId, array $attributesByType): ?Course
    {
        $post = get_post($courseId);

        if (! $post instanceof WP_Post) {
            return null;
        }

        // Defense in depth, not the primary safeguard --
        // CourseIndexer::indexCourse() already refuses to index (and
        // actively removes) a non-published course, so $courseId reaching
        // here should always already be published. This exists for the
        // same reason the orphaned-post check just above does: an index row
        // can go stale relative to wp_posts (a direct DB status change, an
        // import, a race) without going through indexCourse() again, and a
        // stale row must not resurrect a now-unpublished course's title and
        // description into rendered HTML.
        if ($post->post_status !== 'publish') {
            return null;
        }

        $priceMinor = CourseMeta::priceMinor($courseId, AcfFields::FIELD_PRICE);

        // Named arguments are mandatory here: Course takes four consecutive
        // list<int> parameters, so a transposition is invisible to PHPStan.
        return new Course(
            id: CourseId::fromInt($courseId),
            title: $post->post_title,
            shortDescription: $post->post_excerpt,
            longDescription: $post->post_content,
            pricing: new SinglePrice(Money::fromMinor($priceMinor, self::CURRENCY)),
            startDates: StartDateCollection::fromSortKeys(StartDates::storedKeys($courseId)),
            providerIds: $attributesByType[Attribute::Provider->value] ?? [],
            // Instructors are not an attribute — nothing filters on them — so they
            // come from meta rather than the index.
            instructorIds: CourseMeta::relationshipIds($courseId, AcfFields::FIELD_INSTRUCTORS),
            categoryIds: $attributesByType[Attribute::Category->value] ?? [],
            locationIds: $attributesByType[Attribute::Location->value] ?? [],
        );
    }

    /**
     * Every attribute row for a whole page of courses in one query, grouped by
     * course id and then by attribute type -- replaces what would otherwise be
     * one query per attribute type per course (see the class docblock).
     *
     * @param  list<int> $courseIds
     * @return array<int, array<string, list<int>>>
     */
    private function attributesForCourses(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($courseIds), '%d'));

        // %i (identifier placeholder) keeps the query template a true
        // literal-string; interpolating the table name directly would not.
        // {$placeholders} is built only from repeated %d specifiers, never
        // from interpolated values, matching CourseIndexer::replaceAttributes()'s
        // identical pattern.
        $sql = $this->db->prepare(
            "SELECT course_id, attribute, value_id FROM %i
             WHERE course_id IN ({$placeholders})
             ORDER BY course_id, attribute, value_id",
            $this->schema->attributeLookupTable(),
            ...$courseIds
        );

        if ($sql === null) {
            throw new RuntimeException('Failed to prepare attributes-for-courses SQL.');
        }

        /** @var list<array{course_id: string, attribute: string, value_id: string}> $rows */
        $rows = $this->db->get_results($sql, ARRAY_A) ?? [];

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['course_id']][$row['attribute']][] = (int) $row['value_id'];
        }

        return $grouped;
    }
}
