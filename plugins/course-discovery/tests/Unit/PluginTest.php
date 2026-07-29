<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit;

use CourseDiscovery\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function test_it_exposes_a_semver_version(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Plugin::VERSION);
    }
}
