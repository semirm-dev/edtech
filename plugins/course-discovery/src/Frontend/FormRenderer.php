<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\SortOrder;
use CourseDiscovery\Filter\FilterRegistry;

/**
 * Renders the filter form.
 *
 * A real `<form method="get">` with a submit button, so every feature works
 * with JavaScript disabled; a progressive-enhancement script may layer on
 * top later, but nothing here depends on it. Every focusable input carries
 * a `<label for="...">` for screen readers; hidden inputs deliberately have
 * no `id` since they're never focusable.
 */
final class FormRenderer
{
    public function render(FilterRegistry $registry, SearchCriteria $criteria): string
    {
        $html = '<form method="get" class="cd-search-form">';

        foreach ($registry->all() as $filter) {
            $html .= $this->renderFieldset($filter, $criteria);
        }

        $html .= $this->renderSortState($criteria);
        $html .= $this->renderPreservedParams($registry);

        $html .= '<div class="cd-search-actions">';
        $html .= '<button type="submit" class="cd-search-submit">'
            . esc_html__('Search', 'course-discovery') . '</button>';
        $html .= ' <a class="cd-clear-filters" href="' . esc_url($this->clearFiltersUrl($registry)) . '">'
            . esc_html__('Clear filters', 'course-discovery') . '</a>';
        $html .= '</div>';

        $html .= '</form>';

        return $html;
    }

    private function renderFieldset(Filter $filter, SearchCriteria $criteria): string
    {
        $key = $filter->key()->toString();

        $html = '<fieldset class="cd-filter cd-filter-' . esc_attr($key) . '">';
        $html .= '<legend>' . esc_html($filter->label()) . '</legend>';

        $html .= match ($filter->inputType()) {
            FilterInputType::Text => $this->renderText($filter, $criteria),
            FilterInputType::CheckboxGroup => $this->renderCheckboxGroup($filter, $criteria),
            FilterInputType::ComboboxMulti => $this->renderSelectMultiple($filter, $criteria),
        };

        $html .= '</fieldset>';

        return $html;
    }

    private function renderText(Filter $filter, SearchCriteria $criteria): string
    {
        $key = $filter->key()->toString();
        $id = 'cd-filter-' . $key;
        $value = $this->currentTextValue($filter, $criteria);

        [$describedBy, $description] = $this->renderFilterDescription($id, $filter->description(), 'p');

        $html = '<label for="' . esc_attr($id) . '">' . esc_html($filter->label()) . '</label> ';
        $html .= '<input type="search" id="' . esc_attr($id) . '" name="' . esc_attr($key) . '" value="'
            . esc_attr($value) . '"' . $describedBy . ' />';
        $html .= $description;

        return $html;
    }

    /**
     * Builds the aria-describedby id and its description element; centralised
     * so renderText() and renderSelectMultiple() (which use different wrapper
     * tags) can't drift. Returns empty strings when there is no description,
     * so callers can concatenate unconditionally.
     *
     * @return array{0: string, 1: string} [$describedByAttribute, $descriptionMarkup]
     */
    private function renderFilterDescription(string $id, ?string $description, string $tag): array
    {
        if ($description === null) {
            return ['', ''];
        }

        $descriptionId = $id . '-description';
        $describedBy = ' aria-describedby="' . esc_attr($descriptionId) . '"';
        $markup = '<' . $tag . ' class="cd-filter-description" id="' . esc_attr($descriptionId) . '">'
            . esc_html($description) . '</' . $tag . '>';

        return [$describedBy, $markup];
    }

    private function currentTextValue(Filter $filter, SearchCriteria $criteria): string
    {
        return $criteria->valuesFor($filter->key())->toStrings()[0] ?? '';
    }

    private function renderCheckboxGroup(Filter $filter, SearchCriteria $criteria): string
    {
        $key = $filter->key()->toString();
        $selected = $criteria->valuesFor($filter->key())->toStrings();

        $html = '<div class="cd-filter-options">';
        $index = 0;

        foreach ($filter->options($criteria) as $option) {
            $id = 'cd-filter-' . $key . '-' . $index;
            $checked = in_array($option->value, $selected, true) ? ' checked' : '';

            $html .= '<span class="cd-checkbox">';
            $html .= '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($key)
                . '[]" value="' . esc_attr($option->value) . '"' . $checked . ' />';
            $html .= ' <label for="' . esc_attr($id) . '">' . esc_html($option->label) . '</label>';
            $html .= '</span>';

            $index++;
        }

        $html .= '</div>';

        return $html;
    }

    private function renderSelectMultiple(Filter $filter, SearchCriteria $criteria): string
    {
        $key = $filter->key()->toString();
        $id = 'cd-filter-' . $key;
        $selected = $criteria->valuesFor($filter->key())->toStrings();
        $options = $filter->options($criteria);

        /*
         * course-discovery.js upgrades this <select> to an ARIA combobox
         * trigger <button>, whose accessible name comes from its own content
         * (the value summary), not from `<label for>`. Giving the label an id
         * lets the JS set aria-labelledby="<labelId> <triggerId>" so the name
         * becomes "<field label>, <current value>" instead of just the value.
         * The id is inert with no JS running; `<label for>` is kept too.
         */
        $labelId = $id . '-label';

        [$describedBy, $description] = $this->renderFilterDescription($id, $filter->description(), 'span');

        $html = '<label id="' . esc_attr($labelId) . '" for="' . esc_attr($id) . '">'
            . esc_html($filter->label()) . '</label> ';
        $html .= '<select multiple id="' . esc_attr($id) . '" name="' . esc_attr($key) . '[]" size="'
            . esc_attr((string) $this->selectSize($options)) . '"' . $describedBy . '>';

        foreach ($options as $option) {
            $isSelected = in_array($option->value, $selected, true) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($option->value) . '"' . $isSelected . '>'
                . esc_html($option->label) . '</option>';
        }

        $html .= '</select>';
        $html .= $description;

        return $html;
    }

    private function selectSize(FilterOptions $options): int
    {
        return max(2, min(8, count($options)));
    }

    /**
     * Preserves a non-default sort across a form submit.
     *
     * No-JS has no visible sort control, but a visitor can still arrive via
     * `?sort=price_asc` (e.g. a bookmarked URL). Without this, submitting the
     * form replaces the entire query string and silently resets the sort to
     * the default. Only emitted when the sort differs from SortOrder::Soonest.
     */
    private function renderSortState(SearchCriteria $criteria): string
    {
        $sort = $criteria->sort;

        if ($sort === SortOrder::Soonest) {
            return '';
        }

        return '<input type="hidden" name="' . esc_attr(SearchCriteria::PARAM_SORT) . '" value="'
            . esc_attr($sort->value) . '" />';
    }

    /**
     * The keys SearchCriteria and the registry already model explicitly:
     * anything else present in the current request (e.g. `page_id` on a
     * plain-permalinks site) is "non-filter" state the form must not drop.
     *
     * @return list<string>
     */
    private function knownQueryKeys(FilterRegistry $registry): array
    {
        $keys = [
            SearchCriteria::PARAM_TERM,
            SearchCriteria::PARAM_SORT,
            SearchCriteria::PARAM_PAGE,
        ];

        foreach ($registry->keys() as $key) {
            $keys[] = $key->queryParam();
        }

        return $keys;
    }

    /**
     * A GET submission replaces the entire query string with only the fields
     * it carries, so any current param this plugin doesn't own (e.g. `page_id`
     * on a plain-permalinks site) would otherwise be silently dropped. Reading
     * $_GET directly here is deliberate and safe: only known keys are ever
     * turned into filters (see Shortcode::render()).
     */
    private function renderPreservedParams(FilterRegistry $registry): string
    {
        $known = $this->knownQueryKeys($registry);

        /** @var array<string, mixed> $params */
        $params = wp_unslash($_GET);

        $html = '';

        foreach ($params as $paramKey => $value) {
            if (in_array($paramKey, $known, true)) {
                continue;
            }

            $isList = is_array($value);
            $name = $isList ? $paramKey . '[]' : $paramKey;

            foreach ($this->scalarValues($value) as $scalar) {
                $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($scalar) . '" />';
            }
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    private function scalarValues(mixed $value): array
    {
        if (is_string($value) || is_int($value)) {
            return [(string) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry) || is_int($entry)) {
                $strings[] = (string) $entry;
            }
        }

        return $strings;
    }

    private function clearFiltersUrl(FilterRegistry $registry): string
    {
        return remove_query_arg($this->knownQueryKeys($registry));
    }
}
