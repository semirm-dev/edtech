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
 * Renders the form's controls -- not the form.
 *
 * Shortcode owns the `<form method="get">` element itself, because it is also
 * the layout grid: the hero, the facet panel and the sort control sit in
 * different grid areas and all three must submit together. This class renders
 * those three regions independently (renderHero(), renderFilters(),
 * renderSortControl()) for Shortcode to place.
 *
 * Every control is a plain form control that works with JavaScript disabled:
 * the facets are `<select multiple>`/checkboxes applied by a submit button,
 * and sort is a real `<select>` in the form. course-discovery.js does layer
 * on top of two of them -- it upgrades each `<select multiple>` to an ARIA
 * combobox and auto-submits on sort change -- so the markup here carries what
 * that script needs to hook onto (`data-cd-sort`, the `<label>` id
 * renderSelectMultiple() documents). None of it is load-bearing: with the
 * script absent, every control still submits the same values.
 *
 * Every focusable input carries a `<label for="...">` for screen readers;
 * hidden inputs deliberately have no `id` since they're never focusable.
 */
final class FormRenderer
{
    public function __construct(private readonly SearchUrls $urls)
    {
    }

    /**
     * The hero search region: the free-text field and the primary submit.
     *
     * Separate from renderFilters() because Shortcode places the two in
     * different grid areas -- the hero spans the full width above both
     * columns. Preserved params ride along here so they sit inside the form
     * exactly once -- they have no visual position of their own, so they
     * belong here as hidden state.
     */
    public function renderHero(FilterRegistry $registry, SearchCriteria $criteria): string
    {
        $keyword = $this->keywordFilter($registry);

        $html = '<div class="cd-search-hero">';

        if ($keyword !== null) {
            $html .= $this->renderText($keyword, $criteria);
        }

        $html .= '<button type="submit" class="cd-search-submit">'
            . esc_html__('Search', 'course-discovery') . '</button>';
        $html .= '</div>';
        $html .= $this->renderPreservedParams($registry);

        return $html;
    }

    /**
     * The facet panel: every filter except the one that owns the free-text
     * term, wrapped in a <details> so it can collapse on a narrow viewport.
     *
     * $activeCount drives the summary's "(N)" and is supplied by
     * ActiveFiltersRenderer::activeCount(), so the panel header and the
     * chips can never disagree about what counts as applied.
     */
    public function renderFilters(FilterRegistry $registry, SearchCriteria $criteria, int $activeCount = 0): string
    {
        $html = '<details class="cd-filters" open>';
        $html .= '<summary class="cd-filters-summary">'
            . esc_html($this->filtersSummary($activeCount)) . '</summary>';
        $html .= '<div class="cd-filters-body">';

        foreach ($registry->all() as $filter) {
            if ($filter->key()->queryParam() === SearchCriteria::PARAM_TERM) {
                continue;
            }

            $html .= $this->renderFieldset($filter, $criteria);
        }

        $html .= '<div class="cd-search-actions">';
        $html .= '<button type="submit" class="cd-apply-filters">'
            . esc_html__('Apply filters', 'course-discovery') . '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</details>';

        return $html;
    }

    private function filtersSummary(int $activeCount): string
    {
        if ($activeCount < 1) {
            return __('Filters', 'course-discovery');
        }

        return sprintf(
            /* translators: %s: number of applied filters, already localised. */
            __('Filters (%s)', 'course-discovery'),
            number_format_i18n($activeCount)
        );
    }

    /**
     * The filter that owns the free-text term, or null when none is
     * registered.
     *
     * Matched on the key rather than on FilterInputType::Text so a
     * third-party text filter registered via
     * course_discovery/register_filters lands in the facet panel with the
     * others instead of silently taking over the hero search field.
     */
    private function keywordFilter(FilterRegistry $registry): ?Filter
    {
        return $registry->get(SearchCriteria::PARAM_TERM);
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
         *
         * Visually hidden, not removed: the enclosing <fieldset>'s <legend>
         * already shows this exact text, so on screen the two were the same
         * word printed twice. The element has to stay in the DOM for the
         * aria-labelledby above to have anything to point at, and
         * .cd-visually-hidden keeps it available to assistive technology --
         * which is the only consumer it ever had.
         */
        $labelId = $id . '-label';

        [$describedBy, $description] = $this->renderFilterDescription($id, $filter->description(), 'span');

        $html = '<label class="cd-visually-hidden" id="' . esc_attr($labelId) . '" for="'
            . esc_attr($id) . '">' . esc_html($filter->label()) . '</label> ';
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
     * The sort control.
     *
     * A real <select> inside the form, so with JavaScript off it applies on
     * the next submit like any other field. course-discovery.js adds a
     * change listener scoped to this element -- see its own comment for why
     * the listener must not sit on the form.
     *
     * This replaces the hidden input that used to preserve a non-default
     * sort across a submit: the select now carries `sort` on every request,
     * so a hidden field would be a duplicate of the same name.
     */
    public function renderSortControl(SearchCriteria $criteria): string
    {
        $id = 'cd-sort';

        $html = '<div class="cd-sort">';
        $html .= '<label for="' . esc_attr($id) . '">'
            . esc_html__('Sort by', 'course-discovery') . '</label> ';
        $html .= '<select id="' . esc_attr($id) . '" name="'
            . esc_attr(SearchCriteria::PARAM_SORT) . '" data-cd-sort>';

        foreach (SortOrder::cases() as $sort) {
            $selected = $sort === $criteria->sort ? ' selected' : '';
            $html .= '<option value="' . esc_attr($sort->value) . '"' . $selected . '>'
                . esc_html($this->sortLabel($sort)) . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Human labels for SortOrder.
     *
     * They live here rather than on the enum because src/Domain/ must
     * contain no WordPress and __() is WordPress -- DomainPurityTest
     * enforces it. A match on the enum (not a map keyed by ->value) means a
     * new case is a compile-time error here rather than a silently
     * unlabelled option.
     */
    private function sortLabel(SortOrder $sort): string
    {
        return match ($sort) {
            SortOrder::Soonest => __('Starting soonest', 'course-discovery'),
            SortOrder::PriceAscending => __('Price: low to high', 'course-discovery'),
            SortOrder::Title => __('Title A–Z', 'course-discovery'),
        };
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
        $known = $this->urls->knownKeys($registry);

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
}
