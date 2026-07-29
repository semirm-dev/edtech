<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

/**
 * How a filter should be rendered.
 *
 * Filters declare their input type rather than their markup, so the renderer
 * stays the only thing that knows about HTML and a filter stays testable
 * without a DOM. Locations and start dates render as dropdown comboboxes;
 * providers and categories as checkbox groups.
 */
enum FilterInputType: string
{
    case Text = 'text';
    case CheckboxGroup = 'checkbox_group';
    case ComboboxMulti = 'combobox_multi';
}
