<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

final class TaxonomiesTest extends IntegrationTestCase
{
    public function test_category_is_hierarchical_and_attached_to_courses(): void
    {
        self::assertTrue(taxonomy_exists(Taxonomies::CATEGORY));
        self::assertTrue(is_taxonomy_hierarchical(Taxonomies::CATEGORY));
        self::assertContains(
            Taxonomies::CATEGORY,
            get_object_taxonomies(PostTypes::COURSE)
        );
    }

    public function test_location_is_attached_to_providers_not_courses(): void
    {
        self::assertTrue(taxonomy_exists(Taxonomies::LOCATION));
        self::assertContains(Taxonomies::LOCATION, get_object_taxonomies(PostTypes::PROVIDER));
        self::assertNotContains(Taxonomies::LOCATION, get_object_taxonomies(PostTypes::COURSE));
    }

    public function test_location_has_a_clean_rewrite_slug(): void
    {
        $taxonomy = get_taxonomy(Taxonomies::LOCATION);

        self::assertNotFalse($taxonomy);
        self::assertIsArray($taxonomy->rewrite);
        self::assertSame('location', $taxonomy->rewrite['slug']);
    }

    public function test_categories_support_parent_child_nesting(): void
    {
        $parentId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Design',
        ]);
        $childId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Graphic Design',
            'parent'   => $parentId,
        ]);

        $term = get_term($childId);

        self::assertInstanceOf(\WP_Term::class, $term);
        self::assertSame($parentId, $term->parent);
    }
}
