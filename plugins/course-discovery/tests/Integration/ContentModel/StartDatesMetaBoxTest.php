<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\StartDatesMetaBox;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

final class StartDatesMetaBoxTest extends IntegrationTestCase
{
    private int $courseId;

    private StartDatesMetaBox $metaBox;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        $this->courseId = $courseId;
        $this->metaBox = new StartDatesMetaBox();

        /** @var int $userId */
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
    }

    public function test_it_renders_stored_dates_including_numeric_strings(): void
    {
        // Pins the fix for the silent-data-loss bug: numeric-string meta
        // (reachable via REST, an import, or `wp post meta update`
        // without --format=json) must populate a field, not render blank
        // rows that then wipe the real dates on the next Update.
        update_post_meta($this->courseId, StartDates::META_KEY, ['202601', 202603]);

        $post = get_post($this->courseId);
        self::assertNotNull($post);

        ob_start();
        $this->metaBox->render($post);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('value="01-2026"', $output);
        self::assertStringContainsString('value="03-2026"', $output);
    }

    public function test_it_saves_normalised_sorted_dates(): void
    {
        $_POST['course_start_dates'] = ['March-2026', 'January-2026'];
        $_POST['course_start_dates_nonce'] = wp_create_nonce('course_start_dates_save_' . $this->courseId);

        $this->metaBox->save($this->courseId);

        self::assertSame(
            [202601, 202603],
            get_post_meta($this->courseId, StartDates::META_KEY, true)
        );
    }

    public function test_it_ignores_the_request_without_a_valid_nonce(): void
    {
        $_POST['course_start_dates'] = ['March-2026'];
        $_POST['course_start_dates_nonce'] = 'forged';

        $this->metaBox->save($this->courseId);

        self::assertSame('', get_post_meta($this->courseId, StartDates::META_KEY, true));
    }

    public function test_it_rejects_the_save_for_a_user_without_edit_permission(): void
    {
        /** @var int $subscriberId */
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);

        // The nonce is bound to the current user, so it must be minted
        // *after* switching to the subscriber — otherwise this would
        // exercise the nonce path instead of the capability check.
        wp_set_current_user($subscriberId);

        $_POST['course_start_dates'] = ['March-2026'];
        $_POST['course_start_dates_nonce'] = wp_create_nonce('course_start_dates_save_' . $this->courseId);

        $this->metaBox->save($this->courseId);

        self::assertSame('', get_post_meta($this->courseId, StartDates::META_KEY, true));
    }

    public function test_it_ignores_the_request_when_the_nonce_field_is_absent(): void
    {
        // No nonce key at all — distinct from a forged/invalid nonce value.
        // A handler shaped `if (isset($_POST[NONCE]) && !verify(...)) return;`
        // would wrongly let this through by skipping the check entirely.
        $_POST['course_start_dates'] = ['March-2026'];

        $this->metaBox->save($this->courseId);

        self::assertSame('', get_post_meta($this->courseId, StartDates::META_KEY, true));
    }

    public function test_it_preserves_existing_dates_when_post_data_is_empty(): void
    {
        // Simulates save_post firing without a real form submission (e.g.
        // autosave). A handler that unconditionally writes $_POST-derived
        // data would wipe the previously stored dates here.
        update_post_meta($this->courseId, StartDates::META_KEY, [202601, 202603]);

        $this->metaBox->save($this->courseId);

        self::assertSame(
            [202601, 202603],
            get_post_meta($this->courseId, StartDates::META_KEY, true)
        );
    }

    protected function tearDown(): void
    {
        unset($_POST['course_start_dates'], $_POST['course_start_dates_nonce']);

        parent::tearDown();
    }
}
