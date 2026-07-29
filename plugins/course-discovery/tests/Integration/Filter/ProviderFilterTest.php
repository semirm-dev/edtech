<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Filter\ProviderFilter;
use CourseDiscovery\Tests\Support\FilterContractTestCase;

final class ProviderFilterTest extends FilterContractTestCase
{
    private int $providerId;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var int $id */
        $id = self::factory()->post->create([
            'post_type'   => PostTypes::PROVIDER,
            'post_title'  => 'University of Sunderland',
            'post_status' => 'publish',
        ]);

        $this->providerId = $id;
    }

    protected function makeFilter(): Filter
    {
        return new ProviderFilter();
    }

    protected function validValues(): FilterValues
    {
        return FilterValues::fromInts([$this->providerId]);
    }

    public function test_it_is_a_checkbox_group(): void
    {
        self::assertSame(FilterInputType::CheckboxGroup, (new ProviderFilter())->inputType());
    }

    public function test_it_offers_published_providers_as_options(): void
    {
        $options = (new ProviderFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains('University of Sunderland', $labels);
    }

    public function test_it_constrains_on_the_provider_attribute(): void
    {
        $constraint = (new ProviderFilter())->constrain(FilterValues::fromInts([12, 47]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('provider', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    public function test_non_numeric_values_are_discarded_not_coerced(): void
    {
        self::assertNull(
            (new ProviderFilter())->constrain(FilterValues::fromStrings(['abc', '0'])),
            'Values that resolve to nothing must omit the filter, not match id 0.'
        );
    }

    public function test_options_can_be_altered_by_a_hook(): void
    {
        add_filter(
            'course_discovery/filter_options/provider',
            static fn (): \CourseDiscovery\Domain\Filter\FilterOptions =>
                \CourseDiscovery\Domain\Filter\FilterOptions::fromArray([
                    new \CourseDiscovery\Domain\Filter\FilterOption('99', 'Injected'),
                ])
        );

        $options = (new ProviderFilter())->options();

        self::assertCount(1, $options);
    }

    /**
     * FilterOptionsHook::apply() must degrade to the unmodified options
     * whenever a third-party hook misbehaves, so a broken hook cannot fatal
     * a public page. Proven here with a hook returning a bare string.
     */
    public function test_options_survive_a_hook_returning_a_string(): void
    {
        add_filter(
            'course_discovery/filter_options/provider',
            static fn (): string => 'not-a-filter-options-object'
        );

        $options = (new ProviderFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains(
            'University of Sunderland',
            $labels,
            'A hook returning a string must not discard the original, unmodified options.'
        );
    }

    /**
     * Same degradation guarantee as above, this time with a hook returning
     * an array -- a different non-FilterOptions shape a careless third
     * party might plausibly return.
     */
    public function test_options_survive_a_hook_returning_an_array(): void
    {
        add_filter(
            'course_discovery/filter_options/provider',
            static fn (): array => ['unexpected', 'array', 'shape']
        );

        $options = (new ProviderFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains(
            'University of Sunderland',
            $labels,
            'A hook returning an array must not discard the original, unmodified options.'
        );
    }
}
