<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Course;
use CourseDiscovery\Domain\CourseId;
use CourseDiscovery\Domain\Money;
use CourseDiscovery\Domain\SinglePrice;
use CourseDiscovery\Domain\StartDateCollection;
use CourseDiscovery\Frontend\AttributeLabels;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * The id-to-name step the results list needs and the domain cannot do:
 * Course carries provider post ids and location term ids, never their
 * titles, because Domain/ may not touch WordPress.
 */
final class AttributeLabelsTest extends IntegrationTestCase
{
    private AttributeLabels $labels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->labels = new AttributeLabels();
    }

    public function test_it_resolves_provider_titles_and_location_names(): void
    {
        $providerId = $this->makeProvider('Coventry University');
        $locationId = $this->makeLocation('Coventry');

        $map = $this->labels->forPage([$this->makeCourse([$providerId], [$locationId])]);

        self::assertSame('Coventry University', $map->provider($providerId));
        self::assertSame('Coventry', $map->location($locationId));
    }

    /**
     * The whole point of the class: two queries for a page, however many
     * courses are on it and however many ids they share.
     */
    public function test_it_resolves_every_id_across_the_whole_page(): void
    {
        $leeds = $this->makeProvider('University of Leeds');
        $dmu = $this->makeProvider('De Montfort University');
        $leedsLocation = $this->makeLocation('Leeds');
        $leicester = $this->makeLocation('Leicester');

        $map = $this->labels->forPage([
            $this->makeCourse([$leeds], [$leedsLocation]),
            $this->makeCourse([$dmu, $leeds], [$leicester, $leedsLocation]),
        ]);

        self::assertSame('University of Leeds', $map->provider($leeds));
        self::assertSame('De Montfort University', $map->provider($dmu));
        self::assertSame('Leeds', $map->location($leedsLocation));
        self::assertSame('Leicester', $map->location($leicester));
    }

    /**
     * WP_Query IGNORES an empty `post__in` rather than matching nothing, and
     * get_terms() treats an empty `include` the same way -- so the naive
     * implementation of this class answers "no ids" by fetching every
     * provider and every location on the site. This is the test that pins
     * the guard.
     */
    public function test_a_page_with_no_attribute_ids_resolves_nothing(): void
    {
        $providerId = $this->makeProvider('Coventry University');
        $locationId = $this->makeLocation('Coventry');

        $map = $this->labels->forPage([$this->makeCourse([], [])]);

        self::assertNull(
            $map->provider($providerId),
            'An empty id list must resolve to nothing, not to every provider on the site.'
        );
        self::assertNull($map->location($locationId));
    }

    public function test_an_empty_page_resolves_nothing(): void
    {
        $providerId = $this->makeProvider('Coventry University');

        self::assertNull($this->labels->forPage([])->provider($providerId));
    }

    /**
     * The attribute lookup is a projection and can go stale relative to
     * wp_posts, so an id reaching here may name a provider that has since
     * been unpublished. Same rule the applied-filter chips already follow:
     * an unresolvable value is dropped, never rendered as a raw id.
     */
    public function test_an_unpublished_provider_does_not_resolve(): void
    {
        $providerId = $this->makeProvider('Coventry University');
        wp_update_post(['ID' => $providerId, 'post_status' => 'draft']);

        $map = $this->labels->forPage([$this->makeCourse([$providerId], [])]);

        self::assertNull($map->provider($providerId));
    }

    public function test_an_id_naming_nothing_at_all_does_not_resolve(): void
    {
        $map = $this->labels->forPage([$this->makeCourse([999999], [999999])]);

        self::assertNull($map->provider(999999));
        self::assertNull($map->location(999999));
    }

    /**
     * Post ids and term ids are independent auto-increment sequences, so a
     * provider and a location can share a number. Each side of the map must
     * be looked up in its own space.
     */
    public function test_provider_ids_and_location_ids_do_not_leak_into_each_other(): void
    {
        $providerId = $this->makeProvider('Coventry University');
        $locationId = $this->makeLocation('Coventry');

        $map = $this->labels->forPage([$this->makeCourse([$providerId], [$locationId])]);

        self::assertNull(
            $map->location($providerId),
            'A provider post id must not resolve as a location term name.'
        );
        self::assertNull($map->provider($locationId));
    }

    private function makeProvider(string $title): int
    {
        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);

        return $id;
    }

    private function makeLocation(string $name): int
    {
        /** @var int $id */
        $id = self::factory()->term->create([
            'taxonomy' => Taxonomies::LOCATION,
            'name'     => $name,
        ]);

        return $id;
    }

    /**
     * @param list<int> $providerIds
     * @param list<int> $locationIds
     */
    private function makeCourse(array $providerIds, array $locationIds): Course
    {
        return new Course(
            id: CourseId::fromInt(1),
            title: 'Graphic Design Foundation',
            shortDescription: 'Learn the fundamentals of visual communication.',
            longDescription: 'Full description.',
            pricing: new SinglePrice(Money::fromMinor(95000, 'GBP')),
            startDates: StartDateCollection::fromSortKeys([]),
            providerIds: $providerIds,
            instructorIds: [],
            categoryIds: [],
            locationIds: $locationIds,
        );
    }
}
