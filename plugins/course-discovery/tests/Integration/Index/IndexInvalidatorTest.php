<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Index;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Attribute;
use CourseDiscovery\Index\IndexInvalidator;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class IndexInvalidatorTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private Schema $schema;
    private CourseIndexer $indexer;
    private IndexInvalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);
        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, $this->schema);
        $this->invalidator = new IndexInvalidator($this->indexer);
        $this->invalidator->register();
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

    private function searchText(int $courseId): ?string
    {
        global $wpdb;

        $table = $this->schema->metaLookupTable();

        /** @var string|null $value */
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT search_text FROM {$table} WHERE course_id = %d", $courseId)
        );

        return $value;
    }

    /**
     * The location taxonomy is non-hierarchical, so wp_set_object_terms()
     * creates the term the first time a given slug is used. This looks it
     * back up so tests can assert against its real id rather than a guessed
     * one.
     */
    private function locationTermId(string $slug): int
    {
        $term = get_term_by('slug', $slug, Taxonomies::LOCATION);

        self::assertInstanceOf(\WP_Term::class, $term, "Expected a '{$slug}' location term to exist.");

        return (int) $term->term_id;
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

    public function test_saving_a_course_indexes_it(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
            'post_title'  => 'Auto indexed',
        ]);

        global $wpdb;

        $table = $this->schema->metaLookupTable();

        self::assertNotNull(
            $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$table} WHERE course_id = %d", $courseId)),
            'save_post must trigger indexing.'
        );
    }

    public function test_changing_a_provider_location_reindexes_its_courses(): void
    {
        /** @var int $providerId */
        $providerId = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_status' => 'publish',
        ]);
        wp_set_object_terms($providerId, 'india', Taxonomies::LOCATION);

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerId]);
        $this->indexer->indexCourse($courseId);

        $before = $this->attributeValues($courseId, Attribute::Location);
        self::assertCount(1, $before);

        // The provider moves. The course itself is never touched.
        wp_set_object_terms($providerId, 'china', Taxonomies::LOCATION);

        $after = $this->attributeValues($courseId, Attribute::Location);

        self::assertSame(
            [$this->locationTermId('china')],
            $after,
            'A provider location change must propagate to its courses, to the NEW location specifically.'
        );
    }

    /**
     * onPostDeleted() previously issued two DELETEs against the
     * index tables for EVERY deleted post site-wide, regardless of type --
     * attachments, revisions, ordinary pages, anything. Captures the actual
     * queries executed by wp_delete_post() for an unrelated 'post' and
     * asserts neither index table is touched at all, rather than merely
     * asserting the (already-empty) tables stay empty, which would pass
     * even without the guard.
     */
    public function test_deleting_an_unrelated_post_type_never_touches_the_index_tables(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create(['post_type' => 'post', 'post_status' => 'publish']);

        global $wpdb;

        $indexTable = $this->schema->metaLookupTable();
        $attributeTable = $this->schema->attributeLookupTable();

        /** @var list<string> $deleteQueries */
        $deleteQueries = [];
        $capture = static function (string $query) use (&$deleteQueries, $indexTable, $attributeTable): string {
            if (str_contains($query, $indexTable) || str_contains($query, $attributeTable)) {
                $deleteQueries[] = $query;
            }

            return $query;
        };

        add_filter('query', $capture);

        try {
            wp_delete_post($postId, true);
        } finally {
            remove_filter('query', $capture);
        }

        self::assertSame(
            [],
            $deleteQueries,
            'Deleting a non-course post must never issue a query against either index table.'
        );
    }

    public function test_deleting_a_course_clears_its_rows(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);

        wp_delete_post($courseId, true);

        global $wpdb;

        $table = $this->schema->metaLookupTable();

        self::assertNull(
            $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$table} WHERE course_id = %d", $courseId))
        );
    }

    public function test_unpublishing_a_course_removes_it_from_the_index(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);

        wp_update_post(['ID' => $courseId, 'post_status' => 'draft']);

        global $wpdb;

        $table = $this->schema->metaLookupTable();

        self::assertNull(
            $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$table} WHERE course_id = %d", $courseId)),
            'A draft course must not appear in search results.'
        );
    }

    /**
     * Critical: `save_post_cd_course` fires BEFORE the generic
     * `save_post` action, and ACF hooks the GENERIC action (priority 10) to
     * write its own field values. An invalidator listening on
     * `save_post_cd_course` therefore reads the OLD provider value on every
     * admin edit -- exactly the silent-staleness bug this class exists to
     * prevent.
     *
     * This test recreates that ordering for real: it registers its own
     * `save_post` callback to stand in for ACF's real
     * `ACF_Form_Post::save_post()` (also hooked on the generic `save_post`
     * at priority 10 in form-post.php), writing the NEW provider value
     * exactly where ACF would. `wp_update_post()` then drives the same
     * hook sequence a real admin save does. Verified by hand: this test
     * FAILS if IndexInvalidator listens on `save_post_cd_course` (it
     * indexes the OLD provider, India) and PASSES on `wp_after_insert_post`
     * (it indexes the NEW provider, China).
     */
    public function test_acf_meta_written_during_save_post_is_seen_by_the_indexer(): void
    {
        $india = $this->makeProvider('india');
        $china = $this->makeProvider('china');

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $india]);
        $this->indexer->indexCourse($courseId);

        self::assertSame(
            [$this->locationTermId('india')],
            $this->attributeValues($courseId, Attribute::Location),
            'Sanity check on the baseline before the simulated admin edit.'
        );

        // Stands in for ACF's real form-post.php save_post() callback,
        // which is hooked on the GENERIC `save_post` action at priority 10
        // and writes the submitted field values there.
        $simulatedAcfSave = static function (int $postId) use ($china): void {
            update_post_meta($postId, AcfFields::FIELD_PROVIDERS, [(string) $china]);
        };
        add_action('save_post', $simulatedAcfSave, 10, 1);

        try {
            wp_update_post(['ID' => $courseId, 'post_title' => 'Edited by an admin']);
        } finally {
            remove_action('save_post', $simulatedAcfSave, 10);
        }

        self::assertSame(
            [$this->locationTermId('china')],
            $this->attributeValues($courseId, Attribute::Location),
            'The indexer must see the NEW provider written during save_post, not the stale value read before it ran.'
        );
    }

    /**
     * set_object_terms fires on every term write with no dirty
     * check, including a no-op re-assignment (trash/untrash, quick edit).
     * Pinned by tampering with the index row directly and asserting it
     * survives an identical re-assignment untouched -- if indexCourse() ran
     * again it would overwrite search_text from the real post content.
     *
     * Verified by hand: removing the dirty check in onTermsSet() makes this
     * test FAIL (the sentinel gets overwritten); restoring it makes it PASS.
     */
    public function test_reassigning_the_same_provider_location_does_not_reindex(): void
    {
        $providerId = $this->makeProvider('india');

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerId]);
        $this->indexer->indexCourse($courseId);

        global $wpdb;
        $table = $this->schema->metaLookupTable();
        $sentinel = 'SENTINEL-UNCHANGED';
        $wpdb->update($table, ['search_text' => $sentinel], ['course_id' => $courseId]);

        // Same taxonomy, same slug: not a real change. This is what a
        // trash/untrash round-trip or a quick-edit save without touching
        // the location field looks like from set_object_terms' point of
        // view.
        wp_set_object_terms($providerId, 'india', Taxonomies::LOCATION);

        self::assertSame(
            $sentinel,
            $this->searchText($courseId),
            'Re-assigning an unchanged term set must not trigger a reindex.'
        );
    }

    public function test_terms_set_ignores_an_irrelevant_taxonomy(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        $this->indexer->indexCourse($courseId);

        global $wpdb;
        $table = $this->schema->metaLookupTable();
        $sentinel = 'SENTINEL-UNTOUCHED';
        $wpdb->update($table, ['search_text' => $sentinel], ['course_id' => $courseId]);

        $this->invalidator->onTermsSet($courseId, ['tag'], [1], 'post_tag', false, []);

        self::assertSame(
            $sentinel,
            $this->searchText($courseId),
            'An unrelated taxonomy write must not trigger a reindex.'
        );
    }

    public function test_terms_set_ignores_a_non_post_object_id(): void
    {
        $fakeObjectId = 999999999;

        $this->invalidator->onTermsSet($fakeObjectId, [1], [1], Taxonomies::CATEGORY, false, []);

        global $wpdb;
        $table = $this->schema->metaLookupTable();

        self::assertNull(
            $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$table} WHERE course_id = %d", $fakeObjectId)),
            'An object id with no matching post must not produce an index row.'
        );
    }

    public function test_deleting_a_provider_reindexes_its_courses(): void
    {
        $providerId = $this->makeProvider('india');

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerId]);
        $this->indexer->indexCourse($courseId);

        self::assertCount(1, $this->attributeValues($courseId, Attribute::Location));

        wp_delete_post($providerId, true);

        self::assertSame(
            [],
            $this->attributeValues($courseId, Attribute::Location),
            'Deleting a provider must clear the location it contributed to its courses.'
        );
    }

    /**
     * WordPress does not strip a post's term relationships when it is
     * merely trashed (only on permanent delete) -- so unlike deletion, a
     * trashed provider's derived location is unchanged by a reindex. What
     * this test proves is the part that IS fixable at this layer: that
     * trashing actually triggers a reindex pass at all, where previously
     * nothing was hooked to trash whatsoever.
     */
    public function test_trashing_a_provider_triggers_a_reindex_of_its_courses(): void
    {
        $providerId = $this->makeProvider('india');

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerId]);
        $this->indexer->indexCourse($courseId);

        global $wpdb;
        $table = $this->schema->metaLookupTable();
        $wpdb->update($table, ['search_text' => 'STALE-BEFORE-TRASH'], ['course_id' => $courseId]);

        wp_trash_post($providerId);

        self::assertNotSame(
            'STALE-BEFORE-TRASH',
            $this->searchText($courseId),
            'Trashing a provider must trigger a reindex of its courses.'
        );
    }

    public function test_reparenting_a_category_reindexes_courses_filed_under_it(): void
    {
        /** @var int $programming */
        $programming = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Programming']);
        /** @var int $python */
        $python = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Python']);

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        wp_set_object_terms($courseId, [$python], Taxonomies::CATEGORY);
        $this->indexer->indexCourse($courseId);

        self::assertSame([$python], $this->attributeValues($courseId, Attribute::Category), 'Python has no parent yet.');

        // Python moves under Programming. The course itself is never touched.
        wp_update_term($python, Taxonomies::CATEGORY, ['parent' => $programming]);

        $expected = [$python, $programming];
        sort($expected);

        self::assertSame(
            $expected,
            $this->attributeValues($courseId, Attribute::Category),
            'Re-parenting a category must reindex its courses so the new ancestor is picked up.'
        );
    }

    public function test_reparenting_a_category_also_reindexes_courses_filed_under_its_descendants(): void
    {
        /** @var int $programming */
        $programming = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Programming']);
        /** @var int $python */
        $python = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Python']);
        /** @var int $django */
        $django = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Django',
            'parent'   => $python,
        ]);

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        wp_set_object_terms($courseId, [$django], Taxonomies::CATEGORY);
        $this->indexer->indexCourse($courseId);

        $baseline = [$django, $python];
        sort($baseline);
        self::assertSame($baseline, $this->attributeValues($courseId, Attribute::Category));

        // Python (an ancestor of Django, not the course's own term) moves
        // under Programming.
        wp_update_term($python, Taxonomies::CATEGORY, ['parent' => $programming]);

        $expected = [$django, $python, $programming];
        sort($expected);

        self::assertSame(
            $expected,
            $this->attributeValues($courseId, Attribute::Category),
            'A course filed under a descendant of the reparented term must also be reindexed.'
        );
    }

    public function test_deleting_a_category_term_clears_it_from_courses_that_had_it(): void
    {
        /** @var int $categoryId */
        $categoryId = self::factory()->term->create(['taxonomy' => Taxonomies::CATEGORY, 'name' => 'Deprecated']);

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        wp_set_object_terms($courseId, [$categoryId], Taxonomies::CATEGORY);
        $this->indexer->indexCourse($courseId);

        self::assertSame([$categoryId], $this->attributeValues($courseId, Attribute::Category));

        wp_delete_term($categoryId, Taxonomies::CATEGORY);

        self::assertSame(
            [],
            $this->attributeValues($courseId, Attribute::Category),
            'Deleting a category term must drop it from every course it was tagging.'
        );
    }

    public function test_deleting_a_location_term_clears_it_from_courses_of_its_providers(): void
    {
        $providerId = $this->makeProvider('temporary-location');
        $locationTermId = $this->locationTermId('temporary-location');

        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'   => PostTypes::COURSE,
            'post_status' => 'publish',
        ]);
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerId]);
        $this->indexer->indexCourse($courseId);

        self::assertSame([$locationTermId], $this->attributeValues($courseId, Attribute::Location));

        wp_delete_term($locationTermId, Taxonomies::LOCATION);

        self::assertSame(
            [],
            $this->attributeValues($courseId, Attribute::Location),
            'Deleting a location term must drop it from every course whose provider carried it.'
        );
    }
}
