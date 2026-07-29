<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Constraint;

use CourseDiscovery\Domain\Filter\FilterValues;
use InvalidArgumentException;

/**
 * Match courses having ANY of the given values for an attribute — the
 * OR-within-a-filter half of the grouping rule. AND-between-filters comes
 * from composing several of these in a ConstraintSet.
 */
final readonly class AttributeInConstraint implements Constraint
{
    /**
     * $attribute is deliberately a bare string, not the Index\Attribute enum: a third
     * party registering its own attribute (see CourseIndexer::addAttributeValues())
     * has no enum case to name and cannot extend an enum it does not own, so
     * the bare string is what makes custom attributes possible at all.
     *
     * @param list<int> $valueIds
     */
    public function __construct(
        public string $attribute,
        public array $valueIds,
    ) {
        if ($attribute === '') {
            throw new InvalidArgumentException('Attribute name cannot be empty.');
        }

        if ($valueIds === []) {
            throw new InvalidArgumentException(
                'A attribute constraint needs at least one value; omit the constraint instead.'
            );
        }
    }

    /**
     * Safe front door for an attribute filter's constrain(): guards on the
     * CONVERTED ids, not $values->isEmpty(). toInts() silently discards any
     * non-positive-integer entry, so a non-empty selection of garbage (e.g.
     * ["' OR 1=1"]) converts to [] -- which the constructor rejects. Checking
     * the converted list here returns null in that case instead of throwing.
     */
    public static function fromValues(string $attribute, FilterValues $values): ?self
    {
        $ids = $values->toInts();

        return $ids === [] ? null : new self($attribute, $ids);
    }

}
