<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

final class FilterRegistryTest extends IntegrationTestCase
{
    private function makeFilter(string $key): Filter
    {
        return new class ($key) implements Filter {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): FilterKey
            {
                return FilterKey::fromString($this->key);
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::CheckboxGroup;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::empty();
            }

            public function description(): ?string
            {
                return null;
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                $ids = $values->toInts();

                return $ids === [] ? null : new AttributeInConstraint($this->key, $ids);
            }
        };
    }

    public function test_it_registers_and_retrieves_a_filter(): void
    {
        $registry = new FilterRegistry();
        $registry->register($this->makeFilter('provider'));

        self::assertTrue($registry->has('provider'));
        self::assertNotNull($registry->get('provider'));
        self::assertCount(1, $registry);
    }

    public function test_it_preserves_registration_order(): void
    {
        $registry = new FilterRegistry();
        $registry->register($this->makeFilter('keyword'));
        $registry->register($this->makeFilter('provider'));
        $registry->register($this->makeFilter('location'));

        $keys = array_map(static fn (FilterKey $k): string => $k->toString(), $registry->keys());

        self::assertSame(['keyword', 'provider', 'location'], $keys);
    }

    /**
     * A duplicate key must NOT fatal the page: register() runs from inside
     * do_action() (see FilterRegistry::boot()), and WordPress does not
     * catch exceptions thrown from a hook callback -- a throw here would
     * take down the public search page for every visitor the moment two
     * plugins registered the same key, aborting do_action()'s loop and
     * silently dropping every other, lower-priority extension's filters
     * too. So the first registration wins (keeping core filters
     * authoritative against a later extension) and the duplicate is
     * dropped with a `_doing_it_wrong()` notice -- expected here via
     * setExpectedIncorrectUsage() rather than left to fail the test.
     */
    public function test_it_keeps_the_first_registration_on_a_duplicate_key(): void
    {
        $registry = new FilterRegistry();
        $first = $this->makeFilter('provider');
        $second = $this->makeFilter('provider');

        $this->setExpectedIncorrectUsage(FilterRegistry::class . '::register');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($first, $registry->get('provider'), 'The first registration must win.');
        self::assertCount(1, $registry);
    }

    public function test_an_unknown_key_returns_null(): void
    {
        self::assertNull((new FilterRegistry())->get('nope'));
        self::assertFalse((new FilterRegistry())->has('nope'));
    }

    /**
     * Reproduces the exact scenario:
     * two ACTION_REGISTER listeners both register
     * "provider", and a THIRD, lower-priority listener registers something
     * else entirely. Before the fix, register()'s InvalidArgumentException
     * would have propagated out of do_action() uncaught (WordPress does
     * not catch hook exceptions), aborting the loop -- so the third
     * listener would never have run and "location" would never have been
     * registered. This test proves the request completes, the first
     * "provider" registration wins, and the later listener still runs.
     */
    public function test_a_duplicate_registration_inside_do_action_does_not_stop_other_listeners(): void
    {
        $first = $this->makeFilter('provider');

        add_action('course_discovery/register_filters', function (FilterRegistry $registry) use ($first): void {
            $registry->register($first);
        }, 10);

        add_action('course_discovery/register_filters', function (FilterRegistry $registry): void {
            // A conflicting extension also wants the 'provider' key.
            $registry->register($this->makeFilter('provider'));
        }, 20);

        add_action('course_discovery/register_filters', function (FilterRegistry $registry): void {
            // A well-behaved extension queued behind the conflicting one.
            $registry->register($this->makeFilter('location'));
        }, 30);

        $this->setExpectedIncorrectUsage(FilterRegistry::class . '::register');

        $registry = FilterRegistry::boot();

        self::assertSame(
            $first,
            $registry->get('provider'),
            'The first-registered "provider" filter must win over the conflicting one.'
        );
        self::assertTrue(
            $registry->has('location'),
            'A listener registered after the conflict must still run -- the request must not abort mid do_action().'
        );
        self::assertCount(2, $registry);
    }

    public function test_third_party_code_can_register_through_the_action(): void
    {
        add_action('course_discovery/register_filters', function (FilterRegistry $registry): void {
            $registry->register($this->makeFilter('delivery_mode'));
        });

        $registry = FilterRegistry::boot();

        self::assertTrue(
            $registry->has('delivery_mode'),
            'A filter must be addable without modifying any existing file.'
        );
    }

    /**
     * boot()'s documented promise: an extension listening on
     * ACTION_REGISTER must see core filters that were passed to boot()
     * already present, so it can make decisions based on what core
     * already offers (e.g. "add me only if 'provider' isn't already
     * registered").
     */
    public function test_core_filters_are_visible_to_the_action_before_it_fires(): void
    {
        $core = $this->makeFilter('provider');

        $sawCoreFilter = false;

        add_action('course_discovery/register_filters', function (FilterRegistry $registry) use (&$sawCoreFilter): void {
            $sawCoreFilter = $registry->has('provider');
        });

        FilterRegistry::boot($core);

        self::assertTrue(
            $sawCoreFilter,
            'A core filter passed to boot() must already be present when the action fires.'
        );
    }

    public function test_boot_returns_core_then_extension_filters_in_that_order(): void
    {
        add_action('course_discovery/register_filters', function (FilterRegistry $registry): void {
            $registry->register($this->makeFilter('delivery_mode'));
        });

        $registry = FilterRegistry::boot($this->makeFilter('provider'), $this->makeFilter('location'));

        $keys = array_map(static fn (FilterKey $k): string => $k->toString(), $registry->keys());

        self::assertSame(['provider', 'location', 'delivery_mode'], $keys);
    }
}
