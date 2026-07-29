<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Constraint;

use Countable;

/**
 * Constraints composed with AND.
 *
 * Immutable: add() returns a new set, so a hook handler cannot mutate a set
 * another handler is holding.
 */
final readonly class ConstraintSet implements Countable
{
    /**
     * @param list<Constraint> $constraints
     */
    private function __construct(private array $constraints)
    {
    }

    public static function of(Constraint ...$constraints): self
    {
        return new self(array_values($constraints));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function add(Constraint $constraint): self
    {
        return new self([...$this->constraints, $constraint]);
    }

    /**
     * @return list<Constraint>
     */
    public function all(): array
    {
        return $this->constraints;
    }

    public function count(): int
    {
        return count($this->constraints);
    }
}
