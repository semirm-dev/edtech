<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Filter\FilterRegistry;

/**
 * The "currently applied" chips: one per selected value, each a link that
 * removes just that value, plus the "Clear all" escape hatch.
 *
 * Labels come from the filter's own options(), which the form has already
 * built for this request, so chips add no queries. A selected value with no
 * matching option -- a stale term id in a bookmarked URL whose term has since
 * been deleted -- gets no chip rather than being rendered as a raw slug.
 *
 * It does still CONSTRAIN the query, though, so the block itself renders
 * whenever the request carries any filter state at all, not merely when a
 * chip came out of it: a stale value narrows the results to nothing, and
 * returning '' there would leave the visitor looking at an empty result set
 * with no way back -- precisely where "Clear all" matters most. Hence the
 * three-part test in render(): chips, explicitly selected filter keys, and
 * the free-text term.
 *
 * The free-text term gets no chip: it is already visible in the hero search
 * field, and a chip would be a second, separately-removable copy of the same
 * state. It does count as filter state for the test above, since "Clear all"
 * clears it too. Sort is not a filter and gets no chip either.
 */
final class ActiveFiltersRenderer
{
    /**
     * Memoised chips() result and the arguments it was computed for.
     *
     * activeCount() and render() are both called once per request, with the
     * same registry and criteria (see Shortcode::render()), and each needs
     * the same chips. Without this, chips() ran twice and so did every
     * filter's options() -- and ProviderFilter::options() fires the PUBLIC
     * course_discovery/filter_options/{key} hook, so third-party callbacks
     * would observe an extra call purely because of how this class is
     * structured. Keyed rather than unconditional so a second call with
     * different arguments recomputes instead of returning the first answer.
     *
     * @var list<array{filter: Filter, value: string, label: string}>|null
     */
    private ?array $memoisedChips = null;

    private ?string $memoisedFor = null;

    public function __construct(private readonly SearchUrls $urls)
    {
    }

    public function render(FilterRegistry $registry, SearchCriteria $criteria, string $baseUrl): string
    {
        $chips = $this->chips($registry, $criteria);

        if ($chips === [] && $criteria->activeFilterKeys() === [] && $criteria->term === null) {
            return '';
        }

        $html = '<div class="cd-active-filters">';
        $html .= '<h2 class="cd-active-filters-heading">'
            . esc_html__('Applied filters', 'course-discovery') . '</h2>';

        // Only when there is something to list: an empty <ul> is a list of
        // nothing, which assistive technology announces as such.
        if ($chips !== []) {
            $html .= '<ul class="cd-active-filters-list">';

            foreach ($chips as $chip) {
                $html .= '<li>' . $this->renderChip($chip, $criteria, $baseUrl) . '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '<a class="cd-clear-filters" href="'
            . esc_url($this->urls->clearFilters($registry, $baseUrl)) . '">'
            . esc_html__('Clear all', 'course-discovery') . '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * How many chips render() would emit.
     *
     * Shared with FormRenderer's "Filters (N)" summary through
     * Shortcode, so the panel header and the chips can never disagree
     * about what counts as an applied filter.
     */
    public function activeCount(FilterRegistry $registry, SearchCriteria $criteria): int
    {
        return count($this->chips($registry, $criteria));
    }

    /**
     * @return list<array{filter: Filter, value: string, label: string}>
     */
    private function chips(FilterRegistry $registry, SearchCriteria $criteria): array
    {
        // Registry identity plus the criteria's own URL serialisation: two
        // criteria that serialise identically produce identical chips, and
        // the registry is built once at boot and never changes mid-request.
        $key = spl_object_id($registry) . '|' . serialize($criteria->toQueryParams());

        if ($this->memoisedFor === $key && $this->memoisedChips !== null) {
            return $this->memoisedChips;
        }

        $chips = $this->buildChips($registry, $criteria);

        $this->memoisedFor = $key;
        $this->memoisedChips = $chips;

        return $chips;
    }

    /**
     * @return list<array{filter: Filter, value: string, label: string}>
     */
    private function buildChips(FilterRegistry $registry, SearchCriteria $criteria): array
    {
        $chips = [];

        foreach ($registry->all() as $filter) {
            if ($filter->key()->queryParam() === SearchCriteria::PARAM_TERM) {
                continue;
            }

            $selected = $criteria->valuesFor($filter->key())->toStrings();

            if ($selected === []) {
                continue;
            }

            $labels = $this->optionLabels($filter, $criteria);

            foreach ($selected as $value) {
                if (! isset($labels[$value])) {
                    continue;
                }

                $chips[] = ['filter' => $filter, 'value' => $value, 'label' => $labels[$value]];
            }
        }

        return $chips;
    }

    /**
     * @return array<string, string>
     */
    private function optionLabels(Filter $filter, SearchCriteria $criteria): array
    {
        $labels = [];

        /** @var FilterOption $option */
        foreach ($filter->options($criteria) as $option) {
            $labels[$option->value] = $option->label;
        }

        return $labels;
    }

    /**
     * @param array{filter: Filter, value: string, label: string} $chip
     */
    private function renderChip(array $chip, SearchCriteria $criteria, string $baseUrl): string
    {
        $url = $this->removalUrl($chip['filter'], $chip['value'], $criteria, $baseUrl);

        $accessibleName = sprintf(
            /* translators: %s: the filter value being removed, e.g. "Oxford". */
            __('Remove filter: %s', 'course-discovery'),
            $chip['label']
        );

        return '<a class="cd-chip" href="' . esc_url($url) . '" aria-label="'
            . esc_attr($accessibleName) . '">'
            . esc_html($chip['label'])
            . '<span class="cd-chip-remove" aria-hidden="true">&times;</span>'
            . '</a>';
    }

    /**
     * Rebuilds the query with one value dropped by going through
     * SearchCriteria rather than editing the query string: withFilter()
     * already knows to unset a key whose values are now empty, and
     * toQueryParams() already knows which defaults to omit.
     *
     * Page resets to 1 -- removing a filter widens the result set, so page 7
     * of the old, narrower one is meaningless and may not exist.
     */
    private function removalUrl(
        Filter $filter,
        string $value,
        SearchCriteria $criteria,
        string $baseUrl
    ): string {
        $remaining = array_values(array_filter(
            $criteria->valuesFor($filter->key())->toStrings(),
            static fn (string $candidate): bool => $candidate !== $value
        ));

        $params = $criteria
            ->withFilter($filter->key(), FilterValues::fromStrings($remaining))
            ->withPagination(new Pagination(1, $criteria->pagination->perPage))
            ->toQueryParams();

        return add_query_arg($params, $baseUrl);
    }
}
