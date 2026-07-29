<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter\Support;

use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use WP_Term;

/**
 * Builds filter options from a taxonomy.
 *
 * A collaborator filters HOLD, not a base class they extend — two filters
 * need this and neither is a kind of the other.
 */
final readonly class TermOptions
{
    public function __construct(
        private string $taxonomy,
        private bool $hierarchical = false,
    ) {
    }

    public function build(): FilterOptions
    {
        $terms = get_terms([
            'taxonomy'   => $this->taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
        ]);

        if (! is_array($terms)) {
            return FilterOptions::empty();
        }

        // No 'fields' key is passed above, so get_terms() is statically
        // guaranteed (by its own conditional return type) to hand back
        // WP_Term instances here; an instanceof filter would be redundant
        // dead code PHPStan rejects as an always-true check, not a genuine
        // narrowing.
        $termObjects = array_values($terms);

        if (! $this->hierarchical) {
            return FilterOptions::fromArray(array_map(
                static fn (WP_Term $t): FilterOption => new FilterOption((string) $t->term_id, $t->name),
                $termObjects
            ));
        }

        return FilterOptions::fromArray($this->flattenHierarchy($termObjects, 0, 0));
    }

    /**
     * Depth-first, so a child always follows its parent. The label carries
     * the nesting as a non-breaking indent because the value must stay a
     * bare term id.
     *
     * A term is only emitted once its parent chain is walked back to a term
     * matching `$parentId` in the current recursion. A term whose parent id
     * is not present in the given `$terms` set — an orphan left behind by a
     * corrupted parent reference, or any link in a corrupted parent cycle —
     * is therefore never reached and is silently omitted from the result.
     * This is deliberate: it bounds the recursion to the depth of the
     * well-formed part of the tree instead of following a broken or cyclic
     * parent chain indefinitely.
     *
     * @param  list<WP_Term> $terms
     * @return list<FilterOption>
     */
    private function flattenHierarchy(array $terms, int $parentId, int $depth): array
    {
        $options = [];

        foreach ($terms as $term) {
            if ((int) $term->parent !== $parentId) {
                continue;
            }

            $options[] = new FilterOption(
                (string) $term->term_id,
                str_repeat("\u{00A0}\u{00A0}", $depth) . $term->name
            );

            foreach ($this->flattenHierarchy($terms, (int) $term->term_id, $depth + 1) as $child) {
                $options[] = $child;
            }
        }

        return $options;
    }
}
