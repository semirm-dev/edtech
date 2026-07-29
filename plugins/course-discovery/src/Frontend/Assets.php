<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Plugin;
use WP_Post;

/**
 * Enqueues the plugin's stylesheet and progressive-enhancement script, but
 * only on a page that actually renders [course_discovery] -- avoids
 * shipping unused assets to every visitor site-wide.
 */
final class Assets
{
    private const HANDLE = 'course-discovery';

    /**
     * @param string $pluginFile Absolute path to the plugin's entry file
     *                           (course-discovery.php's __FILE__), passed from
     *                           Plugin::boot(). MUST NOT be derived from this
     *                           class's own __DIR__, which resolves to a
     *                           different path than the plugin copy WordPress
     *                           scans -- the bug that made plugins_url() 404
     *                           here before.
     */
    public function __construct(private readonly string $pluginFile)
    {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue']);
    }

    public function maybeEnqueue(): void
    {
        $post = get_post();

        if (! $post instanceof WP_Post || ! has_shortcode($post->post_content, Shortcode::TAG)) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            plugins_url('assets/course-discovery.css', $this->pluginFile),
            [],
            Plugin::VERSION
        );

        wp_enqueue_script(
            self::HANDLE,
            plugins_url('assets/course-discovery.js', $this->pluginFile),
            [],
            Plugin::VERSION,
            true
        );

        // Strings the script needs that have no server-rendered markup to
        // translate from -- currently just the combobox's empty-selection
        // placeholder.
        wp_localize_script(self::HANDLE, 'cdDiscoveryConfig', [
            'anyLabel' => __('Any', 'course-discovery'),
        ]);
    }
}
