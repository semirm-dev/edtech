<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Support;

/**
 * Where the WP_CLI stub (see WpCliStub.php) records registered commands.
 *
 * Deliberately a separate, normally-namespaced class rather than a new
 * static property bolted onto the WP_CLI stub itself: PHPStan already knows
 * a `WP_CLI` class from the (scanned, not autoloaded-at-runtime)
 * php-stubs/wp-cli-stubs package, and resolves any reference to `WP_CLI` in
 * analysed code against THAT shape -- not our runtime-only redeclaration --
 * so a property that exists only on our stub would be reported as
 * undefined. Recording through an unrelated, fully-analysable class avoids
 * that entirely.
 */
final class WpCliCommandRecorder
{
    /** @var list<array{0: string, 1: object}> */
    public static array $commands = [];
}
