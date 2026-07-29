<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration;

/**
 * Polyfill for wp-phpunit's WP_UnitTestCase::set_up(), which unconditionally
 * calls expectDeprecated(). That method looks for PHPUnit's legacy
 * getAnnotations() first and, failing that, falls back to the PHPUnit 9-era
 * PHPUnit\Util\Test::parseTestMethodAnnotations(). Both were removed from
 * PHPUnit in the 10.0 metadata rewrite, so without this shim every test
 * extending WP_UnitTestCase fatals during set_up() on PHPUnit 10/11.
 *
 * wp-phpunit checks `method_exists($this, 'getAnnotations')` (duck typing),
 * so mixing this trait into a test case satisfies that check and short
 * circuits before the removed static method is ever called.
 *
 * Side effect: because this always returns empty arrays, doc-comment
 * annotations such as `@expectedDeprecated` / `@expectedIncorrectUsage` are
 * silently no-ops under this trait. Use the programmatic equivalents
 * instead — `setExpectedDeprecated()` / `setExpectedIncorrectUsage()` —
 * which still work correctly.
 */
trait Phpunit11CompatTrait
{
    /**
     * @return array{class: array<string, mixed>, method: array<string, mixed>}
     */
    public function getAnnotations(): array
    {
        return ['class' => [], 'method' => []];
    }
}
