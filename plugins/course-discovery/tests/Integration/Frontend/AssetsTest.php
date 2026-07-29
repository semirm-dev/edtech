<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\Frontend\Shortcode;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Assets::maybeEnqueue() is registered on 'wp_enqueue_scripts' once, at
 * process bootstrap, by the real Plugin::boot() call in
 * tests/bootstrap-integration.php (which requires the actual
 * course-discovery.php entry file -- see that file's docblock). These
 * tests exercise that real, already-wired callback rather than constructing
 * their own Assets instance, so they see exactly what a real request sees.
 */
final class AssetsTest extends IntegrationTestCase
{
    private const HANDLE = 'course-discovery';

    protected function setUp(): void
    {
        parent::setUp();

        // wp-phpunit's own tear_down() (see vendor/wp-phpunit/.../
        // abstract-testcase.php) does NOT reset the $wp_scripts/$wp_styles
        // globals between tests, and WP_Dependencies::add() silently no-ops
        // for a handle that is already registered. Without discarding both
        // globals here, a registration left over from an earlier test (or
        // an earlier call within this test) would make every assertion
        // below observe stale data instead of the result of THIS test's own
        // do_action('wp_enqueue_scripts') call.
        global $wp_scripts, $wp_styles;
        $wp_scripts = null;
        $wp_styles = null;
    }

    /**
     * Renders a real front-end request for a page containing the shortcode,
     * then fires the same 'wp_enqueue_scripts' hook a real request fires.
     * go_to() alone only sets up the query/$post globals Assets::
     * maybeEnqueue()'s get_post() call depends on -- it does not itself
     * trigger 'wp_enqueue_scripts', so that action is fired explicitly here.
     */
    private function enqueueOnAShortcodePage(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'Find Courses',
            'post_content' => '[' . Shortcode::TAG . ']',
            'post_status'  => 'publish',
        ]);

        $permalink = get_permalink($postId);
        self::assertIsString($permalink, 'Precondition failed: expected a real permalink for the fixture page.');

        $this->go_to($permalink);

        do_action('wp_enqueue_scripts');
    }

    public function test_it_enqueues_the_stylesheet_and_script_on_a_shortcode_page(): void
    {
        $this->enqueueOnAShortcodePage();

        self::assertTrue(
            wp_style_is(self::HANDLE, 'registered'),
            'Expected the course-discovery stylesheet to be registered on a page carrying the shortcode.'
        );
        self::assertTrue(
            wp_script_is(self::HANDLE, 'registered'),
            'Expected the course-discovery script to be registered on a page carrying the shortcode.'
        );
    }

    public function test_it_does_not_enqueue_on_a_page_without_the_shortcode(): void
    {
        /** @var int $postId */
        $postId = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'About',
            'post_content' => 'Nothing to see here.',
            'post_status'  => 'publish',
        ]);

        $permalink = get_permalink($postId);
        self::assertIsString($permalink, 'Precondition failed: expected a real permalink for the fixture page.');

        $this->go_to($permalink);
        do_action('wp_enqueue_scripts');

        self::assertFalse(wp_style_is(self::HANDLE, 'registered'));
        self::assertFalse(wp_script_is(self::HANDLE, 'registered'));
    }

    /**
     * Root cause (caught by Playwright, not by any PHP test, because none
     * previously existed that inspected the enqueued URL's shape -- every
     * prior check, and the two tests above, stop at "the handle got
     * enqueued"): Assets::maybeEnqueue() used to derive its plugin file path
     * from ITS OWN __DIR__ (a `src/Frontend` class). Composer's PSR-4
     * autoloader resolves CourseDiscovery\ classes from
     * plugins/course-discovery/src -- a different absolute path, in both
     * DDEV and a production container, than the plugin copy WordPress
     * scans under wp-content/plugins/. plugins_url() cannot find
     * WP_PLUGIN_DIR as a prefix of that wrong path, so it silently
     * CONCATENATES instead of rewriting it, producing e.g.
     * https://site/wp-content/plugins/var/www/html/plugins/course-discovery/assets/course-discovery.js
     * -- a 404. That left the ARIA combobox dead on the real site; only the
     * no-JS baseline ever ran. See keyboard.spec.ts in e2e/tests, which
     * caught this against the real site.
     *
     * The fix derives the URL from the plugin's ENTRY FILE (course-
     * discovery.php's own __FILE__, passed down through Plugin::boot()),
     * which WordPress always loads from its real plugin directory. This
     * test pins the resulting URL's shape so a regression back to a `src/`
     * __DIR__ fails here instead of only in a browser.
     */
    public function test_the_enqueued_urls_are_well_formed(): void
    {
        $this->enqueueOnAShortcodePage();

        $registeredStyle = wp_styles()->registered[self::HANDLE] ?? null;
        $registeredScript = wp_scripts()->registered[self::HANDLE] ?? null;

        self::assertNotNull($registeredStyle, 'Expected the stylesheet handle to be registered.');
        self::assertNotNull($registeredScript, 'Expected the script handle to be registered.');

        $urls = [
            'stylesheet' => $registeredStyle->src,
            'script'     => $registeredScript->src,
        ];

        foreach ($urls as $label => $src) {
            self::assertIsString($src, sprintf('Expected the %s to be registered with a string src.', $label));

            self::assertTrue(
                str_starts_with($src, site_url()),
                sprintf('Expected the %s URL to start with the site URL, got "%s".', $label, $src)
            );

            self::assertStringNotContainsString(
                '/var/www/',
                $src,
                sprintf(
                    'Expected the %s URL to contain no absolute filesystem path, got "%s".',
                    $label,
                    $src
                )
            );

            self::assertMatchesRegularExpression(
                '#/wp-content/plugins/course-discovery/assets/course-discovery\.(css|js)(\?.*)?$#',
                $src,
                sprintf(
                    'Expected the %s URL to point under wp-content/plugins/course-discovery/assets/, got "%s".',
                    $label,
                    $src
                )
            );

            self::assertSame(
                1,
                substr_count((string) $src, 'plugins/'),
                sprintf(
                    'Expected exactly one "plugins/" path segment in the %s URL (no doubled plugins path), got "%s".',
                    $label,
                    $src
                )
            );
        }
    }
}
