<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit;

use CourseDiscovery\Container;
use CourseDiscovery\Tests\Unit\Support\CircularServiceA;
use CourseDiscovery\Tests\Unit\Support\CircularServiceB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function test_get_resolves_a_registered_service(): void
    {
        $container = new Container();
        $container->set(stdClass::class, static fn (): stdClass => new stdClass());

        self::assertInstanceOf(stdClass::class, $container->get(stdClass::class));
    }

    public function test_get_returns_the_same_instance_on_repeated_calls(): void
    {
        $container = new Container();
        $container->set(stdClass::class, static fn (): stdClass => new stdClass());

        self::assertSame($container->get(stdClass::class), $container->get(stdClass::class));
    }

    public function test_get_rejects_an_unregistered_service(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->get(stdClass::class);
    }

    /**
     * A factory that asks the container for its own id used to
     * recurse until the call stack overflowed with a fatal error. get()
     * must now detect the cycle and fail with a clear RuntimeException
     * instead.
     */
    public function test_get_detects_a_service_that_directly_depends_on_itself(): void
    {
        $container = new Container();
        $container->set(stdClass::class, static function (Container $c): stdClass {
            return $c->get(stdClass::class);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/circular/i');

        $container->get(stdClass::class);
    }

    /**
     * The indirect case (A -> B -> A): neither service directly depends on
     * itself, but the graph still can't be resolved. The error message
     * should name the full chain, not just the repeated id.
     */
    public function test_get_detects_an_indirect_cycle_between_two_services(): void
    {
        $container = new Container();

        $container->set(CircularServiceA::class, static function (Container $c): CircularServiceA {
            return new CircularServiceA($c->get(CircularServiceB::class));
        });
        $container->set(CircularServiceB::class, static function (Container $c): CircularServiceB {
            return new CircularServiceB($c->get(CircularServiceA::class));
        });

        try {
            $container->get(CircularServiceA::class);
            self::fail('Expected a RuntimeException naming the circular dependency.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(CircularServiceA::class, $e->getMessage());
            self::assertStringContainsString(CircularServiceB::class, $e->getMessage());
        }
    }

    /**
     * A factory that throws (for an unrelated reason) must not leave the id
     * permanently marked as "resolving", which would make every subsequent
     * attempt to resolve it fail with a false-positive cycle error instead
     * of re-running the factory.
     */
    public function test_a_failed_resolution_does_not_permanently_block_later_attempts(): void
    {
        $container = new Container();

        $attempts = 0;
        $container->set(stdClass::class, static function () use (&$attempts): stdClass {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('Simulated failure on the first attempt.');
            }

            return new stdClass();
        });

        try {
            $container->get(stdClass::class);
            self::fail('Expected the first attempt to throw.');
        } catch (RuntimeException) {
            // Expected on the first attempt.
        }

        self::assertInstanceOf(stdClass::class, $container->get(stdClass::class), 'A retry after a failed resolution must not be blocked as a false-positive cycle.');
    }
}
