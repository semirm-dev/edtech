<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\Search\SearchService;

/**
 * [course_discovery] — the whole discovery UI on any page.
 *
 * A shortcode rather than a template override so the plugin works with any
 * theme and needs no template files.
 *
 * One attribute: [course_discovery per_page="24"].
 */
final readonly class Shortcode
{
    public const TAG = 'course_discovery';

    public const ATTR_PER_PAGE = 'per_page';

    /**
     * Deliberately tighter than Pagination::MAX_PER_PAGE (100), which is
     * the domain's absolute ceiling. This is the bound on what an editor
     * can ask a PUBLIC page to render in one go: every course on the page
     * costs a permalink lookup and widens the provider/location lookups
     * (see AttributeLabels), so the cap is set where a page still renders
     * quickly rather than where the value object stops complaining.
     */
    private const MIN_PER_PAGE = 1;
    private const MAX_PER_PAGE = 50;

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

    /**
     * WordPress passes '' rather than an empty array when a shortcode is
     * written without attributes, hence the mixed parameter and the
     * is_array() guard -- shortcode_atts() would otherwise be handed a
     * string.
     */
    public function render(mixed $atts = []): string
    {
        $attributes = shortcode_atts(
            [self::ATTR_PER_PAGE => ''],
            is_array($atts) ? $atts : [],
            self::TAG
        );

        // $_GET is the request; SearchCriteria::fromQueryParams reads only
        // known filter keys and clamps pagination, so raw access here is
        // deliberate and safe. wp_unslash undoes WordPress's magic quotes.
        /** @var array<string, mixed> $params */
        $params = wp_unslash($_GET);

        $criteria = $this->service->criteriaFromParams(
            $params,
            self::perPageFrom($attributes[self::ATTR_PER_PAGE])
        );
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

    /**
     * The `per_page` attribute as a usable page size, or null for "not
     * specified" -- which leaves the default (Pagination::default()) in
     * place.
     *
     * Null and clamping are different answers to different mistakes.
     * `per_page="0"` and `per_page="9999"` are requests for a page size,
     * just out of range, so they clamp to the nearest allowed one.
     * `per_page="abc"` is not a page size at all, and answering it with the
     * minimum -- one course per page -- would be a far more confusing
     * result than the usual twelve.
     *
     * Nothing here throws: this is editor input on a public page, and
     * Pagination's constructor DOES throw on out-of-range values (see its
     * docblock), so the clamping has to happen before the value object is
     * ever built.
     *
     * Static and free of WordPress so the rule can be pinned directly --
     * proving the ceiling through an integration test would mean seeding
     * fifty-one courses.
     */
    public static function perPageFrom(mixed $raw): ?int
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        // (int) saturates at PHP_INT_MAX/PHP_INT_MIN for digit strings
        // beyond them rather than erroring, so even absurd input lands in
        // range here instead of reaching the query layer.
        return max(self::MIN_PER_PAGE, min((int) $value, self::MAX_PER_PAGE));
    }
}
