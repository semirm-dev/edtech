<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter;

use Countable;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Plugin;

/**
 * The set of available filters, in display order.
 *
 * Third parties add filters by listening to course_discovery/register_filters
 * and calling register(), so a new filter requires no change to any existing
 * file.
 */
final class FilterRegistry implements Countable
{
    public const ACTION_REGISTER = 'course_discovery/register_filters';

    /** @var array<string, Filter> */
    private array $filters = [];

    /**
     * A duplicate key does not throw. register() runs from inside
     * do_action() (see boot()), and WordPress doesn't catch exceptions
     * from a hook callback — throwing here would fatal the public search
     * page and abort do_action()'s loop, silently dropping every OTHER
     * extension's filters too (the same "must not fatal the page" policy
     * as SearchCriteria::withFilter()).
     *
     * Instead the first registration wins; a later duplicate is dropped
     * with a `_doing_it_wrong()` notice naming the key, so a misbehaving
     * extension is loud in the log without taking the page down — and
     * core filters registered via boot()'s $coreFilters stay authoritative.
     */
    public function register(Filter $filter): void
    {
        $key = $filter->key()->toString();

        if (isset($this->filters[$key])) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'A filter is already registered for key "%s". The first registration wins; this later one was ignored.',
                    $key
                ),
                Plugin::VERSION
            );

            return;
        }

        $this->filters[$key] = $filter;
    }

    public function get(string $key): ?Filter
    {
        return $this->filters[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->filters[$key]);
    }

    /**
     * @return list<Filter>
     */
    public function all(): array
    {
        return array_values($this->filters);
    }

    /**
     * @return list<FilterKey>
     */
    public function keys(): array
    {
        return array_map(static fn (Filter $f): FilterKey => $f->key(), $this->all());
    }

    public function count(): int
    {
        return count($this->filters);
    }

    /**
     * Builds a registry pre-populated with the given core filters, then
     * gives third-party code its turn.
     *
     * $coreFilters is required (even if empty), not an optional
     * pre-populated registry, so core-before-extension ordering can't be
     * skipped by a caller who forgets to pass one. do_action() fires
     * against this same registry, so an extension on ACTION_REGISTER can
     * see everything already registered via has()/get()/all().
     */
    public static function boot(Filter ...$coreFilters): self
    {
        $registry = new self();

        foreach ($coreFilters as $filter) {
            $registry->register($filter);
        }

        do_action(self::ACTION_REGISTER, $registry);

        return $registry;
    }
}
