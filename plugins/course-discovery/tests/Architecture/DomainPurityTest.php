<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The domain layer must never depend on WordPress.
 *
 * This is what makes the domain unit-testable in milliseconds without
 * booting WordPress, and it is the structural claim the project's
 * architecture rests on. A grep-based guard is crude but it fails loudly
 * the moment someone reaches for a wp_* helper "just this once".
 */
final class DomainPurityTest extends TestCase
{
    private const FORBIDDEN_PATTERNS = [
        '/\bwp_[a-z_]+\s*\(/',
        '/\bget_post\w*\s*\(/',
        '/\bget_term\w*\s*\(/',
        '/\badd_(action|filter)\s*\(/',
        '/\b(apply_filters|do_action)(_ref_array)?\s*\(/',
        '/\besc_[a-z_]+\s*\(/',
        '/\b(__|_e|_n|_x|_nx)\s*\(/',
        '/\bWP_[A-Za-z_]+/',
        '/\$wpdb\b/',
        '/\$GLOBALS\s*\[\s*[\'"]wpdb[\'"]\s*\]/',
        '/\bABSPATH\b/',
        '/\b(get|add|update|delete)_option\s*\(/',
        '/\badd_shortcode\s*\(/',
        '/\b(admin|home|site)_url\s*\(/',
        '/\bfunction_exists\s*\(\s*[\'"]wp_[A-Za-z_]*[\'"]\s*\)/',
    ];

    /**
     * Token kinds dropped before pattern matching: comments, docblocks, and
     * stray inline HTML. A docblock is free to NAME a WordPress API (e.g.
     * "passed through $wpdb->prepare()") without tripping the guard — only
     * executable references count.
     *
     * Deliberately does NOT include T_CONSTANT_ENCAPSED_STRING (plain string
     * literals), even though the original review comment suggested it: three
     * of the FORBIDDEN_PATTERNS below only ever match INSIDE a quoted string
     * and have no realistic bare-code equivalent --
     * `defined('ABSPATH')`, `function_exists('wp_...')`, and
     * `$GLOBALS['wpdb']` (PHP treats an unquoted array key as a constant
     * lookup, so `$GLOBALS[wpdb]` is not an equivalent construct at all).
     * Stripping string literals would make those three patterns permanently
     * unreachable by any real file -- silently reintroducing exactly the
     * kind of blind spot the pattern-detection self-test below exists to
     * catch, and weakening tests that currently pass. Comments and docblocks
     * carry no such trade-off: they are never executed, so nothing of
     * substance is lost by exempting them.
     *
     * @var list<int>
     */
    private const SKIPPED_TOKENS = [
        T_COMMENT,
        T_DOC_COMMENT,
        T_INLINE_HTML,
    ];

    /**
     * Strips comments, docblocks and inline HTML from PHP source using the
     * real tokenizer, so matching runs only against actual code — never
     * against prose in a comment that merely mentions a WordPress symbol.
     *
     * token_get_all() requires an opening `<?php` tag to lex its argument as
     * PHP at all; the self-test below feeds bare statement snippets with no
     * tag, so one is added when missing. Without this, the whole snippet
     * would lex as a single T_INLINE_HTML token and vanish under the skip
     * list above, making every "should match" case in the self-test fail.
     */
    private static function stripComments(string $source): string
    {
        $hasOpeningTag = str_contains($source, '<?php') || str_contains($source, '<?=');
        $code = $hasOpeningTag ? $source : '<?php ' . $source;

        $stripped = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], self::SKIPPED_TOKENS, true)) {
                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }

    /**
     * The single source of truth for "does this source reference WordPress?"
     * Shared by the real-file check below and the self-test that proves
     * every pattern in FORBIDDEN_PATTERNS is actually reachable, rather than
     * relying on any real file happening to trip it.
     */
    private static function firstMatchingPattern(string $source): ?string
    {
        $codeOnly = self::stripComments($source);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $codeOnly) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function domainFileProvider(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Domain';

        $files = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                $files[] = [$path, (string) file_get_contents($path)];
            }
        }

        self::assertNotEmpty($files, 'No domain files found — check the path.');

        return $files;
    }

    /**
     * @dataProvider domainFileProvider
     */
    public function test_domain_file_contains_no_wordpress_references(string $path, string $source): void
    {
        $matched = self::firstMatchingPattern($source);

        self::assertNull($matched, sprintf('%s references WordPress (matched %s).', basename($path), $matched ?? ''));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function syntheticSourceProvider(): array
    {
        return [
            // Positive: one representative snippet per forbidden pattern
            // family, proving the regex is actually reachable rather than
            // dead weight nobody has ever seen fire.
            'wp_-prefixed function call' => ['$x = wp_insert_post([]);', true],
            'wp_cache_* is a wp_-prefixed call too' => ['wp_cache_set("k", "v");', true],
            'get_post*()' => ['$p = get_post(1);', true],
            'get_term*()' => ['$t = get_term_by("id", 1, "category");', true],
            'add_action()' => ['add_action("init", $cb);', true],
            'add_filter()' => ['add_filter("the_content", $cb);', true],
            'apply_filters()' => ['apply_filters("foo", $bar);', true],
            'apply_filters_ref_array()' => ['apply_filters_ref_array("foo", $args);', true],
            'do_action()' => ['do_action("foo");', true],
            'do_action_ref_array()' => ['do_action_ref_array("foo", $args);', true],
            'esc_html()' => ['echo esc_html($value);', true],
            'esc_html_e()' => ['echo esc_html_e("hi");', true],
            'esc_attr_e()' => ['echo esc_attr_e("hi");', true],
            'esc_sql()' => ['$safe = esc_sql($value);', true],
            'esc_js()' => ['$safe = esc_js($value);', true],
            'esc_textarea()' => ['echo esc_textarea($value);', true],
            '__() translation' => ['echo __("Hello", "textdomain");', true],
            '_e() translation' => ['_e("Hello", "textdomain");', true],
            '_n() translation' => ['echo _n("one", "many", $count, "textdomain");', true],
            '_x() translation' => ['echo _x("context", "Hello", "textdomain");', true],
            'use statement importing a WP class' => ['use WP_Post;', true],
            'WP_* type-hint' => ['function f(WP_Query $q) {}', true],
            '$wpdb variable' => ['$db = $wpdb;', true],
            "\$GLOBALS['wpdb']" => ['$db = $GLOBALS["wpdb"];', true],
            'ABSPATH guard' => ['if (defined("ABSPATH")) {}', true],
            'get_option()' => ['$x = get_option("foo");', true],
            'update_option()' => ['update_option("foo", "bar");', true],
            'add_shortcode()' => ['add_shortcode("foo", $cb);', true],
            'admin_url()' => ['$url = admin_url("edit.php");', true],
            'home_url()' => ['$url = home_url("/");', true],
            "function_exists('wp_...') guard" => ['if (function_exists("wp_insert_post")) {}', true],

            // Negative: legitimate pure-PHP domain code that must not be
            // flagged. A pattern that is too eager is as broken as one
            // that is too narrow.
            'plain object instantiation' => ['$this->wrapper = new Wrapper();', false],
            'plain method declaration' => ['public function swap(): void {}', false],
            'variable name that merely starts with wp' => ['$wpm = 5;', false],

            // Regression case for the false-positive this fix addresses: a
            // docblock (or any comment) may legitimately NAME a WordPress
            // API without being flagged, since only executable code is
            // matched after stripping comments/strings.
            'docblock naming a WordPress API' => [
                '/** Bindings are passed through $wpdb->prepare(), never interpolated. */',
                false,
            ],
            'line comment naming a WordPress function' => [
                '// calls wp_insert_post() internally, see docs',
                false,
            ],
        ];
    }

    /**
     * Proves the detection mechanism itself works for every pattern family,
     * independent of whether any real domain file currently happens to
     * trigger it. A previous review only ever exercised the get_post()
     * pattern by hand; every other pattern was unverified, so a typo in
     * any of them would have been a silent, permanent blind spot.
     *
     * @dataProvider syntheticSourceProvider
     */
    public function test_pattern_detection_mechanism(string $snippet, bool $shouldMatch): void
    {
        self::assertSame($shouldMatch, self::firstMatchingPattern($snippet) !== null, $snippet);
    }
}
