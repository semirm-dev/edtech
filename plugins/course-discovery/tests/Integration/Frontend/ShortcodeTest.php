<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\StartDates;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Frontend\Shortcode;
use CourseDiscovery\Index\CourseIndexer;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

final class ShortcodeTest extends IntegrationTestCase
{
    use UsesIndexTables;

    /**
     * The exact vector the escaping requirements exist to defeat: a
     * filter option label sourced from editor-controlled content (a
     * provider's post title, a term's name) that carries markup instead of
     * plain text.
     */
    private const XSS_LABEL = '"><img src=x onerror=alert(1)>';

    private CourseIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->prepareIndexTables();

        $this->indexer = new CourseIndexer($wpdb, new Schema($wpdb));

        $this->createCourse('Graphic Design Foundation', '950', [202603]);

        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    private function render(): string
    {
        return do_shortcode('[' . Shortcode::TAG . ']');
    }

    /**
     * @param list<int> $startDates
     */
    private function createCourse(string $title, string $price, array $startDates): int
    {
        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'    => PostTypes::COURSE,
            'post_title'   => $title,
            'post_excerpt' => 'Learn visual communication.',
            'post_status'  => 'publish',
        ]);

        update_post_meta($id, AcfFields::FIELD_PRICE, $price);
        update_post_meta($id, StartDates::META_KEY, $startDates);
        $this->indexer->indexCourse($id);

        return $id;
    }

    public function test_it_renders_a_real_form_that_submits_by_get(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('method="get"', $html);
    }

    public function test_each_filter_group_is_a_fieldset_with_a_legend(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<fieldset', $html);
        self::assertStringContainsString('<legend', $html);
    }

    public function test_it_renders_matching_courses(): void
    {
        self::assertStringContainsString('Graphic Design Foundation', $this->render());
    }

    public function test_it_announces_the_result_count_to_assistive_technology(): void
    {
        $html = $this->render();

        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertMatchesRegularExpression('/1\s+course/i', $html);
    }

    public function test_it_reflects_the_current_query_in_the_form(): void
    {
        $_GET['q'] = 'design';

        self::assertStringContainsString('value="design"', $this->render());
    }

    public function test_it_escapes_a_reflected_search_term(): void
    {
        $_GET['q'] = '"><script>alert(1)</script>';

        $html = $this->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_it_renders_an_empty_state_rather_than_nothing(): void
    {
        $_GET['q'] = 'zzzzznomatchzzzzz';

        $html = $this->render();

        self::assertMatchesRegularExpression('/no courses|0 courses/i', $html);
    }

    public function test_every_input_has_an_associated_label(): void
    {
        $html = $this->render();

        preg_match_all('/<input[^>]*id="([^"]+)"/', $html, $inputs);

        self::assertNotEmpty($inputs[1], 'Expected at least one identified input.');

        foreach ($inputs[1] as $id) {
            self::assertStringContainsString(
                'for="' . $id . '"',
                $html,
                sprintf('Input #%s has no associated <label for>.', $id)
            );
        }
    }

    /**
     * Mirrors test_every_input_has_an_associated_label() for the two
     * `<select multiple>` controls (location, start_date), which the
     * `<input id=...>` regex above never inspects. Both filters render
     * through renderSelectMultiple() rather than renderCheckboxGroup() or
     * renderText(), so nothing else in this file exercises their markup.
     */
    public function test_every_select_has_an_associated_label(): void
    {
        $html = $this->render();

        preg_match_all('/<select[^>]*id="([^"]+)"/', $html, $selects);

        self::assertNotEmpty($selects[1], 'Expected at least one identified select.');

        foreach ($selects[1] as $id) {
            self::assertStringContainsString(
                'for="' . $id . '"',
                $html,
                sprintf('Select #%s has no associated <label for>.', $id)
            );
        }
    }

    /**
     * Pins the server-rendered half of the combobox accessible-
     * name fix -- course-discovery.js reads this exact "<select id>-label"
     * convention to set aria-labelledby on the trigger it builds (see
     * FormRenderer::renderSelectMultiple()'s and the JS's own docblocks).
     * The JS side itself is out of scope for an automated test here; this
     * only pins the id FormRenderer must keep emitting for that JS to have
     * anything to point at.
     */
    public function test_every_select_label_carries_an_id_the_combobox_upgrade_can_reference(): void
    {
        $html = $this->render();

        preg_match_all('/<select[^>]*id="([^"]+)"/', $html, $selects);

        self::assertNotEmpty($selects[1], 'Expected at least one identified select.');

        foreach ($selects[1] as $id) {
            self::assertStringContainsString(
                '<label id="' . $id . '-label" for="' . $id . '">',
                $html,
                sprintf('Select #%s has no label carrying the "%s-label" id the JS combobox expects.', $id, $id)
            );
        }
    }

    /**
     * Provider names are editor-controlled and render through
     * FormRenderer::renderCheckboxGroup() -- the exact reason option labels
     * are escaped at all. A reflected search term already has this
     * coverage (test_it_escapes_a_reflected_search_term above); this is the
     * option-label vector, which had none.
     *
     * The fixture is created as an administrator: WordPress's own
     * `title_save_pre` kses filter (wp_filter_kses(), see
     * kses_init_filters()) strips a raw `<img>` tag out of a post title for
     * any user WITHOUT the `unfiltered_html` capability, which the default
     * (logged-out) test user lacks -- the payload would never survive far
     * enough to reach FormRenderer at all, and the test would prove nothing
     * about FormRenderer's own escaping. Administrators hold
     * `unfiltered_html` on a single-site install by default, so this is the
     * realistic "editor entered markup in a title" scenario the escaping
     * exists for, not a workaround.
     */
    public function test_a_provider_name_containing_markup_is_escaped_in_checkbox_options(): void
    {
        /** @var int $adminId */
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_title'  => self::XSS_LABEL,
            'post_status' => 'publish',
        ]);

        $html = $this->render();

        self::assertStringNotContainsString(
            '<img src=x onerror=alert(1)>',
            $html,
            'The provider label must be HTML-escaped, not echoed raw.'
        );
        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;',
            $html,
            'The escaped provider label must still be present -- the provider stays listed, just safely.'
        );
    }

    /**
     * Same vector as the provider checkbox test above, but through
     * FormRenderer::renderSelectMultiple() -- a `<select multiple>`
     * `<option>` label is a different code path and must be checked
     * separately.
     *
     * Unlike a post title, a term's `name` is run through
     * `sanitize_text_field()` (which strips all tags) on EVERY save via the
     * `pre_term_name` filter -- see default-filters.php -- unconditionally,
     * with no `unfiltered_html` capability check at all. There is no
     * legitimate WP-API path (`wp_insert_term()`/`wp_update_term()`) that
     * lets a term name retain a raw tag, so going through the factory as in
     * the provider test above cannot reach FormRenderer with the payload
     * intact. The row is written directly so the fixture models a term
     * whose stored name already contains markup -- e.g. imported from
     * another system -- which is exactly the case FormRenderer's own
     * `esc_html()` (not WordPress's save-time filtering) must still defend
     * against. `clean_term_cache()` bumps the terms `last_changed` cache
     * key so `get_terms()` in TermOptions::build() reads this row rather
     * than a stale cached one.
     */
    public function test_a_location_name_containing_markup_is_escaped_in_combobox_options(): void
    {
        /** @var int $locationId */
        $locationId = self::factory()->term->create([
            'taxonomy' => Taxonomies::LOCATION,
            'name'     => 'Placeholder Location',
        ]);

        global $wpdb;
        $wpdb->update($wpdb->terms, ['name' => self::XSS_LABEL], ['term_id' => $locationId]);
        clean_term_cache($locationId, Taxonomies::LOCATION);

        $html = $this->render();

        self::assertStringNotContainsString(
            '<img src=x onerror=alert(1)>',
            $html,
            'The location option label must be HTML-escaped, not echoed raw.'
        );
        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;',
            $html,
            'The escaped location label must still be present -- the location stays listed, just safely.'
        );
    }

    public function test_a_selected_checkbox_is_reflected_as_checked(): void
    {
        /** @var int $providerId */
        $providerId = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_title'  => 'Selected Provider',
            'post_status' => 'publish',
        ]);

        $_GET['provider'] = [(string) $providerId];

        $html = $this->render();

        self::assertMatchesRegularExpression(
            '/<input type="checkbox" id="[^"]+" name="provider\[\]" value="' . $providerId . '" checked \/>/',
            $html
        );
    }

    public function test_a_selected_combobox_option_is_reflected_as_selected(): void
    {
        /** @var int $locationId */
        $locationId = self::factory()->term->create([
            'taxonomy' => Taxonomies::LOCATION,
            'name'     => 'Selected Location',
        ]);

        $_GET['location'] = [(string) $locationId];

        $html = $this->render();

        self::assertStringContainsString(
            '<option value="' . $locationId . '" selected>',
            $html
        );
    }

    public function test_pagination_links_carry_query_and_mark_the_current_page(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->createCourse('Extra Course ' . $i, '100', [202603]);
        }

        $html = $this->render();

        self::assertStringContainsString('<nav class="cd-pagination"', $html);
        self::assertMatchesRegularExpression(
            '/<a href="[^"]*paged=2[^"]*"[^>]*>2<\/a>/',
            $html,
            'Expected a real link to page 2 carrying the paged query param.'
        );
        self::assertMatchesRegularExpression(
            '/<a href="[^"]*"[^>]*aria-current="page"[^>]*>1<\/a>/',
            $html,
            'Expected the page-1 link to carry aria-current="page".'
        );
    }

    public function test_clear_filters_link_has_no_known_filter_params(): void
    {
        $_GET['q'] = 'design';
        $_GET['provider'] = ['5'];

        $html = $this->render();

        $marker = '<a class="cd-clear-filters" href="';
        $hrefStart = strpos($html, $marker);

        self::assertNotFalse($hrefStart, 'Expected a clear-filters link.');

        $hrefStart += strlen($marker);
        $hrefEnd = strpos($html, '"', $hrefStart);

        self::assertNotFalse($hrefEnd, 'Expected the clear-filters href to be a closed attribute.');

        $href = html_entity_decode(substr($html, $hrefStart, $hrefEnd - $hrefStart));

        self::assertStringNotContainsString('q=design', $href);
        self::assertStringNotContainsString('provider', $href);
    }

    public function test_an_unrelated_query_param_is_preserved_as_a_hidden_input(): void
    {
        $_GET['utm_source'] = 'newsletter';

        $html = $this->render();

        self::assertStringContainsString(
            '<input type="hidden" name="utm_source" value="newsletter" />',
            $html
        );
    }

    public function test_it_preserves_a_non_default_sort_as_a_hidden_input_on_submit(): void
    {
        $_GET['sort'] = 'price_asc';

        $html = $this->render();

        self::assertStringContainsString('name="sort" value="price_asc"', $html);
    }

    public function test_it_does_not_render_a_sort_hidden_input_for_the_default_sort(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('name="sort"', $html);
    }
}
