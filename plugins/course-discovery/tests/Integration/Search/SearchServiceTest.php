<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Search;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Filter\CategoryFilter;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Filter\KeywordFilter;
use CourseDiscovery\Filter\LocationFilter;
use CourseDiscovery\Filter\ProviderFilter;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Query\WhereClauseBuilder;
use CourseDiscovery\Query\WpCourseRepository;
use CourseDiscovery\Search\SearchService;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class SearchServiceTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private SearchService $service;
    private CourseIndexer $indexer;
    private int $uosd;
    private int $dmu;

    /** @var list<Constraint> captured by the HOOK_CONSTRAINTS spy in the keyword-applied-once test */
    private array $capturedConstraints = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $schema = new Schema($wpdb);
        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, $schema);
        $repository = new WpCourseRepository($wpdb, $schema, new WhereClauseBuilder($wpdb, $schema));

        $registry = new FilterRegistry();
        $registry->register(new KeywordFilter());
        $registry->register(new ProviderFilter());
        $registry->register(new LocationFilter());
        $registry->register(new CategoryFilter());

        $this->service = new SearchService($registry, $repository);

        $this->uosd = $this->makeProvider('india');
        $this->dmu = $this->makeProvider('china');
    }

    private function makeProvider(string $location): int
    {
        /** @var int $id */
        $id = self::factory()->post->create(['post_type' => PostTypes::PROVIDER, 'post_status' => 'publish']);
        wp_set_object_terms($id, $location, Taxonomies::LOCATION);

        return $id;
    }

    /**
     * @param list<int> $providerIds
     */
    private function makeCourse(string $title, array $providerIds): int
    {
        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => $title,
            'post_excerpt' => $title . ' summary',
            'post_status'  => 'publish',
        ]);

        update_post_meta($id, AcfFields::FIELD_PROVIDERS, array_map('strval', $providerIds));
        update_post_meta($id, AcfFields::FIELD_PRICE, '950');
        update_post_meta($id, StartDates::META_KEY, [202603]);

        $this->indexer->indexCourse($id);

        return $id;
    }

    public function test_it_builds_criteria_only_from_known_filters(): void
    {
        $criteria = $this->service->criteriaFromParams([
            'provider' => ['12'],
            'unknown'  => ['99'],
        ]);

        self::assertSame(['provider'], $criteria->activeFilterKeys());
    }

    public function test_it_returns_all_courses_for_empty_criteria(): void
    {
        $this->makeCourse('Alpha', [$this->uosd]);
        $this->makeCourse('Beta', [$this->dmu]);

        self::assertSame(2, $this->service->search(SearchCriteria::empty())->total);
    }

    public function test_separate_filters_and_together(): void
    {
        $this->makeCourse('India only', [$this->uosd]);
        $this->makeCourse('China only', [$this->dmu]);
        $this->makeCourse('Both', [$this->uosd, $this->dmu]);

        $india = get_term_by('slug', 'india', Taxonomies::LOCATION);
        self::assertInstanceOf(\WP_Term::class, $india);

        $criteria = $this->service->criteriaFromParams([
            'provider' => [(string) $this->uosd, (string) $this->dmu],
            'location' => [(string) $india->term_id],
        ]);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($this->service->search($criteria)->courses)
        );

        sort($titles);

        self::assertSame(['Both', 'India only'], $titles);
    }

    public function test_the_criteria_hook_can_transform_the_request(): void
    {
        $this->makeCourse('Alpha', [$this->uosd]);
        $this->makeCourse('Beta', [$this->dmu]);

        add_filter(
            SearchService::HOOK_CRITERIA,
            fn (SearchCriteria $criteria): SearchCriteria => $criteria->withFilter(
                \CourseDiscovery\Domain\Filter\FilterKey::fromString('provider'),
                \CourseDiscovery\Domain\Filter\FilterValues::fromInts([$this->uosd])
            )
        );

        self::assertSame(1, $this->service->search(SearchCriteria::empty())->total);
    }

    public function test_the_constraints_hook_can_add_a_restriction(): void
    {
        $this->makeCourse('Alpha', [$this->uosd]);
        $this->makeCourse('Beta', [$this->dmu]);

        add_filter(
            SearchService::HOOK_CONSTRAINTS,
            fn (ConstraintSet $set): ConstraintSet => $set->add(
                new AttributeInConstraint('provider', [$this->dmu])
            )
        );

        self::assertSame(1, $this->service->search(SearchCriteria::empty())->total);
    }

    public function test_a_hook_returning_the_wrong_type_is_ignored(): void
    {
        $this->makeCourse('Alpha', [$this->uosd]);

        add_filter(SearchService::HOOK_CRITERIA, static fn (): string => 'nonsense');
        add_filter(SearchService::HOOK_CONSTRAINTS, static fn (): int => 42);

        self::assertSame(1, $this->service->search(SearchCriteria::empty())->total);
    }

    public function test_the_keyword_term_reaches_the_query_through_the_service(): void
    {
        global $wpdb;

        $this->makeCourse('Typography', [$this->uosd]);
        $this->makeCourse('Statistics', [$this->dmu]);

        // InnoDB defers FULLTEXT index changes to an internal DML cache
        // merged into the searchable index on commit, so a MATCH ...
        // AGAINST query run inside the same still-open transaction as the
        // INSERT does not see the new rows at all (see
        // WhereClauseBuilderTest::test_a_combined_attribute_and_search_fragment_executes_correctly()
        // for the verified detail). wp-phpunit wraps every test in exactly
        // such a transaction, so without this commit the search below can
        // never see its own fixture data. Safe: prepareIndexTables()
        // truncates both index tables again at the start of every test's
        // setUp(), so nothing leaks into a later test.
        $wpdb->query('COMMIT');

        $criteria = $this->service->criteriaFromParams(['q' => 'Typography']);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($this->service->search($criteria)->courses)
        );

        self::assertSame(['Typography'], $titles);
    }

    public function test_the_keyword_term_is_applied_to_the_query_exactly_once(): void
    {
        $this->makeCourse('Graphic design fundamentals', [$this->uosd]);

        add_filter(
            SearchService::HOOK_CONSTRAINTS,
            function (ConstraintSet $set): ConstraintSet {
                // Captured here rather than counted straight from the
                // return value: this filter runs from inside search(), so a
                // test property is the only way to observe the set search()
                // built without changing SearchService itself.
                $this->capturedConstraints = $set->all();

                return $set;
            }
        );

        $criteria = $this->service->criteriaFromParams(['q' => 'design']);
        $this->service->search($criteria);

        $textConstraints = array_values(array_filter(
            $this->capturedConstraints,
            static fn (Constraint $c): bool => $c instanceof SearchTextConstraint
        ));

        // Counting matching rows cannot tell "applied once" from "applied
        // twice" -- a doubled MATCH ... AGAINST still returns the same row.
        // Counting SearchTextConstraint instances in the resulting set can.
        self::assertCount(
            1,
            $textConstraints,
            'KeywordFilter::KEY equals SearchCriteria::PARAM_TERM ("q"); the term must reach the '
                . 'constraint set exactly once, not once from the term and once from the filter loop.'
        );
    }

    public function test_the_term_never_appears_in_the_services_active_filter_keys(): void
    {
        $criteria = $this->service->criteriaFromParams(['q' => 'design']);

        self::assertSame('design', $criteria->term);
        self::assertSame(
            [],
            $criteria->activeFilterKeys(),
            'The term must stay out of the filter map at the service layer, not just SearchCriteria in isolation.'
        );
    }

    public function test_multiple_values_in_one_filter_are_ored_together(): void
    {
        $providerC = $this->makeProvider('uk');

        $this->makeCourse('On provider A', [$this->uosd]);
        $this->makeCourse('On provider B', [$this->dmu]);
        $this->makeCourse('On provider C', [$providerC]);

        $criteria = $this->service->criteriaFromParams([
            'provider' => [(string) $this->uosd, (string) $this->dmu],
        ]);

        $titles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($this->service->search($criteria)->courses)
        );

        sort($titles);

        self::assertSame(['On provider A', 'On provider B'], $titles);
    }

    public function test_the_service_uses_the_post_hook_criterias_pagination(): void
    {
        $this->makeCourse('First', [$this->uosd]);
        $this->makeCourse('Second', [$this->uosd]);
        $this->makeCourse('Third', [$this->uosd]);
        $this->makeCourse('Fourth', [$this->uosd]);

        $requested = SearchCriteria::empty()->withPagination(new Pagination(1, 2));

        $pageOneTitles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($this->service->search($requested)->courses)
        );

        add_filter(
            SearchService::HOOK_CRITERIA,
            static fn (SearchCriteria $criteria): SearchCriteria => $criteria->withPagination(new Pagination(2, 2))
        );

        $result = $this->service->search($requested);

        $pageTwoTitles = array_map(
            static fn ($c): string => $c->title,
            iterator_to_array($result->courses)
        );

        self::assertSame(2, $result->pagination->page, 'The service must read pagination from the post-hook criteria.');
        self::assertNotSame($pageOneTitles, $pageTwoTitles);
        self::assertSame(['Third', 'Fourth'], $pageTwoTitles);
    }
}
