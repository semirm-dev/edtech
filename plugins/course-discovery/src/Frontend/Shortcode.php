<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Search\SearchService;

/**
 * [course_discovery] — the whole discovery UI on any page.
 *
 * A shortcode rather than a template override so the plugin works with any
 * theme and needs no template files.
 */
final readonly class Shortcode
{
    public const TAG = 'course_discovery';

    public function __construct(
        private SearchService $service,
        private FormRenderer $form,
        private ResultsRenderer $results,
        private ActiveFiltersRenderer $activeFilters,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    public function render(): string
    {
        // $_GET is the request; SearchCriteria::fromQueryParams reads only
        // known filter keys and clamps pagination, so raw access here is
        // deliberate and safe. wp_unslash undoes WordPress's magic quotes.
        /** @var array<string, mixed> $params */
        $params = wp_unslash($_GET);

        $criteria = $this->service->criteriaFromParams($params);
        $result = $this->service->search($criteria);

        // The base pagination links resolve against -- see ResultsRenderer's
        // own docblock for why this must be threaded through explicitly
        // rather than left for add_query_arg() to infer from REQUEST_URI.
        // get_permalink() (no argument) reads the current global $post,
        // which is exactly the page this shortcode is rendering on; a
        // shortcode invoked outside of any post context (or on a page
        // get_permalink() cannot resolve) falls back to the site's home URL
        // rather than producing an unusable pagination link.
        $permalink = get_permalink();
        $baseUrl = $permalink !== false ? $permalink : home_url('/');

        $registry = $this->service->registry;
        $activeCount = $this->activeFilters->activeCount($registry, $criteria);

        return '<form class="cd-discovery cd-search-form" method="get" data-cd-root>'
            . $this->form->renderHero($registry, $criteria)
            . $this->activeFilters->render($registry, $criteria, $baseUrl)
            . $this->form->renderFilters($registry, $criteria, $activeCount)
            . '<div class="cd-results-region">'
            . '<div class="cd-toolbar">'
            . $this->results->renderCount($result)
            . $this->form->renderSortControl($criteria)
            . '</div>'
            . $this->results->render($result, $criteria, $baseUrl)
            . '</div>'
            . '</form>';
    }
}
