<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * StartDates is otherwise pure PHP; storedKeys() is its one deliberate,
 * WordPress-touching exception (see the class docblock), so it needs
 * WordPress loaded and belongs in the integration suite rather than the
 * unit one.
 */
final class StartDatesTest extends IntegrationTestCase
{
    public function test_stored_keys_accepts_and_normalises_numeric_strings(): void
    {
        // Reachable via REST, an import, or `wp post meta update` without
        // --format=json. A strict is_int() filter used to silently drop
        // rows shaped like this.
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, ['202601', '202603']);

        self::assertSame([202601, 202603], StartDates::storedKeys($courseId));
    }

    public function test_stored_keys_discards_non_numeric_junk(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, ['not-a-date', 202603, null, true, '']);

        self::assertSame([202603], StartDates::storedKeys($courseId));
    }

    public function test_stored_keys_returns_an_empty_list_for_a_non_array_value(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, 'not-an-array');

        self::assertSame([], StartDates::storedKeys($courseId));
    }

    /**
     * formatLocalised() is the other WordPress-touching member of this
     * otherwise-pure class (alongside storedKeys() above) -- it calls
     * wp_date(), so it belongs in the integration suite too.
     */
    public function test_format_localised_produces_the_expected_english_string_under_the_default_locale(): void
    {
        self::assertSame('March 2026', StartDates::formatLocalised(202603));
    }

    public function test_format_localised_degrades_to_empty_string_for_an_out_of_range_key(): void
    {
        self::assertSame('', StartDates::formatLocalised(202600));
        self::assertSame('', StartDates::formatLocalised(202613));
    }

    /**
     * Proves formatLocalised() actually goes through WordPress's date
     * localisation layer, rather than happening to match format()'s
     * hard-coded English by coincidence -- observed via the 'wp_date'
     * filter that wp_date() applies internally (see wp-includes/functions.php).
     * A hard-coded-English implementation would never trigger this filter.
     */
    public function test_format_localised_goes_through_wordpress_date_localisation(): void
    {
        $seenFormat = null;

        add_filter('wp_date', static function (string $date, string $format) use (&$seenFormat): string {
            $seenFormat = $format;

            return $date;
        }, 10, 2);

        StartDates::formatLocalised(202603);

        self::assertSame('F Y', $seenFormat, 'formatLocalised() must render via wp_date() so translated month names are possible.');
    }

    /**
     * formatLocalised() built its timestamp with gmmktime() (the
     * 1st of the month at 00:00 UTC) but then called wp_date() with no
     * explicit timezone, so wp_date() re-projected that instant into the
     * SITE's configured timezone. On any negative-offset site (the
     * Americas) the instant rolls back into the previous month -- sort key
     * 202603 rendered "February 2026" instead of "March 2026".
     *
     * A month-and-year value has no timezone of its own, so the fix pins
     * wp_date()'s third argument to UTC explicitly. This test proves that
     * pin holds by switching the site to America/New_York (UTC-5 in
     * March, non-DST) -- a genuinely negative offset, not just a
     * different label -- and asserting the rendered month is unaffected.
     */
    public function test_format_localised_is_unaffected_by_a_negative_offset_site_timezone(): void
    {
        $originalTimezone = get_option('timezone_string', '');

        update_option('timezone_string', 'America/New_York');

        try {
            self::assertSame(
                'March 2026',
                StartDates::formatLocalised(202603),
                'A month/year value must render the same month regardless of the site timezone.'
            );
        } finally {
            update_option('timezone_string', $originalTimezone);
        }
    }
}
