<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Index;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Attribute;
use CourseDiscovery\Index\MigrationRunner;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Query\WhereClauseBuilder;
use CourseDiscovery\Query\WpCourseRepository;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class CourseIndexerTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private Schema $schema;
    private CourseIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);

        // Runs migrations and truncates. Also removes wp-phpunit's
        // temporary-table filter, without which MariaDB rejects the
        // FULLTEXT index. See the trait's docblock.
        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, $this->schema);
    }

    /**
     * @param list<int> $providerIds
     */
    private function makeCourse(array $providerIds = [], string $title = 'Test Course'): int
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => $title,
            'post_excerpt' => 'Short blurb.',
            'post_content' => 'Long body text.',
            'post_status'  => 'publish',
        ]);

        // ACF stores relationship values as numeric STRINGS, not ints.
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, array_map('strval', $providerIds));
        update_post_meta($courseId, AcfFields::FIELD_PRICE, '950');
        update_post_meta($courseId, StartDates::META_KEY, [202603, 202601]);

        return $courseId;
    }

    private function makeProvider(string $locationSlug): int
    {
        /** @var int $providerId */
        $providerId = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_status' => 'publish',
        ]);

        wp_set_object_terms($providerId, $locationSlug, Taxonomies::LOCATION);

        return $providerId;
    }

    /**
     * @return list<int>
     */
    private function attributeValues(int $courseId, Attribute $attribute): array
    {
        global $wpdb;

        $table = $this->schema->attributeLookupTable();

        /** @var list<string> $values */
        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT value_id FROM {$table} WHERE course_id = %d AND attribute = %s ORDER BY value_id",
                $courseId,
                $attribute->value
            )
        );

        return array_map('intval', $values);
    }

    private function indexRowExists(int $courseId): bool
    {
        global $wpdb;

        $table = $this->schema->metaLookupTable();

        return $wpdb->get_var(
            $wpdb->prepare("SELECT course_id FROM {$table} WHERE course_id = %d", $courseId)
        ) !== null;
    }

    /**
     * The location taxonomy is non-hierarchical, so makeProvider() creates
     * the term the first time a given slug is used. This looks it back up
     * so tests can assert against its real id rather than a guessed one.
     */
    private function locationTermId(string $slug): int
    {
        $term = get_term_by('slug', $slug, Taxonomies::LOCATION);

        self::assertInstanceOf(\WP_Term::class, $term, "Expected a '{$slug}' location term to exist.");

        return (int) $term->term_id;
    }

    public function test_it_writes_one_index_row_per_course(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse();
        $this->indexer->indexCourse($courseId);

        $table = $this->schema->metaLookupTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE course_id = %d", $courseId), ARRAY_A);

        self::assertIsArray($row);

        /** @var mixed $earliestStartYm */
        $earliestStartYm = $row['earliest_start_ym'];
        /** @var mixed $priceMinor */
        $priceMinor = $row['price_minor'];
        /** @var mixed $searchText */
        $searchText = $row['search_text'];

        self::assertSame(
            202601,
            is_numeric($earliestStartYm) ? (int) $earliestStartYm : null,
            'Earliest date, not first stored.'
        );
        self::assertSame(
            95000,
            is_numeric($priceMinor) ? (int) $priceMinor : null,
            'Price stored in minor units.'
        );
        self::assertStringContainsString('Short blurb', is_string($searchText) ? $searchText : '');
        self::assertStringContainsString('Long body text', is_string($searchText) ? $searchText : '');
    }

    public function test_it_stores_the_post_title_for_title_ordering(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse([], 'Graphic Design Foundation');
        $this->indexer->indexCourse($courseId);

        $table = $this->schema->metaLookupTable();

        $title = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$table} WHERE course_id = %d", $courseId));

        self::assertSame('Graphic Design Foundation', $title);
    }

    public function test_it_indexes_providers_from_acf_string_values(): void
    {
        $provider = $this->makeProvider('india');
        $courseId = $this->makeCourse([$provider]);

        $this->indexer->indexCourse($courseId);

        self::assertSame([$provider], $this->attributeValues($courseId, Attribute::Provider));
    }

    public function test_it_derives_locations_from_providers(): void
    {
        $india = $this->makeProvider('india');
        $china = $this->makeProvider('china');
        $courseId = $this->makeCourse([$india, $china]);

        $this->indexer->indexCourse($courseId);

        $locations = $this->attributeValues($courseId, Attribute::Location);

        $expected = [$this->locationTermId('india'), $this->locationTermId('china')];
        sort($expected);

        self::assertSame($expected, $locations, 'A course inherits a location from every provider.');
    }

    public function test_it_deduplicates_a_location_shared_by_two_providers(): void
    {
        $providerA = $this->makeProvider('india');
        $providerB = $this->makeProvider('india');
        $courseId = $this->makeCourse([$providerA, $providerB]);

        $this->indexer->indexCourse($courseId);

        $locations = $this->attributeValues($courseId, Attribute::Location);

        self::assertSame(
            [$this->locationTermId('india')],
            $locations,
            'Two providers in the same location must yield exactly one location row, not one per provider.'
        );
    }

    public function test_a_provider_without_a_location_does_not_blank_other_providers_locations(): void
    {
        $withLocation = $this->makeProvider('india');

        /** @var int $withoutLocation */
        $withoutLocation = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_status' => 'publish',
        ]);
        // Deliberately no wp_set_object_terms() call: this provider has no
        // location term at all.

        $courseId = $this->makeCourse([$withLocation, $withoutLocation]);
        $this->indexer->indexCourse($courseId);

        self::assertSame(
            [$this->locationTermId('india')],
            $this->attributeValues($courseId, Attribute::Location),
            'A provider with no location must contribute nothing, without blanking the others.'
        );
    }

    public function test_it_indexes_a_category_and_all_its_ancestors(): void
    {
        // A single-level parent/child chain cannot distinguish get_ancestors()
        // from a lookup of just the immediate parent, so this goes three
        // levels deep: grandparent -> parent -> child.
        /** @var int $grandparent */
        $grandparent = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Arts']);
        /** @var int $parent */
        $parent = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Design',
            'parent'   => $grandparent,
        ]);
        /** @var int $child */
        $child = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Graphic Design',
            'parent'   => $parent,
        ]);

        $courseId = $this->makeCourse();
        wp_set_object_terms($courseId, [$child], Taxonomies::CATEGORY);

        $this->indexer->indexCourse($courseId);

        $categories = $this->attributeValues($courseId, Attribute::Category);

        self::assertCount(3, $categories, 'Only the category and its ancestors, nothing else.');
        self::assertContains($child, $categories);
        self::assertContains($parent, $categories, 'Selecting a parent must match a child-filed course.');
        self::assertContains($grandparent, $categories, 'Selecting a grandparent must match too, not just the immediate parent.');
    }

    public function test_it_indexes_every_start_date(): void
    {
        $courseId = $this->makeCourse();
        $this->indexer->indexCourse($courseId);

        self::assertSame([202601, 202603], $this->attributeValues($courseId, Attribute::Start));
    }

    public function test_reindexing_is_idempotent(): void
    {
        $provider = $this->makeProvider('india');
        $courseId = $this->makeCourse([$provider]);

        $this->indexer->indexCourse($courseId);
        $this->indexer->indexCourse($courseId);

        self::assertSame([$provider], $this->attributeValues($courseId, Attribute::Provider));
    }

    public function test_reindexing_removes_rows_that_no_longer_apply(): void
    {
        $provider = $this->makeProvider('india');
        $courseId = $this->makeCourse([$provider]);
        $this->indexer->indexCourse($courseId);

        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, []);
        $this->indexer->indexCourse($courseId);

        self::assertSame([], $this->attributeValues($courseId, Attribute::Provider), 'Stale rows must be swept.');
    }

    public function test_removing_a_course_clears_both_tables(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse([$this->makeProvider('india')]);
        $this->indexer->indexCourse($courseId);

        $this->indexer->removeCourse($courseId);

        $indexTable = $this->schema->metaLookupTable();

        self::assertSame([], $this->attributeValues($courseId, Attribute::Provider));
        self::assertNull(
            $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$indexTable} WHERE course_id = %d", $courseId))
        );
    }

    public function test_it_finds_the_courses_attached_to_a_provider(): void
    {
        $provider = $this->makeProvider('india');
        $a = $this->makeCourse([$provider], 'A');
        $b = $this->makeCourse([$provider], 'B');
        $this->makeCourse([], 'Unrelated');

        $found = $this->indexer->coursesForProvider($provider);

        sort($found);
        $expected = [$a, $b];
        sort($expected);

        self::assertSame($expected, $found);
    }

    /**
     * Pins the fix for the false-negative on int-serialised provider meta:
     * `update_post_meta($id, ..., [$providerId])` with a bare int (no
     * strval()) serialises as `i:12;`, not the `s:2:"12";` string form that
     * makeCourse()'s array_map('strval', ...) produces. A query matching
     * only the string form would silently miss this course.
     */
    public function test_it_finds_courses_with_integer_serialised_provider_meta(): void
    {
        $provider = $this->makeProvider('india');
        $courseId = $this->makeCourse();

        // Deliberately bypasses makeCourse()'s strval() mapping.
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [$provider]);

        $found = $this->indexer->coursesForProvider($provider);

        self::assertSame([$courseId], $found);
    }

    public function test_it_removes_an_indexed_course_when_it_becomes_a_draft(): void
    {
        $courseId = $this->makeCourse([$this->makeProvider('india')]);
        $this->indexer->indexCourse($courseId);

        self::assertTrue($this->indexRowExists($courseId));

        wp_update_post(['ID' => $courseId, 'post_status' => 'draft']);
        $this->indexer->indexCourse($courseId);

        self::assertFalse($this->indexRowExists($courseId), 'A course set to draft must be removed from the index.');
        self::assertSame([], $this->attributeValues($courseId, Attribute::Provider));
    }

    public function test_it_indexes_a_future_dated_course_once_published(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'future',
            'post_date'   => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
        ]);

        $this->indexer->indexCourse($courseId);

        self::assertFalse($this->indexRowExists($courseId), 'A future-dated course must not be indexed before it publishes.');

        // wp_update_post() merges unset fields from the EXISTING row, so
        // omitting post_date_gmt here would keep the old future timestamp
        // and WP would silently force the status straight back to 'future'.
        // Both post_date and post_date_gmt must move out of the future.
        $now = gmdate('Y-m-d H:i:s');
        wp_update_post(['ID' => $courseId, 'post_status' => 'publish', 'post_date' => $now, 'post_date_gmt' => $now]);
        $this->indexer->indexCourse($courseId);

        self::assertTrue($this->indexRowExists($courseId), 'A course must be indexed once it is published.');
    }

    public function test_it_removes_index_rows_when_a_post_stops_being_a_course(): void
    {
        $courseId = $this->makeCourse([$this->makeProvider('india')]);
        $this->indexer->indexCourse($courseId);

        self::assertTrue($this->indexRowExists($courseId));

        wp_update_post(['ID' => $courseId, 'post_type' => 'page']);
        $this->indexer->indexCourse($courseId);

        self::assertFalse(
            $this->indexRowExists($courseId),
            'A post whose type changed away from cd_course must have its stale index row removed.'
        );
        self::assertSame([], $this->attributeValues($courseId, Attribute::Provider), 'Its attribute rows must be swept too.');
    }

    public function test_indexing_a_non_course_post_is_a_no_op(): void
    {
        /** @var int $pageId */
        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $unrelatedCourse = $this->makeCourse([$this->makeProvider('india')]);
        $this->indexer->indexCourse($unrelatedCourse);

        $this->indexer->indexCourse($pageId);

        self::assertFalse($this->indexRowExists($pageId));
        self::assertTrue(
            $this->indexRowExists($unrelatedCourse),
            'Indexing an unrelated non-course id must not disturb existing index rows.'
        );
        self::assertNotSame(
            [],
            $this->attributeValues($unrelatedCourse, Attribute::Provider),
            'Indexing an unrelated non-course id must not disturb existing attribute rows.'
        );
    }

    public function test_price_is_rounded_and_defaults_to_zero(): void
    {
        global $wpdb;

        $table = $this->schema->metaLookupTable();

        $withPrice = $this->makeCourse();
        update_post_meta($withPrice, AcfFields::FIELD_PRICE, '19.99');
        $this->indexer->indexCourse($withPrice);

        /** @var mixed $rounded */
        $rounded = $wpdb->get_var($wpdb->prepare("SELECT price_minor FROM {$table} WHERE course_id = %d", $withPrice));

        self::assertSame(
            1999,
            is_numeric($rounded) ? (int) $rounded : null,
            'round() must be applied; a naive int cast of 19.99 * 100 truncates to 1998.'
        );

        $withEmptyPrice = $this->makeCourse();
        update_post_meta($withEmptyPrice, AcfFields::FIELD_PRICE, '');
        $this->indexer->indexCourse($withEmptyPrice);

        /** @var mixed $defaulted */
        $defaulted = $wpdb->get_var(
            $wpdb->prepare("SELECT price_minor FROM {$table} WHERE course_id = %d", $withEmptyPrice)
        );

        self::assertSame(
            0,
            is_numeric($defaulted) ? (int) $defaulted : null,
            'A non-numeric/empty price must default to zero rather than erroring.'
        );
    }

    /**
     * `course_discovery/indexed_course` fires so third-party code
     * can add its own attribute rows, but every attribute-writing method used to be
     * private -- a listener had no way to actually do it. This proves the
     * whole extension path end to end: a listener registers on the action,
     * calls the PUBLIC addAttributeValues(), and the value is then readable
     * back through the exact same public API a real search would use
     * (WpCourseRepository::attributeValues()).
     *
     * Verified by hand to fail for the right reason: making
     * addAttributeValues() private again turns this test's call into a fatal
     * "Call to private method" error rather than a silent pass.
     */
    public function test_a_listener_can_add_custom_attribute_rows_via_the_public_api_and_read_them_back(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse();

        $listener = static function (int $indexedCourseId, CourseIndexer $indexer) use ($courseId): void {
            if ($indexedCourseId === $courseId) {
                $indexer->addAttributeValues($indexedCourseId, 'skill_level', [3]);
            }
        };

        add_action('course_discovery/indexed_course', $listener, 10, 2);

        try {
            $this->indexer->indexCourse($courseId);
        } finally {
            remove_action('course_discovery/indexed_course', $listener, 10);
        }

        $repository = new WpCourseRepository($wpdb, $this->schema, new WhereClauseBuilder($wpdb, $this->schema));

        self::assertSame(
            [3],
            $repository->attributeValues('skill_level'),
            'A listener must be able to add and read back its own custom attribute through the public API.'
        );
    }

    /**
     * Companion to the test above: proves addAttributeValues() is idempotent
     * under the exact conditions it is actually used in -- called again on
     * every reindex, since a listener re-adds its attribute unconditionally
     * rather than checking whether it already ran.
     */
    public function test_adding_the_same_custom_attribute_values_twice_does_not_duplicate_rows(): void
    {
        global $wpdb;

        $courseId = $this->makeCourse();
        $this->indexer->indexCourse($courseId);

        $this->indexer->addAttributeValues($courseId, 'skill_level', [3, 7]);
        $this->indexer->addAttributeValues($courseId, 'skill_level', [3, 7]);

        $table = $this->schema->attributeLookupTable();

        /** @var list<string> $values */
        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT value_id FROM {$table} WHERE course_id = %d AND attribute = %s ORDER BY value_id",
                $courseId,
                'skill_level'
            )
        );

        self::assertSame(['3', '7'], $values, 'Re-adding the same values must not duplicate rows.');
    }

    /**
     * addAttributeValues() must only ever touch rows for the attribute it was
     * called with -- never a blanket delete of every attribute row for the
     * course -- or it would silently wipe out the indexer's own built-in
     * attributes (or another listener's custom attribute) written moments earlier.
     */
    public function test_adding_a_custom_attribute_does_not_disturb_a_built_in_attributes_rows(): void
    {
        $provider = $this->makeProvider('india');
        $courseId = $this->makeCourse([$provider]);
        $this->indexer->indexCourse($courseId);

        self::assertSame([$provider], $this->attributeValues($courseId, Attribute::Provider), 'Sanity check on the baseline.');

        $this->indexer->addAttributeValues($courseId, 'skill_level', [3]);

        self::assertSame(
            [$provider],
            $this->attributeValues($courseId, Attribute::Provider),
            'Adding a custom attribute must not disturb the built-in provider attribute rows.'
        );
    }

    public function test_a_course_with_no_start_dates_leaves_earliest_start_null(): void
    {
        global $wpdb;

        // Deliberately built without makeCourse(), which always sets
        // StartDates::META_KEY -- every other test course has dates, so
        // this path is otherwise entirely unexercised.
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);

        $this->indexer->indexCourse($courseId);

        $table = $this->schema->metaLookupTable();

        $earliest = $wpdb->get_var(
            $wpdb->prepare("SELECT earliest_start_ym FROM {$table} WHERE course_id = %d", $courseId)
        );

        self::assertNull($earliest, 'A course with no start dates must leave earliest_start_ym as SQL NULL.');
    }
}
