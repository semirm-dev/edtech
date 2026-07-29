<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * ACF field groups declared in PHP rather than stored in the database,
 * so a fresh clone reproduces the content model exactly.
 *
 * The group title, field labels and instructions below render verbatim on
 * the course edit screen in wp-admin, so they are wrapped in __() like any
 * other admin-facing string. This class is exercised by an INTEGRATION
 * test (tests/Integration/ContentModel/AcfFieldsTest.php), not a unit one,
 * specifically because __() requires WordPress to be loaded -- see that
 * test's docblock.
 */
final class AcfFields
{
    public const FIELD_PRICE = 'cd_course_price';
    public const FIELD_INSTRUCTORS = 'cd_course_instructors';
    public const FIELD_PROVIDERS = 'cd_course_providers';

    public function register(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group($this->definition());
    }

    /**
     * The field group definition, exposed separately from register() so
     * it can be asserted against directly in tests without ACF loaded.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key'      => 'group_course_details',
            'title'    => __('Course Details', 'course-discovery'),
            'location' => [[[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => PostTypes::COURSE,
            ]]],
            'fields'   => [
                [
                    'key'           => 'field_' . self::FIELD_PRICE,
                    'label'         => __('Price', 'course-discovery'),
                    'name'          => self::FIELD_PRICE,
                    'type'          => 'number',
                    'min'           => 0,
                    'instructions'  => __('Whole currency units.', 'course-discovery'),
                ],
                [
                    'key'           => 'field_' . self::FIELD_INSTRUCTORS,
                    'label'         => __('Instructors', 'course-discovery'),
                    'name'          => self::FIELD_INSTRUCTORS,
                    'type'          => 'relationship',
                    'post_type'     => [PostTypes::INSTRUCTOR],
                    'return_format' => 'id',
                ],
                [
                    'key'           => 'field_' . self::FIELD_PROVIDERS,
                    'label'         => __('Providers', 'course-discovery'),
                    'name'          => self::FIELD_PROVIDERS,
                    'type'          => 'relationship',
                    'post_type'     => [PostTypes::PROVIDER],
                    'return_format' => 'id',
                ],
            ],
        ];
    }
}
