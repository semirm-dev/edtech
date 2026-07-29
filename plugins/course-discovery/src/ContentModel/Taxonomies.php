<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Course categories, and provider locations which courses derive from.
 */
final class Taxonomies
{
    public const CATEGORY = 'cd_course_category';
    public const LOCATION = 'cd_location';

    public function register(): void
    {
        register_taxonomy(self::CATEGORY, [PostTypes::COURSE], [
            'labels'            => [
                'name'          => __('Course Categories', 'course-discovery'),
                'singular_name' => __('Course Category', 'course-discovery'),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'course-category'],
        ]);

        register_taxonomy(self::LOCATION, [PostTypes::PROVIDER], [
            'labels'            => [
                'name'          => __('Locations', 'course-discovery'),
                'singular_name' => __('Location', 'course-discovery'),
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'location'],
        ]);
    }
}
