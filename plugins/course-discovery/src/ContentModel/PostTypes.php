<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Registers the three content types the discovery system operates on.
 */
final class PostTypes
{
    public const COURSE = 'cd_course';
    public const INSTRUCTOR = 'cd_instructor';
    public const PROVIDER = 'cd_provider';

    public function register(): void
    {
        register_post_type(self::COURSE, [
            'labels'       => self::labels(
                __('Course', 'course-discovery'),
                __('Courses', 'course-discovery')
            ),
            'public'       => true,
            'has_archive'  => true,
            'menu_icon'    => 'dashicons-welcome-learn-more',
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'rewrite'      => ['slug' => 'courses'],
            'show_in_rest' => true,
        ]);

        register_post_type(self::INSTRUCTOR, [
            'labels'       => self::labels(
                __('Instructor', 'course-discovery'),
                __('Instructors', 'course-discovery')
            ),
            'public'       => true,
            'menu_icon'    => 'dashicons-businessperson',
            'supports'     => ['title', 'editor', 'thumbnail'],
            'rewrite'      => ['slug' => 'instructors'],
            'show_in_rest' => true,
        ]);

        register_post_type(self::PROVIDER, [
            'labels'       => self::labels(
                __('Provider', 'course-discovery'),
                __('Providers', 'course-discovery')
            ),
            'public'       => true,
            'menu_icon'    => 'dashicons-building',
            'supports'     => ['title', 'editor', 'thumbnail'],
            'rewrite'      => ['slug' => 'providers'],
            'show_in_rest' => true,
        ]);
    }

    /**
     * @param  string $singular Already-translated singular label.
     * @param  string $plural   Already-translated plural label.
     * @return array<string, string>
     */
    private static function labels(string $singular, string $plural): array
    {
        return [
            'name'          => $plural,
            'singular_name' => $singular,
            /* translators: %s: post type singular name, already translated. */
            'add_new_item'  => sprintf(__('Add New %s', 'course-discovery'), $singular),
            /* translators: %s: post type singular name, already translated. */
            'edit_item'     => sprintf(__('Edit %s', 'course-discovery'), $singular),
            /* translators: %s: post type plural name, already translated. */
            'search_items'  => sprintf(__('Search %s', 'course-discovery'), $plural),
            /* translators: %s: post type plural name, already translated, lowercased. */
            'not_found'     => sprintf(__('No %s found', 'course-discovery'), mb_strtolower($plural)),
        ];
    }
}
