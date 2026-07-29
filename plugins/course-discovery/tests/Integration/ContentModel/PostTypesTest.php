<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

final class PostTypesTest extends IntegrationTestCase
{
    public function test_all_three_post_types_are_registered(): void
    {
        self::assertTrue(post_type_exists(PostTypes::COURSE));
        self::assertTrue(post_type_exists(PostTypes::INSTRUCTOR));
        self::assertTrue(post_type_exists(PostTypes::PROVIDER));
    }

    public function test_course_is_public_and_supports_editor_and_excerpt(): void
    {
        $courseType = get_post_type_object(PostTypes::COURSE);

        self::assertNotNull($courseType);
        self::assertTrue($courseType->public);
        self::assertTrue(post_type_supports(PostTypes::COURSE, 'editor'));
        self::assertTrue(post_type_supports(PostTypes::COURSE, 'excerpt'));
    }

    public function test_a_course_can_be_created_and_read_back(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => 'Graphic Design Foundation',
            'post_excerpt' => 'A short description.',
        ]);

        self::assertSame('Graphic Design Foundation', get_the_title($courseId));

        $course = get_post($courseId);
        self::assertNotNull($course);
        self::assertSame('A short description.', $course->post_excerpt);
    }
}
