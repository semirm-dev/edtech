<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

/**
 * Extra columns on the course list screen, so an administrator can see each
 * course's providers and next start date without opening it.
 */
final class AdminColumns
{
    private const COLUMN_PROVIDERS = 'cd_providers';
    private const COLUMN_NEXT_START = 'cd_next_start';

    public function register(): void
    {
        add_filter('manage_' . PostTypes::COURSE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . PostTypes::COURSE . '_posts_custom_column', [$this, 'render'], 10, 2);
    }

    /**
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $reordered = [];

        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $reordered[self::COLUMN_PROVIDERS] = __('Providers', 'course-discovery');
                $reordered[self::COLUMN_NEXT_START] = __('Next Start', 'course-discovery');
            }

            $reordered[$key] = $label;
        }

        if (! isset($reordered[self::COLUMN_PROVIDERS])) {
            $reordered[self::COLUMN_PROVIDERS] = __('Providers', 'course-discovery');
        }

        if (! isset($reordered[self::COLUMN_NEXT_START])) {
            $reordered[self::COLUMN_NEXT_START] = __('Next Start', 'course-discovery');
        }

        return $reordered;
    }

    public function render(string $column, int $postId): void
    {
        if ($column === self::COLUMN_PROVIDERS) {
            self::renderProviders($postId);

            return;
        }

        if ($column === self::COLUMN_NEXT_START) {
            self::renderNextStart($postId);
        }
    }

    private static function renderNextStart(int $postId): void
    {
        $keys = StartDates::storedKeys($postId);

        if ($keys === []) {
            echo '—';

            return;
        }

        // formatLocalised(), not format(): this renders in wp-admin, so it
        // must go through WordPress's date localisation rather than
        // format()'s hard-coded English -- see both methods' docblocks.
        $formatted = StartDates::formatLocalised(min($keys));

        echo esc_html($formatted !== '' ? $formatted : '—');
    }

    private static function renderProviders(int $postId): void
    {
        /** @var mixed $stored */
        $stored = get_post_meta($postId, AcfFields::FIELD_PROVIDERS, true);
        $storedList = is_array($stored) ? $stored : [];

        // ACF's relationship field stores linked post IDs as numeric
        // STRINGS (see AcfFields), not integers, so an is_int() filter --
        // correct for StartDates' own hand-rolled meta -- would silently
        // drop every admin-authored provider here. intval() accepts both
        // shapes; the is_numeric() guard just keeps out non-numeric junk.
        $titles = [];

        foreach ($storedList as $value) {
            if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
                continue;
            }

            $title = get_the_title(intval($value));

            if ($title !== '') {
                $titles[] = $title;
            }
        }

        echo esc_html($titles !== [] ? implode(', ', $titles) : '—');
    }
}
