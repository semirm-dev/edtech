<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Filter\LocationFilter;
use CourseDiscovery\Tests\Support\FilterContractTestCase;

final class LocationFilterTest extends FilterContractTestCase
{
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var int $locationId */
        $locationId = self::factory()->term->create([
            'taxonomy' => Taxonomies::LOCATION,
            'name'     => 'Sunderland',
        ]);

        $this->locationId = $locationId;
    }

    protected function makeFilter(): Filter
    {
        return new LocationFilter();
    }

    protected function validValues(): FilterValues
    {
        return FilterValues::fromInts([$this->locationId]);
    }

    public function test_it_is_a_combobox(): void
    {
        self::assertSame(FilterInputType::ComboboxMulti, (new LocationFilter())->inputType());
    }

    public function test_a_providers_location_appears_as_an_option(): void
    {
        $options = (new LocationFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains('Sunderland', $labels);
    }

    public function test_it_constrains_on_the_location_attribute(): void
    {
        $constraint = (new LocationFilter())->constrain(FilterValues::fromInts([12, 47]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('location', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    public function test_non_numeric_values_are_discarded_not_coerced(): void
    {
        self::assertNull(
            (new LocationFilter())->constrain(FilterValues::fromStrings(['abc', '0'])),
            'Values that resolve to nothing must omit the filter, not match id 0.'
        );
    }

    public function test_location_labels_have_no_hierarchy_indentation(): void
    {
        $options = (new LocationFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        // Guard against the assertion below being vacuously true: the
        // fixture location created in setUp() must actually be present.
        self::assertContains('Sunderland', $labels);

        foreach ($labels as $label) {
            self::assertFalse(
                str_starts_with($label, "\u{00A0}"),
                sprintf(
                    'Location option "%s" must not carry hierarchy indentation; cd_location is flat, unlike cd_category.',
                    $label
                )
            );
        }
    }
}
