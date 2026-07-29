<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\CourseMeta;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * CourseIndexer::relationshipIds() and
 * WpCourseRepository::relationshipIds() were exact copies that had already
 * diverged -- the indexer rejected ids <= 0, the repository did not. This
 * class pins the single, unified, STRICTER rule both now share.
 */
final class CourseMetaTest extends IntegrationTestCase
{
    private const META_KEY = 'cd_test_relationship';
    private const PRICE_KEY = 'cd_test_price';

    /**
     * "0" is ACF's "nothing selected" placeholder rather than a real post
     * id, and get_post(0) returns the CURRENT global $post instead of
     * null/false -- so silently keeping it here would let an unrelated post
     * render as, e.g., an instructor. "-1" and "" are equally not valid
     * relationship ids.
     */
    public function test_it_rejects_a_zero_a_negative_and_an_empty_string_id(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create();

        update_post_meta($postId, self::META_KEY, ['0', '-1', '', '5']);

        self::assertSame(
            [5],
            CourseMeta::relationshipIds($postId, self::META_KEY),
            '"0", "-1" and "" must all be rejected; only the genuine id must survive.'
        );
    }

    public function test_it_accepts_both_string_and_int_serialised_ids(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create();

        // ACF's admin-authored form stores numeric strings; a bare int
        // array (e.g. update_post_meta($id, ..., [12])) serialises as a
        // plain int instead. Both must be accepted.
        update_post_meta($postId, self::META_KEY, ['12', 7]);

        $ids = CourseMeta::relationshipIds($postId, self::META_KEY);
        sort($ids);

        self::assertSame([7, 12], $ids);
    }

    public function test_it_returns_an_empty_list_when_no_meta_is_stored(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create();

        self::assertSame([], CourseMeta::relationshipIds($postId, self::META_KEY));
    }

    public function test_price_minor_rounds_rather_than_truncates(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create();

        update_post_meta($postId, self::PRICE_KEY, '19.99');

        self::assertSame(
            1999,
            CourseMeta::priceMinor($postId, self::PRICE_KEY),
            'round() must be applied; a naive int cast of 19.99 * 100 truncates to 1998.'
        );
    }

    public function test_price_minor_defaults_to_zero_for_a_non_numeric_or_empty_value(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create();

        update_post_meta($postId, self::PRICE_KEY, '');

        self::assertSame(0, CourseMeta::priceMinor($postId, self::PRICE_KEY));
    }
}
