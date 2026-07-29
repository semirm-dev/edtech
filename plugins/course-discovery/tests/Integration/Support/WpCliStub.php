<?php

declare(strict_types=1);

// Deliberately no namespace: Plugin::registerCliCommand() calls the real
// WP_CLI class in the GLOBAL namespace (`\WP_CLI::add_command(...)`), so a
// stand-in for it must live there too.
//
// The real `wp` binary process (and its WP_CLI class) is
// unavailable in the integration test environment -- only the PHPStan-only
// `php-stubs/wp-cli-stubs` package is installed, which composer does not
// autoload at runtime (verified: class_exists('WP_CLI', false) is false
// before this file is required). Without a stand-in, nothing could ever
// exercise Plugin::registerCliCommand()'s actual `WP_CLI::add_command()`
// call, and a typo in the registered command name would ship green.

if (! class_exists('WP_CLI', false)) {
    /**
     * Minimal recorder standing in for the real WP_CLI class. Delegates to
     * WpCliCommandRecorder (see its docblock for why) rather than holding
     * state itself, so a test can assert on the exact name and callback a
     * command was registered under.
     */
    final class WP_CLI
    {
        public static function add_command(string $name, object $callback): void
        {
            \CourseDiscovery\Tests\Integration\Support\WpCliCommandRecorder::$commands[] = [$name, $callback];
        }

        public static function success(string $message): void
        {
        }
    }
}
