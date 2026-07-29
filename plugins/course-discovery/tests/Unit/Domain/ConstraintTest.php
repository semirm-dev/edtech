<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Domain\Filter\FilterValues;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
    public function test_a_attribute_constraint_holds_its_values(): void
    {
        $constraint = new AttributeInConstraint('provider', [12, 47]);

        self::assertSame('provider', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    public function test_a_attribute_constraint_rejects_an_empty_value_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AttributeInConstraint('provider', []);
    }

    public function test_from_values_builds_a_constraint_from_valid_ids(): void
    {
        $constraint = AttributeInConstraint::fromValues('provider', FilterValues::fromInts([12, 47]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('provider', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    public function test_from_values_returns_null_when_every_value_is_invalid(): void
    {
        self::assertNull(AttributeInConstraint::fromValues('provider', FilterValues::fromStrings(['0', 'abc'])));
    }

    public function test_from_values_returns_null_for_an_empty_selection(): void
    {
        self::assertNull(AttributeInConstraint::fromValues('provider', FilterValues::empty()));
    }

    public function test_a_constraint_set_is_immutable(): void
    {
        $first = ConstraintSet::of(new AttributeInConstraint('provider', [12]));
        $second = $first->add(new AttributeInConstraint('location', [3]));

        self::assertCount(1, $first, 'add() must not mutate the original.');
        self::assertCount(2, $second);
    }

    public function test_search_text_is_trimmed(): void
    {
        self::assertSame('graphic design', (new SearchTextConstraint('  graphic design '))->terms);
    }

    public function test_search_text_rejects_an_empty_term(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchTextConstraint('   ');
    }
}
