<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Frontend\FormRenderer;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Filter::description() is documented as aria-describedby copy
 * for a combobox, but renderSelectMultiple() never emitted it -- only
 * renderText() did. Both core comboboxes (location, start_date) return null
 * from description(), so the gap was dormant; this pins the fix with a stub
 * combobox filter that actually returns one, the same way
 * ExampleExtensionTest::test_the_instructor_filter_renders_into_the_search_form
 * drives FormRenderer directly through a hand-built FilterRegistry.
 */
final class FormRendererTest extends IntegrationTestCase
{
    public function test_a_combobox_filters_description_is_rendered_and_referenced(): void
    {
        $registry = new FilterRegistry();
        $registry->register(new class ('Pick one or more values.') implements Filter {
            public function __construct(private readonly ?string $description)
            {
            }

            public function key(): FilterKey
            {
                return FilterKey::fromString('stub_combo');
            }

            public function label(): string
            {
                return 'Stub Combo';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::ComboboxMulti;
            }

            public function description(): ?string
            {
                return $this->description;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([new FilterOption('1', 'First')]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        $html = (new FormRenderer())->render($registry, SearchCriteria::empty());

        self::assertStringContainsString(
            '<span class="cd-filter-description" id="cd-filter-stub_combo-description">Pick one or more values.</span>',
            $html,
            'Expected the combobox description text to be rendered.'
        );
        self::assertStringContainsString(
            'aria-describedby="cd-filter-stub_combo-description"',
            $html,
            'Expected the <select> to reference the description via aria-describedby.'
        );
        self::assertMatchesRegularExpression(
            '/<select[^>]*aria-describedby="cd-filter-stub_combo-description"[^>]*>/',
            $html,
            'Expected aria-describedby to be an attribute of the <select multiple> element itself.'
        );
    }

    public function test_a_combobox_filter_with_no_description_renders_none(): void
    {
        $registry = new FilterRegistry();
        $registry->register(new class implements Filter {
            public function key(): FilterKey
            {
                return FilterKey::fromString('stub_combo_no_desc');
            }

            public function label(): string
            {
                return 'Stub Combo No Description';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::ComboboxMulti;
            }

            public function description(): ?string
            {
                return null;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([new FilterOption('1', 'First')]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        $html = (new FormRenderer())->render($registry, SearchCriteria::empty());

        self::assertStringNotContainsString('cd-filter-description', $html);
        self::assertStringNotContainsString('aria-describedby', $html);
    }
}
