<?php

declare(strict_types=1);

namespace CourseDiscoveryExample;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Filter\FilterOptionsHook;
use CourseDiscovery\Filter\Support\PostTypeOptions;

/**
 * Demonstrates the Open/Closed principle in practice: a filter
 * for a dimension the core plugin never anticipated (instructor), added
 * entirely from a SEPARATE plugin through course-discovery's public hooks
 * and public classes -- CourseDiscovery\Domain\Filter\Filter,
 * CourseDiscovery\Filter\FilterRegistry (via the
 * course_discovery/register_filters action, see the plugin bootstrap
 * file), CourseDiscovery\Filter\Support\PostTypeOptions,
 * CourseDiscovery\Filter\FilterOptionsHook, and
 * CourseDiscovery\Domain\Constraint\AttributeInConstraint. Nothing under
 * plugins/course-discovery/src/ changes to make this class work -- see
 * tests/Integration/Filter/ExampleExtensionTest.php for the mechanical
 * proof.
 *
 * 'instructor' is a genuinely NEW attribute dimension, not one of the four
 * cases the core CourseDiscovery\Index\Attribute enum enumerates
 * (provider|location|start|category). No schema migration is needed: the
 * attribute table is keyed by an attribute NAME, not a column per dimension
 * (see AttributeInConstraint's own docblock) -- this filter simply
 * names an attribute ('instructor') that only this extension ever writes to,
 * via the course_discovery/indexed_course listener in the plugin bootstrap
 * file, which calls the core indexer's PUBLIC
 * CourseIndexer::addAttributeValues().
 */
final class InstructorFilter implements Filter
{
    public const KEY = 'instructor';

    /**
     * The attribute name this filter reads (in constrain()) and the
     * course_discovery/indexed_course listener writes (in the plugin
     * bootstrap file). Named once here so the two call sites cannot drift
     * apart.
     */
    public const ATTRIBUTE = 'instructor';

    private PostTypeOptions $options;

    public function __construct(?PostTypeOptions $options = null)
    {
        $this->options = $options ?? new PostTypeOptions(PostTypes::INSTRUCTOR);
    }

    public function key(): FilterKey
    {
        return FilterKey::fromString(self::KEY);
    }

    public function label(): string
    {
        return __('Instructor', 'course-discovery-example');
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
        return AttributeInConstraint::fromValues(self::ATTRIBUTE, $values);
    }
}
