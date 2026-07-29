<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Filter;

use CourseDiscovery\ContentModel\Taxonomies;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Filter\CategoryFilter;
use CourseDiscovery\Tests\Support\FilterContractTestCase;

final class CategoryFilterTest extends FilterContractTestCase
{
    private int $parentId;
    private int $childId;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var int $parentId */
        $parentId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Design',
        ]);

        /** @var int $childId */
        $childId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Graphic Design',
            'parent'   => $parentId,
        ]);

        $this->parentId = $parentId;
        $this->childId = $childId;
    }

    protected function makeFilter(): Filter
    {
        return new CategoryFilter();
    }

    protected function validValues(): FilterValues
    {
        return FilterValues::fromInts([$this->childId]);
    }

    public function test_it_is_a_checkbox_group(): void
    {
        self::assertSame(FilterInputType::CheckboxGroup, (new CategoryFilter())->inputType());
    }

    public function test_it_offers_categories_as_options(): void
    {
        $options = (new CategoryFilter())->options();

        $labels = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
        }

        self::assertContains('Design', $labels);
    }

    public function test_a_childs_label_is_indented_and_follows_its_parent(): void
    {
        $options = (new CategoryFilter())->options();

        $values = [];
        $labels = [];

        foreach ($options as $option) {
            $values[] = $option->value;
            $labels[] = $option->label;
        }

        // Located by id, not by label text: the value must stay a bare term
        // id regardless of how the label is decorated for nesting.
        $parentIndex = array_search((string) $this->parentId, $values, true);
        $childIndex = array_search((string) $this->childId, $values, true);

        self::assertNotFalse($parentIndex, 'The parent category must be among the offered options.');
        self::assertNotFalse($childIndex, 'The child category must be among the offered options.');
        self::assertSame(
            $parentIndex + 1,
            $childIndex,
            'A child category must immediately follow its parent in option order.'
        );
        self::assertStringStartsNotWith(
            "\u{00A0}",
            $labels[$parentIndex],
            'A top-level category label must not be indented.'
        );
        self::assertStringStartsWith(
            "\u{00A0}\u{00A0}",
            $labels[$childIndex],
            'A child category label must be visually indented.'
        );
        self::assertStringContainsString(
            'Graphic Design',
            $labels[$childIndex],
            'The indentation must decorate the name, not replace it.'
        );
    }

    /**
     * The single-level test above cannot distinguish real depth
     * accumulation from a flat "is this a child?" check: both would
     * produce one unit of indentation for a lone child. A three-level
     * chain forces the distinction -- a flat check would indent the
     * grandchild the same as the child, while real depth accumulation
     * must double it.
     */
    public function test_a_three_level_chain_is_depth_first_with_accumulating_indentation(): void
    {
        /** @var int $grandparentId */
        $grandparentId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Creative Arts',
        ]);

        /** @var int $parentId */
        $parentId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Visual Arts',
            'parent'   => $grandparentId,
        ]);

        /** @var int $childId */
        $childId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Illustration',
            'parent'   => $parentId,
        ]);

        $options = (new CategoryFilter())->options();

        $values = [];
        $labels = [];

        foreach ($options as $option) {
            $values[] = $option->value;
            $labels[] = $option->label;
        }

        // Located by id, not by label text -- see the single-level test's
        // own comment on why.
        $grandparentIndex = array_search((string) $grandparentId, $values, true);
        $parentIndex = array_search((string) $parentId, $values, true);
        $childIndex = array_search((string) $childId, $values, true);

        self::assertNotFalse($grandparentIndex, 'The grandparent category must be among the offered options.');
        self::assertNotFalse($parentIndex, 'The parent category must be among the offered options.');
        self::assertNotFalse($childIndex, 'The child category must be among the offered options.');

        self::assertSame(
            $grandparentIndex + 1,
            $parentIndex,
            'The parent must immediately follow the grandparent in depth-first order.'
        );
        self::assertSame(
            $parentIndex + 1,
            $childIndex,
            'The child must immediately follow the parent in depth-first order, contiguous with the grandparent.'
        );

        self::assertSame(
            (string) $grandparentId,
            $values[$grandparentIndex],
            'Option values must stay bare term ids, not the indented label.'
        );
        self::assertSame(
            (string) $parentId,
            $values[$parentIndex],
            'Option values must stay bare term ids, not the indented label.'
        );
        self::assertSame(
            (string) $childId,
            $values[$childIndex],
            'Option values must stay bare term ids, not the indented label.'
        );

        $grandparentDepth = self::indentDepth($labels[$grandparentIndex]);
        $parentDepth = self::indentDepth($labels[$parentIndex]);
        $childDepth = self::indentDepth($labels[$childIndex]);

        self::assertSame(0, $grandparentDepth, 'A root category must carry no indentation.');
        self::assertSame(1, $parentDepth, 'A first-level child must carry exactly one unit of indentation.');
        self::assertSame(2, $childDepth, 'A second-level child must carry exactly two units of indentation.');
        self::assertSame(
            $parentDepth * 2,
            $childDepth,
            'The grandchild must carry exactly twice the indentation of the parent, proving depth '
                . 'accumulates with each level rather than a flat parent/child check.'
        );
    }

    /**
     * TermOptions::flattenHierarchy() only emits a term once its parent
     * chain is walked back to a root within the given term set. A term
     * whose parent id is not present in that set -- an orphan -- must
     * therefore be silently omitted, and building the options must not
     * hang or error.
     *
     * Simulating the orphan: neither of the two obvious routes actually
     * produces one under real WordPress behaviour. `wp_update_term()`
     * validates the parent and returns a `WP_Error` ('missing_parent') for
     * a non-existent id, refusing the update outright. Creating a child and
     * then deleting its parent does not orphan it either -- WordPress's
     * `wp_delete_term()` re-parents surviving children to the deleted
     * term's own parent (here, the root) via a direct SQL update, bypassing
     * `wp_update_term()`'s validation entirely. So the only faithful way to
     * reproduce a genuinely corrupted parent reference (e.g. left behind by
     * a bad migration or direct SQL import) is to write it below the WP API
     * layer, exactly as WordPress's own re-parenting logic does. Hence the
     * direct $wpdb update here rather than either suggested route.
     */
    public function test_a_term_with_a_missing_parent_is_omitted_not_hung_or_errored(): void
    {
        global $wpdb;

        /** @var int $orphanId */
        $orphanId = self::factory()->term->create([
            'taxonomy' => Taxonomies::CATEGORY,
            'name'     => 'Dangling Category',
            'parent'   => $this->parentId,
        ]);

        // Bypass wp_update_term()'s parent-existence validation (it would
        // return a WP_Error for a non-existent id) to simulate a corrupted
        // parent chain at the storage layer, as explained above.
        $bogusParentId = $orphanId + 1_000_000;

        $wpdb->update(
            $wpdb->term_taxonomy,
            ['parent' => $bogusParentId],
            ['term_id' => $orphanId, 'taxonomy' => Taxonomies::CATEGORY]
        );

        clean_term_cache($orphanId, Taxonomies::CATEGORY);

        $threw = null;
        $options = null;

        try {
            $options = (new CategoryFilter())->options();
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertNull(
            $threw,
            sprintf('Building options with an orphaned term threw %s.', $threw === null ? '' : $threw::class)
        );
        self::assertNotNull($options);

        $values = [];

        foreach ($options as $option) {
            $values[] = $option->value;
        }

        self::assertNotContains(
            (string) $orphanId,
            $values,
            'A term whose parent is missing from the set must be omitted, not surfaced without its context.'
        );
        self::assertContains(
            (string) $this->parentId,
            $values,
            'The orphan must not have taken down unrelated, well-formed terms with it.'
        );
    }

    private static function indentDepth(string $label): int
    {
        $unit = "\u{00A0}\u{00A0}";
        $depth = 0;

        while (str_starts_with($label, $unit)) {
            $label = substr($label, strlen($unit));
            $depth++;
        }

        return $depth;
    }

    public function test_it_constrains_on_the_category_attribute(): void
    {
        $constraint = (new CategoryFilter())->constrain(FilterValues::fromInts([12, 47]));

        self::assertInstanceOf(AttributeInConstraint::class, $constraint);
        self::assertSame('category', $constraint->attribute);
        self::assertSame([12, 47], $constraint->valueIds);
    }

    public function test_non_numeric_values_are_discarded_not_coerced(): void
    {
        self::assertNull(
            (new CategoryFilter())->constrain(FilterValues::fromStrings(['abc', '0'])),
            'Values that resolve to nothing must omit the filter, not match id 0.'
        );
    }

    public function test_options_can_be_altered_by_a_hook(): void
    {
        add_filter(
            'course_discovery/filter_options/category',
            static fn (): \CourseDiscovery\Domain\Filter\FilterOptions =>
                \CourseDiscovery\Domain\Filter\FilterOptions::fromArray([
                    new \CourseDiscovery\Domain\Filter\FilterOption('99', 'Injected'),
                ])
        );

        $options = (new CategoryFilter())->options();

        self::assertCount(1, $options);
    }
}
