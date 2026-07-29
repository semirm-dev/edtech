<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\AdminColumns;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

final class AdminColumnsTest extends IntegrationTestCase
{
    private AdminColumns $adminColumns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminColumns = new AdminColumns();
    }

    public function test_it_adds_providers_and_next_start_columns(): void
    {
        $columns = $this->adminColumns->columns(['title' => 'Title', 'date' => 'Date']);

        self::assertArrayHasKey('cd_providers', $columns);
        self::assertArrayHasKey('cd_next_start', $columns);
        self::assertSame('Title', $columns['title']);
    }

    public function test_it_inserts_the_new_columns_immediately_before_date_in_order(): void
    {
        // An append-only implementation would also satisfy the presence
        // assertion above, so this pins the actual insert-before-'date'
        // behaviour instead.
        $columns = $this->adminColumns->columns(['cb' => 'Checkbox', 'title' => 'Title', 'date' => 'Date']);

        self::assertSame(
            ['cb', 'title', 'cd_providers', 'cd_next_start', 'date'],
            array_keys($columns)
        );
    }

    public function test_it_appends_the_new_columns_when_there_is_no_date_column(): void
    {
        $columns = $this->adminColumns->columns(['title' => 'Title']);

        self::assertSame(['title', 'cd_providers', 'cd_next_start'], array_keys($columns));
    }

    public function test_it_renders_the_earliest_start_date(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, [202601, 202603]);

        ob_start();
        $this->adminColumns->render('cd_next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('January 2026', $output);
    }

    public function test_it_renders_a_dash_when_no_dates_are_set(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);

        ob_start();
        $this->adminColumns->render('cd_next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('—', $output);
    }

    public function test_it_renders_numeric_string_meta_instead_of_dropping_it(): void
    {
        // Previously a strict is_int() filter silently dropped rows shaped
        // like this (reachable via REST, an import, or `wp post meta
        // update` without --format=json), rendering a dash even though
        // real dates were stored. Fixed via StartDates::storedKeys().
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, ['202601', '202603']);

        ob_start();
        $this->adminColumns->render('cd_next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('January 2026', $output);
    }

    public function test_it_uses_all_valid_entries_from_a_mixed_array(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, ['202601', 202603, 202605]);

        ob_start();
        $this->adminColumns->render('cd_next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('January 2026', $output);
    }

    public function test_it_renders_a_dash_for_an_out_of_range_key(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);
        update_post_meta($courseId, StartDates::META_KEY, [202613]);

        ob_start();
        $this->adminColumns->render('cd_next_start', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('—', $output);
    }

    public function test_it_renders_linked_provider_titles(): void
    {
        /** @var int $providerA */
        $providerA = self::factory()->post->create([
            'post_type'  => PostTypes::PROVIDER,
            'post_title' => 'Acme University',
        ]);
        /** @var int $providerB */
        $providerB = self::factory()->post->create([
            'post_type'  => PostTypes::PROVIDER,
            'post_title' => 'Beta College',
        ]);

        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);

        // ACF stores relationship values as numeric STRINGS, not integers.
        update_post_meta($courseId, AcfFields::FIELD_PROVIDERS, [(string) $providerA, (string) $providerB]);

        ob_start();
        $this->adminColumns->render('cd_providers', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('Acme University, Beta College', $output);
    }

    public function test_it_renders_a_dash_when_no_providers_are_linked(): void
    {
        /** @var int $courseId */
        $courseId = self::factory()->post->create(['post_type' => PostTypes::COURSE]);

        ob_start();
        $this->adminColumns->render('cd_providers', $courseId);
        $output = (string) ob_get_clean();

        self::assertSame('—', $output);
    }
}
