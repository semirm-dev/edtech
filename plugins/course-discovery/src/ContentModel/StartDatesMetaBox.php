<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

use WP_Post;

/**
 * Admin UI for the repeating start-date list.
 *
 * ACF's Repeater is PRO-only, so this is hand-rolled. The upside is full
 * control over the stored format, which is what makes chronological
 * ordering cheap at query time.
 */
final class StartDatesMetaBox
{
    private const NONCE_ACTION = 'course_start_dates_save';
    private const NONCE_NAME = 'course_start_dates_nonce';
    private const FIELD_NAME = 'course_start_dates';

    public function register(): void
    {
        add_meta_box(
            'course-start-dates',
            __('Start Dates', 'course-discovery'),
            [$this, 'render'],
            PostTypes::COURSE,
            'normal',
            'default'
        );
    }

    public function render(WP_Post $post): void
    {
        $keys = StartDates::storedKeys($post->ID);

        wp_nonce_field(self::NONCE_ACTION . '_' . $post->ID, self::NONCE_NAME);

        printf(
            '<p class="description">%s</p>',
            wp_kses(
                __('One per line, as <code>MM-YYYY</code> or <code>Month-YYYY</code>.', 'course-discovery'),
                ['code' => []]
            )
        );
        echo '<div id="course-start-dates-rows">';

        foreach ($keys as $key) {
            printf(
                '<p><input type="text" name="%s[]" value="%s" class="regular-text" /></p>',
                esc_attr(self::FIELD_NAME),
                esc_attr(StartDates::formatForInput($key))
            );
        }

        printf(
            '<p><input type="text" name="%s[]" value="" class="regular-text" placeholder="03-2026" /></p>',
            esc_attr(self::FIELD_NAME)
        );

        echo '</div>';
    }

    public function save(int $postId): void
    {
        $nonce = '';

        if (isset($_POST[self::NONCE_NAME]) && is_string($_POST[self::NONCE_NAME])) {
            $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
        }

        if (! wp_verify_nonce($nonce, self::NONCE_ACTION . '_' . $postId)) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $raw = [];

        if (isset($_POST[self::FIELD_NAME]) && is_array($_POST[self::FIELD_NAME])) {
            foreach ($_POST[self::FIELD_NAME] as $value) {
                if (is_string($value)) {
                    $raw[] = sanitize_text_field(wp_unslash($value));
                }
            }
        }

        $normalised = StartDates::normaliseList($raw);

        update_post_meta($postId, StartDates::META_KEY, $normalised);
    }
}
