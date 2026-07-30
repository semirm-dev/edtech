<?php

declare(strict_types=1);

namespace CourseDiscovery\Frontend;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Course;

/**
 * Resolves the provider post ids and location term ids a page of courses
 * carries into display names.
 *
 * Course holds ids and never names: Domain/ may not touch WordPress, so the
 * lookup has to live out here in the adapter layer. It is done once for the
 * whole page in two queries -- one for every provider id on the page, one
 * for every location id -- rather than per card, which would be an N+1 in
 * the results list.
 *
 * Deliberately NOT reusing Filter\Support\PostTypeOptions / TermOptions:
 * those exist to build facets and therefore fetch EVERY provider and EVERY
 * location on the site, and reusing them would make the results list depend
 * on FilterRegistry -- so a course whose provider is missing from the facet
 * list would silently lose its name.
 */
final class AttributeLabels
{
    /**
     * @param iterable<Course> $courses
     */
    public function forPage(iterable $courses): LabelMap
    {
        // Keyed, so an id shared by several courses on the page is asked
        // for once.
        $providerIds = [];
        $locationIds = [];

        foreach ($courses as $course) {
            foreach ($course->providerIds as $providerId) {
                $providerIds[$providerId] = true;
            }

            foreach ($course->locationIds as $locationId) {
                $locationIds[$locationId] = true;
            }
        }

        return new LabelMap(
            $this->providerTitles(array_keys($providerIds)),
            $this->locationNames(array_keys($locationIds))
        );
    }

    /**
     * @param  list<int> $ids
     * @return array<int, string>
     */
    private function providerTitles(array $ids): array
    {
        // WP_Query IGNORES an empty post__in rather than matching nothing,
        // so without this guard a page of courses with no providers would
        // fetch every provider on the site.
        if ($ids === []) {
            return [];
        }

        // The two cache flags are the difference between one query and
        // three: get_posts() otherwise primes the postmeta and the term
        // relationships of every provider it returns, and a provider's
        // title is the only thing read here. Measured on a 12-course page:
        // 3 queries with the defaults, 1 with these.
        $posts = get_posts([
            'post_type'              => PostTypes::PROVIDER,
            'post__in'               => $ids,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $titles = [];

        // No 'fields' key is passed above, so get_posts() is statically
        // guaranteed (by its own conditional return type) to hand back
        // WP_Post instances here -- see PostTypeOptions::build(), which
        // relies on the same guarantee.
        foreach ($posts as $post) {
            $titles[$post->ID] = $post->post_title;
        }

        return $titles;
    }

    /**
     * @param  list<int> $ids
     * @return array<int, string>
     */
    private function locationNames(array $ids): array
    {
        // get_terms() treats an empty `include` the same way WP_Query
        // treats an empty post__in -- see providerTitles().
        if ($ids === []) {
            return [];
        }

        // 'id=>name' is the whole map in one query. Asking for WP_Term
        // objects instead costs a second one, because get_terms() then
        // hydrates them through _prime_term_caches() -- and a name is all
        // this needs. TermOptions builds facets and does need the objects
        // (parent, for the hierarchy), which is why it asks differently.
        $names = get_terms([
            'taxonomy'               => Taxonomies::LOCATION,
            'include'                => $ids,
            'hide_empty'             => false,
            'fields'                 => 'id=>name',
            'update_term_meta_cache' => false,
        ]);

        if (! is_array($names)) {
            return [];
        }

        return $names;
    }
}
