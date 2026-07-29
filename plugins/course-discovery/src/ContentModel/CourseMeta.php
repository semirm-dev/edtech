<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Reads a course's ACF-authored meta into typed values.
 *
 * CourseIndexer and WpCourseRepository each carried their own copy
 * of this exact logic, and the two copies had already diverged: the indexer
 * rejected relationship ids <= 0, the repository did not. Meta containing a
 * stray "0" (ACF's "nothing selected" placeholder in some field states) then
 * surfaced as Course::instructorIds() containing 0 -- and templates
 * call get_post(0) for each instructor id, which returns the
 * CURRENT global $post rather than null/false, silently rendering the
 * page's own title as an "instructor". This is the THIRD time this exact
 * rule was duplicated (indexer, repository, and now unified here); no
 * further copies should exist -- both call sites now delegate here instead.
 */
final class CourseMeta
{
    /**
     * ACF relationship values are stored as arrays of numeric STRINGS (the
     * admin-authored form, e.g. `s:2:"12";`) or, less commonly, plain INTs
     * (e.g. written via `update_post_meta($id, ..., [12])` or `wp post meta
     * update --format=json`, i.e. `i:12;`). Both are accepted; filtering
     * with is_int() alone would silently drop every admin-authored value.
     *
     * Applies the STRICTER of the two previously-diverged rules: any id
     * <= 0 is rejected. A relationship field storing "0" is not a real post
     * id, and get_post(0) returns the CURRENT global $post rather than
     * null/false -- silently keeping a "0" here would let an unrelated post
     * render as, e.g., an instructor (see the class docblock).
     *
     * @return list<int>
     */
    public static function relationshipIds(int $postId, string $metaKey): array
    {
        /** @var mixed $stored */
        $stored = get_post_meta($postId, $metaKey, true);

        if (! is_array($stored)) {
            return [];
        }

        $ids = [];

        foreach ($stored as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int) $value;

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A whole-currency-unit price meta value converted to integer minor
     * units (e.g. pounds to pence). round() is applied rather than a bare
     * int cast, so "19.99" becomes 1999, not 1998. A non-numeric or empty
     * stored value (including ACF's own "" default) defaults to zero.
     */
    public static function priceMinor(int $postId, string $metaKey): int
    {
        /** @var mixed $rawPrice */
        $rawPrice = get_post_meta($postId, $metaKey, true);

        return is_numeric($rawPrice) ? (int) round((float) $rawPrice * 100) : 0;
    }
}
