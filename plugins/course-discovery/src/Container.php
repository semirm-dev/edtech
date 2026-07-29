<?php

declare(strict_types=1);

namespace CourseDiscovery;

use RuntimeException;

/**
 * A deliberately tiny service locator — not a general-purpose DI
 * container. This plugin has a handful of singletons with a fixed graph;
 * services are registered as factories and resolved once.
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * Ids currently mid-resolution (outermost first), so get() can detect
     * a factory that requires its own output and fail with a clear cycle
     * error instead of recursing until the stack overflows.
     *
     * @var list<string>
     */
    private array $resolving = [];

    /**
     * @param callable(self): object $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->resolved[$id]);
    }

    /**
     * @template T of object
     * @param  class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->resolved[$id])) {
            $instance = $this->resolved[$id];
        } else {
            if (! isset($this->factories[$id])) {
                throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
            }

            if (in_array($id, $this->resolving, true)) {
                throw new RuntimeException(sprintf(
                    'Circular dependency detected while resolving "%s": %s -> %s.',
                    $id,
                    implode(' -> ', $this->resolving),
                    $id
                ));
            }

            $this->resolving[] = $id;

            try {
                $instance = ($this->factories[$id])($this);
            } finally {
                array_pop($this->resolving);
            }

            $this->resolved[$id] = $instance;
        }

        if (! $instance instanceof $id) {
            throw new RuntimeException(sprintf('Service "%s" resolved to the wrong type.', $id));
        }

        return $instance;
    }
}
