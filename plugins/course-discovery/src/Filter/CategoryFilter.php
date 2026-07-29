<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter;

use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\Support\TermOptions;
use CourseDiscovery\Index\Attribute;

final class CategoryFilter implements Filter
{
    public const KEY = 'category';

    private TermOptions $options;

    public function __construct(?TermOptions $options = null)
    {
        $this->options = $options ?? new TermOptions(Taxonomies::CATEGORY, hierarchical: true);
    }

    public function key(): FilterKey
    {
        return FilterKey::fromString(self::KEY);
    }

    public function label(): string
    {
        return __('Category', 'course-discovery');
    }

    public function inputType(): FilterInputType
    {
        return FilterInputType::CheckboxGroup;
    }

    public function description(): ?string
    {
        return null;
    }

    public function options(?SearchCriteria $context = null): FilterOptions
    {
        return FilterOptionsHook::apply(self::KEY, $this->options->build());
    }

    public function constrain(FilterValues $values): ?Constraint
    {
        return AttributeInConstraint::fromValues(Attribute::Category->value, $values);
    }
}
