<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Query;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SortOrder;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\MigrationRunner;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Query\WhereClauseBuilder;
use CourseDiscovery\Query\WpCourseRepository;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class WpCourseRepositoryTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private WpCourseRepository $repository;
    private CourseIndexer $indexer;

    /** @var array<string, int> */
    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $schema = new Schema($wpdb);
        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, $schema);
        $this->repository = new WpCourseRepository($wpdb, $schema, new WhereClauseBuilder($wpdb, $schema));

        $this->providers['uosd'] = $this->makeProvider('india');
        $this->providers['dmu'] = $this->makeProvider('china');
    }

    private function makeProvider(string $location): int
    {
        /** @var int $id */
        $id = self::factory()->post->create(['post_type' => PostTypes::PROVIDER, 'post_status' => 'publish']);
        wp_set_object_terms($id, $location, Taxonomies::LOCATION);

        return $id;
    }

    /**
     * Creates a new post whose id does not collide with any id in
     * $excluded. Needed because post ids (wp_posts) and term ids
     * (wp_terms) are independent auto-increment sequences that can
     * otherwise coincide numerically -- see
     * test_it_hydrates_domain_objects()'s docblock.
     *
     * @param list<int> $excluded
     */
    private function makeDistinctPost(string $postType, array $excluded): int
    {
        do {
            /** @var int $id */
            $id = self::factory()->post->create(['post_type' => $postType, 'post_status' => 'publish']);
        } while (in_array($id, $excluded, true));

        return $id;
    }

    /**
     * Creates a new term whose id does not collide with any id in
     * $excluded -- see makeDistinctPost()'s docblock. Each attempt uses a
     * fresh, unique name so a naming collision is never the reason a
     * retry is needed.
     *
     * @param list<int> $excluded
     */
    private function makeDistinctTerm(string $taxonomy, string $label, array $excluded): int
    {
        do {
            /** @var int $id */
            $id = self::factory()->term->create([
                'taxonomy' => $taxonomy,
                'name'     => $label . '-' . uniqid('', true),
            ]);
        } while (in_array($id, $excluded, true));

        return $id;
    }

    /**
     * @param list<int> $providerIds
     * @param list<int> $startKeys
     */
    private function makeCourse(string $title, array $providerIds, array $startKeys, int $price): int
    {
        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => $title,
            'post_excerpt' => $title . ' summary',
            'post_content' => $title . ' full description',
            'post_status'  => 'publish',
        ]);

        update_post_meta($id, AcfFields::FIELD_PROVIDERS, array_map('strval', $providerIds));
        update_post_meta($id, AcfFields::FIELD_PRICE, (string) $price);
        update_post_meta($id, StartDates::META_KEY, $startKeys);

        $this->indexer->indexCourse($id);

        return $id;
    }

    public function test_it_returns_all_courses_with_no_constraints(): void
    {
        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Beta', [$this->providers['dmu']], [202603], 1200);

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->courses);
    }

    /**
     * Course's constructor takes four consecutive list<int> parameters
     * (providerIds, instructorIds, categoryIds, locationIds) -- structurally
     * identical types, so PHPStan cannot catch a transposition and only
     * asserting one of the four (as this test used to) would let the other
     * three be silently swapped forever. Every accessor is asserted here,
     * against four id sets that are genuinely distinct from one another, so
     * a transposition of any two is guaranteed to flip an assertion rather
     * than pass by coincidence.
     *
     * The fixture builds its own providers/instructors/categories/locations
     * from scratch (rather than reusing setUp()'s shared providers) using
     * makeDistinctPost()/makeDistinctTerm(), which retry until an id does
     * not collide with any id already in use. This matters because post
     * ids (wp_posts) and term ids (wp_terms) are independent auto-increment
     * sequences -- nothing stops a provider's post id from numerically
     * coinciding with a category's term id, which would silently defeat
     * this test's ability to catch a transposition between them.
     */
    public function test_it_hydrates_domain_objects(): void
    {
        $usedIds = [];

        $providerA = $this->makeDistinctPost(PostTypes::PROVIDER, $usedIds);
        $usedIds[] = $providerA;
        $providerB = $this->makeDistinctPost(PostTypes::PROVIDER, $usedIds);
        $usedIds[] = $providerB;

        $instructorA = $this->makeDistinctPost(PostTypes::INSTRUCTOR, $usedIds);
        $usedIds[] = $instructorA;
        $instructorB = $this->makeDistinctPost(PostTypes::INSTRUCTOR, $usedIds);
        $usedIds[] = $instructorB;

        $categoryA = $this->makeDistinctTerm(Taxonomies::CATEGORY, 'Graphic Design', $usedIds);
        $usedIds[] = $categoryA;
        $categoryB = $this->makeDistinctTerm(Taxonomies::CATEGORY, 'Illustration', $usedIds);
        $usedIds[] = $categoryB;

        $locationA = $this->makeDistinctTerm(Taxonomies::LOCATION, 'Location A', $usedIds);
        $usedIds[] = $locationA;
        $locationB = $this->makeDistinctTerm(Taxonomies::LOCATION, 'Location B', $usedIds);
        $usedIds[] = $locationB;

        wp_set_object_terms($providerA, [$locationA], Taxonomies::LOCATION);
        wp_set_object_terms($providerB, [$locationB], Taxonomies::LOCATION);

        $courseId = $this->makeCourse('Alpha', [$providerA, $providerB], [202603, 202601], 950);

        update_post_meta($courseId, AcfFields::FIELD_INSTRUCTORS, [(string) $instructorA, (string) $instructorB]);
        wp_set_object_terms($courseId, [$categoryA, $categoryB], Taxonomies::CATEGORY);
        $this->indexer->indexCourse($courseId);

        $expectedProviders = [$providerA, $providerB];
        sort($expectedProviders);
        $expectedInstructors = [$instructorA, $instructorB];
        $expectedCategories = [$categoryA, $categoryB];
        sort($expectedCategories);
        $expectedLocations = [$locationA, $locationB];
        sort($expectedLocations);

        // Sanity check on the fixture itself, not the repository: proves
        // the retries above actually delivered eight pairwise-distinct ids,
        // rather than trusting that silently.
        $allIds = array_merge($expectedProviders, $expectedInstructors, $expectedCategories, $expectedLocations);
        self::assertCount(
            8,
            array_unique($allIds),
            'Fixture ids collided across id lists -- a transposition would go undetected. Adjust the fixture.'
        );

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);

        $courses = iterator_to_array($result->courses);
        $course = $courses[0];

        self::assertSame('Alpha', $course->title);
        self::assertSame('£950.00', $course->pricing->format());
        self::assertSame([202601, 202603], $course->startDates->toSortKeys());
        self::assertSame($expectedProviders, $course->providerIds);
        self::assertSame($expectedInstructors, $course->instructorIds);
        self::assertSame($expectedCategories, $course->categoryIds);
        self::assertSame($expectedLocations, $course->locationIds);
    }

    public function test_values_within_one_filter_are_combined_with_or(): void
    {
        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Beta', [$this->providers['dmu']], [202603], 1200);

        $result = $this->repository->search(
            ConstraintSet::of(new AttributeInConstraint('provider', [$this->providers['uosd'], $this->providers['dmu']])),
            Pagination::default(),
            SortOrder::Soonest
        );

        self::assertSame(2, $result->total, 'Multiple values in one filter must OR.');
    }

    public function test_separate_filters_are_combined_with_and(): void
    {
        $india = get_term_by('slug', 'india', Taxonomies::LOCATION);
        self::assertInstanceOf(\WP_Term::class, $india);

        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Beta', [$this->providers['dmu']], [202603], 1200);

        $result = $this->repository->search(
            ConstraintSet::of(
                new AttributeInConstraint('provider', [$this->providers['uosd'], $this->providers['dmu']]),
                new AttributeInConstraint('location', [$india->term_id]),
            ),
            Pagination::default(),
            SortOrder::Soonest
        );

        self::assertSame(1, $result->total, 'Separate filters must AND: only the India course survives.');
    }

    public function test_full_text_search_matches_the_long_description(): void
    {
        global $wpdb;

        $this->makeCourse('Typography Masterclass', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Statistics Primer', [$this->providers['dmu']], [202603], 1200);

        // InnoDB defers FULLTEXT index changes to an internal DML cache that
        // is only merged into the searchable index on commit -- see
        // WhereClauseBuilderTest::test_a_combined_attribute_and_search_fragment_executes_correctly()'s
        // docblock, which verified this directly against this project's own
        // test database. wp-phpunit wraps every test in a transaction rolled
        // back at tear_down(), so without this explicit COMMIT the MATCH ...
        // AGAINST query below can never see the rows just indexed. Safe
        // here: prepareIndexTables() truncates both index tables again at
        // the start of every test's setUp().
        $wpdb->query('COMMIT');

        $result = $this->repository->search(
            ConstraintSet::of(new SearchTextConstraint('Typography')),
            Pagination::default(),
            SortOrder::Soonest
        );

        self::assertSame(1, $result->total);
    }

    /**
     * WhereClauseBuilder::buildSearchText() runs the search term through
     * $wpdb->prepare(), which turns any literal `%` in it into a per-request
     * placeholder-escape token (see WhereClauseBuilder::build()'s docblock and
     * wp-includes/class-wpdb.php's placeholder_escape()/
     * add_placeholder_escape()). That token is only ever resolved back to a
     * literal `%` by the `query` filter $wpdb registers the first time any
     * prepare() call runs -- a filter that fires on every query passed to
     * $wpdb->query()/get_col(), regardless of where in the pipeline it came
     * from.
     *
     * A test asserting only total() === 1 cannot tell a working escape from
     * a broken one: the builder's boolean-mode strip regex leaves `{` and
     * `}` alone, so a leaked token would render as
     * "Masterclass 50{token} off" -- and boolean-mode full-text search still
     * matches on the word "Masterclass" alone either way. This version
     * captures the SQL actually sent to the database (via a `query` filter
     * that records and returns it unmodified) and asserts directly on that
     * string, plus on the matched course's title, so a regression here is
     * guaranteed to fail rather than pass by coincidence.
     */
    public function test_a_literal_percent_in_search_text_survives_into_the_executed_sql(): void
    {
        global $wpdb;

        $this->makeCourse('Discount Voucher Masterclass', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Unrelated Statistics Primer', [$this->providers['dmu']], [202603], 1200);

        $wpdb->query('COMMIT');

        /** @var list<string> $executedQueries */
        $executedQueries = [];
        $capture = static function (string $query) use (&$executedQueries): string {
            $executedQueries[] = $query;

            // Must return the query unmodified -- this filter only
            // observes what wpdb is about to run, it must not alter it.
            return $query;
        };

        add_filter('query', $capture);

        try {
            $result = $this->repository->search(
                ConstraintSet::of(new SearchTextConstraint('Masterclass 50% off')),
                Pagination::default(),
                SortOrder::Soonest
            );
        } finally {
            remove_filter('query', $capture);
        }

        $searchQueries = array_values(array_filter(
            $executedQueries,
            static fn (string $query): bool => str_contains($query, 'MATCH') && str_contains($query, 'LIMIT')
        ));

        self::assertNotEmpty($searchQueries, 'Expected the main search query to actually execute.');
        $executedSql = $searchQueries[0];

        self::assertStringContainsString(
            '%',
            $executedSql,
            'A literal % in the search text must survive, as a literal %, into the executed SQL.'
        );
        // The token is '{' . hash_hmac('sha256', ...) . '}' -- see
        // wpdb::placeholder_escape(). Its presence here would mean the
        // token never got resolved back to a literal % before execution.
        self::assertDoesNotMatchRegularExpression(
            '/\{[0-9a-f]{64}\}/',
            $executedSql,
            'The executed SQL must contain no leftover, unresolved placeholder-escape token.'
        );

        self::assertSame(
            '',
            $wpdb->last_error,
            'A literal % in the search text must not corrupt the executed query.'
        );
        self::assertSame(1, $result->total);

        $titles = array_map(static fn ($c): string => $c->title, iterator_to_array($result->courses));
        self::assertSame(['Discount Voucher Masterclass'], $titles);
    }

    public function test_results_order_by_soonest_start_date(): void
    {
        $this->makeCourse('Later', [$this->providers['uosd']], [202609], 950);
        $this->makeCourse('Sooner', [$this->providers['uosd']], [202601], 1200);

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($result->courses)
        );

        self::assertSame(['Sooner', 'Later'], $titles);
    }

    public function test_results_order_by_price_ascending(): void
    {
        $this->makeCourse('Expensive', [$this->providers['uosd']], [202601], 2000);
        $this->makeCourse('Cheap', [$this->providers['uosd']], [202601], 500);

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::PriceAscending);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($result->courses)
        );

        self::assertSame(['Cheap', 'Expensive'], $titles);
    }

    public function test_results_order_by_title(): void
    {
        $this->makeCourse('Zeta', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Title);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($result->courses)
        );

        self::assertSame(['Alpha', 'Zeta'], $titles);
    }

    public function test_the_order_filter_can_override_the_default_ordering(): void
    {
        $this->makeCourse('B', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('A', [$this->providers['uosd']], [202603], 1200);

        $override = static fn (): string => 'i.course_id DESC';
        add_filter('course_discovery/order', $override);

        try {
            $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);
        } finally {
            remove_filter('course_discovery/order', $override);
        }

        $titles = array_map(static fn ($c): string => $c->title, iterator_to_array($result->courses));

        self::assertSame(['A', 'B'], $titles, 'A filter returning a valid ORDER BY expression must be honoured verbatim.');
    }

    /**
     * Guards the `course_discovery/order` filter's security contract: a
     * misbehaving third-party callback must degrade to the whitelisted
     * default ordering, not fatal. Before this guard existed, `(string)`
     * casting a non-string filter result (e.g. an array) raised a PHP
     * "Array to string conversion" warning -- which, under this project's
     * failOnWarning="true" PHPUnit setting, would fail this very test.
     */
    public function test_a_non_string_order_filter_result_falls_back_to_the_default_ordering(): void
    {
        $this->makeCourse('Later', [$this->providers['uosd']], [202609], 950);
        $this->makeCourse('Sooner', [$this->providers['uosd']], [202601], 1200);

        $badFilter = static fn (): array => ['not', 'a', 'string'];
        add_filter('course_discovery/order', $badFilter);

        try {
            $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);
        } finally {
            remove_filter('course_discovery/order', $badFilter);
        }

        $titles = array_map(static fn ($c): string => $c->title, iterator_to_array($result->courses));

        self::assertSame(
            ['Sooner', 'Later'],
            $titles,
            'A filter returning a non-string must not fatal; the whitelisted default ordering must still apply.'
        );
    }

    public function test_pagination_limits_and_reports_the_full_total(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCourse('Course ' . $i, [$this->providers['uosd']], [202600 + $i], 950);
        }

        $result = $this->repository->search(ConstraintSet::empty(), new Pagination(1, 2), SortOrder::Soonest);

        self::assertCount(2, $result->courses, 'Page size must be respected.');
        self::assertSame(5, $result->total, 'Total must count all matches, not just this page.');
        self::assertSame(3, $result->totalPages());
    }

    public function test_the_second_page_returns_different_courses(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->makeCourse('Course ' . $i, [$this->providers['uosd']], [202600 + $i], 950);
        }

        $first = $this->repository->search(ConstraintSet::empty(), new Pagination(1, 2), SortOrder::Soonest);
        $second = $this->repository->search(ConstraintSet::empty(), new Pagination(2, 2), SortOrder::Soonest);

        $firstIds = array_map(static fn ($c): int => $c->id->value, iterator_to_array($first->courses));
        $secondIds = array_map(static fn ($c): int => $c->id->value, iterator_to_array($second->courses));

        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function test_an_empty_result_set_reports_zero_total_and_zero_pages(): void
    {
        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->totalPages());
        self::assertCount(0, $result->courses);
    }

    public function test_a_page_beyond_the_last_page_returns_no_courses_but_reports_the_full_total(): void
    {
        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('Beta', [$this->providers['dmu']], [202603], 1200);

        $result = $this->repository->search(ConstraintSet::empty(), new Pagination(5, 2), SortOrder::Soonest);

        self::assertCount(0, $result->courses);
        self::assertSame(2, $result->total, 'Total must still reflect the full match count, not the requested (empty) page.');
    }

    /**
     * Simulates an index row that has gone stale relative to wp_posts --
     * e.g. a direct DB operation, an import, or a race, none of which went
     * through wp_delete_post() (and therefore never fired
     * IndexInvalidator's deleted_post cleanup). hydrate() must drop such a
     * course silently; total() is computed from the index alone, so it
     * necessarily still counts the now-orphaned row. That total() >
     * count(courses()) skew is documented and expected here, not treated as
     * impossible.
     */
    public function test_a_course_indexed_but_deleted_from_wp_posts_is_dropped_without_fataling(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse('Ghost', [$this->providers['uosd']], [202601], 950);

        $wpdb->delete($wpdb->posts, ['ID' => $courseId], ['%d']);
        clean_post_cache($courseId);

        $result = $this->repository->search(ConstraintSet::empty(), Pagination::default(), SortOrder::Soonest);

        self::assertCount(0, $result->courses, 'hydrate() must drop a course whose post no longer exists, not fatal.');
        self::assertSame(
            1,
            $result->total,
            'total() is computed from the index alone, so it still counts the orphaned row -- documented ' .
            'skew, not a bug.'
        );
    }

    public function test_the_canonical_worked_example_composes_correctly(): void
    {
        // (provider = uosd OR dmu) AND (location = india) — the canonical
        // AND-between-filters / OR-within-a-filter case.
        $india = get_term_by('slug', 'india', Taxonomies::LOCATION);
        self::assertInstanceOf(\WP_Term::class, $india);

        $this->makeCourse('India course', [$this->providers['uosd']], [202601], 950);
        $this->makeCourse('China course', [$this->providers['dmu']], [202603], 1200);
        $this->makeCourse('Both', [$this->providers['uosd'], $this->providers['dmu']], [202605], 800);

        $result = $this->repository->search(
            ConstraintSet::of(
                new AttributeInConstraint('provider', [$this->providers['uosd'], $this->providers['dmu']]),
                new AttributeInConstraint('location', [$india->term_id]),
            ),
            Pagination::default(),
            SortOrder::Soonest
        );

        $titles = array_map(static fn ($c): string => $c->title, iterator_to_array($result->courses));
        sort($titles);

        self::assertSame(['Both', 'India course'], $titles);
    }

    /**
     * Turns the performance claim into a regression test: a
     * naive per-course hydration (get_post() plus one attribute query per attribute
     * type, per course) would cost roughly five queries per course --
     * ~60 for a page of 12 -- with the 36 attribute queries alone collapsing
     * into a single grouped query. Measured directly against the code
     * before this fix (a page of 5 courses): 17 queries beyond the fixture
     * setup (15 attribute queries -- 3 attribute types x 5 courses -- plus the main
     * SELECT and the COUNT query; get_post()/get_post_meta() were
     * already free in this same-request test because WordPress's object
     * cache was warmed by the fixtures' own factory calls). After batching
     * attributes into one query and priming the post cache, the same scenario
     * costs 3: the main SELECT, the COUNT, and the single grouped attribute
     * query. The ceiling below is set well above that measured value so the
     * assertion is about the shape of the cost (flat, not per-course), not
     * a brittle exact count.
     */
    public function test_hydrating_a_page_does_not_scale_queries_with_course_count(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCourse('Course ' . $i, [$this->providers['uosd']], [202600 + $i], 950);
        }

        global $wpdb;
        $before = $wpdb->num_queries;

        $result = $this->repository->search(ConstraintSet::empty(), new Pagination(1, 5), SortOrder::Soonest);

        $after = $wpdb->num_queries;

        self::assertSame(5, $result->total);
        self::assertLessThanOrEqual(
            6,
            $after - $before,
            'Hydrating a page must not issue a query per course -- the query count must not scale with page size.'
        );
    }

    public function test_it_lists_the_distinct_values_present_for_a_attribute(): void
    {
        $this->makeCourse('Alpha', [$this->providers['uosd']], [202601], 950);

        $values = $this->repository->attributeValues('provider');

        self::assertSame([$this->providers['uosd']], $values);
    }
}
