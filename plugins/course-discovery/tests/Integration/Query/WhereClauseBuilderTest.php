<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Query;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Constraint\RawConstraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Index\Schema;
use CourseDiscovery\Query\WhereClauseBuilder;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use CourseDiscovery\Tests\Integration\UsesIndexTables;

/**
 * Every executing test in this class runs the built fragment against the
 * PRODUCTION query shape -- `SELECT course_id FROM {index_table} i WHERE
 * <fragment>` -- because WhereClauseBuilder's fragments assume that exact
 * alias (see the class docblock). A query built any other way is not
 * actually proving what the repository will run.
 */
final class WhereClauseBuilderTest extends IntegrationTestCase
{
    use UsesIndexTables;

    private WhereClauseBuilder $builder;
    private Schema $schema;
    private int $nextCourseId = 90000;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->schema = new Schema($wpdb);

        // Runs migrations and truncates both index tables. Also removes
        // wp-phpunit's temporary-table filter, without which MariaDB
        // rejects the FULLTEXT index. See the trait's docblock.
        $this->prepareIndexTables();

        $this->builder = new WhereClauseBuilder($wpdb, $this->schema);
    }

    /**
     * Inserts a bare index row with an arbitrary, non-existent course id --
     * this class tests the builder and its generated SQL, not the indexer
     * or the content model, so there is no need for a real WP_Post behind
     * each row.
     */
    private function makeIndexRow(string $searchText = 'placeholder text'): int
    {
        global $wpdb;

        $courseId = $this->nextCourseId++;

        $wpdb->insert($this->schema->metaLookupTable(), [
            'course_id'   => $courseId,
            'price_minor' => 0,
            'search_text' => $searchText,
        ]);

        return $courseId;
    }

    private function addAttributeValue(int $courseId, string $attribute, int $valueId): void
    {
        global $wpdb;

        $wpdb->insert($this->schema->attributeLookupTable(), [
            'course_id' => $courseId,
            'attribute'     => $attribute,
            'value_id'  => $valueId,
        ]);
    }

    /**
     * @return list<int>
     */
    private function runWhere(string $whereSql): array
    {
        global $wpdb;

        $indexTable = $this->schema->metaLookupTable();

        /** @var list<string> $found */
        $found = $wpdb->get_col("SELECT course_id FROM {$indexTable} i WHERE {$whereSql}");

        return array_map('intval', $found);
    }

    public function test_an_empty_set_builds_a_true_predicate(): void
    {
        $where = $this->builder->build(ConstraintSet::empty());

        self::assertSame('1=1', trim($where));
    }

    public function test_one_attribute_builds_an_exists_subquery(): void
    {
        $where = $this->builder->build(
            ConstraintSet::of(new AttributeInConstraint('provider', [12, 47]))
        );

        $sql = $where;

        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringContainsString("'provider'", $sql);
        self::assertStringContainsString('12', $sql);
        self::assertStringContainsString('47', $sql);
    }

    public function test_multiple_attributes_are_combined_with_and(): void
    {
        $where = $this->builder->build(ConstraintSet::of(
            new AttributeInConstraint('provider', [12]),
            new AttributeInConstraint('location', [3]),
        ));

        self::assertSame(2, substr_count($where, 'EXISTS'));
        self::assertStringContainsString('AND', $where);
    }

    public function test_search_text_builds_a_fulltext_match(): void
    {
        $where = $this->builder->build(
            ConstraintSet::of(new SearchTextConstraint('graphic design'))
        );

        self::assertStringContainsString('MATCH', $where);
        self::assertStringContainsString('AGAINST', $where);
    }

    /**
     * A attribute fragment's EXISTS subquery correlates on i.course_id, so it
     * only works against the aliased production shape. Previously nothing
     * executed an attribute fragment at all -- the only executing test ran an
     * unaliased search-text-only query, which cannot catch an alias bug in
     * buildAttribute().
     */
    public function test_a_attribute_fragment_executes_and_filters_correctly_against_the_production_shape(): void
    {
        $matching = $this->makeIndexRow('course a');
        $this->addAttributeValue($matching, 'provider', 12);

        $nonMatching = $this->makeIndexRow('course b');
        $this->addAttributeValue($nonMatching, 'provider', 99);

        $where = $this->builder->build(
            ConstraintSet::of(new AttributeInConstraint('provider', [12]))
        );

        self::assertSame([$matching], $this->runWhere($where));
    }

    /**
     * Proves the attribute and search-text fragments compose correctly under
     * real AND semantics, each qualified with the same `i` alias.
     *
     * Requires an explicit COMMIT after inserting: InnoDB defers FULLTEXT
     * index changes to an internal DML cache that is only merged into the
     * searchable index on commit, so a MATCH ... AGAINST query run inside
     * the SAME still-open transaction as the INSERT does not see the new
     * row at all (verified directly against this project's own test
     * database: identical INSERT-then-MATCH inside one uncommitted
     * transaction returns zero rows; the same statements with a plain
     * autocommit session return the expected row). wp-phpunit wraps every
     * test in exactly such a transaction and rolls it back in tear_down(),
     * so without committing here the search half of this test can never
     * see its own fixture data. The commit is safe: prepareIndexTables()
     * truncates both index tables again at the start of every test's
     * setUp(), so nothing leaks into a later test.
     */
    public function test_a_combined_attribute_and_search_fragment_executes_correctly(): void
    {
        global $wpdb;

        $matchesBoth = $this->makeIndexRow('graphic design basics');
        $this->addAttributeValue($matchesBoth, 'provider', 12);

        $wrongAttribute = $this->makeIndexRow('graphic design advanced');
        $this->addAttributeValue($wrongAttribute, 'provider', 99);

        $wrongSearch = $this->makeIndexRow('cooking recipes');
        $this->addAttributeValue($wrongSearch, 'provider', 12);

        $wpdb->query('COMMIT');

        $where = $this->builder->build(ConstraintSet::of(
            new AttributeInConstraint('provider', [12]),
            new SearchTextConstraint('graphic'),
        ));

        self::assertSame(
            [$matchesBoth],
            $this->runWhere($where),
            'Must satisfy both the attribute AND the search-text constraint, excluding rows that satisfy only one.'
        );
    }

    /**
     * build() must parenthesise every fragment before joining
     * with AND. Without it, `EXISTS(...) AND 1=0 OR 1=1` parses (by normal
     * AND/OR precedence) as `(EXISTS(...) AND 1=0) OR 1=1` -- a RawConstraint
     * with a top-level OR then voids every other constraint in the set and
     * the query returns the whole catalogue instead of the filtered result.
     */
    public function test_fragments_are_parenthesised_so_a_top_level_or_cannot_void_other_constraints(): void
    {
        $shouldBeExcluded = $this->makeIndexRow('unrelated course');
        $this->addAttributeValue($shouldBeExcluded, 'provider', 99);

        $where = $this->builder->build(ConstraintSet::of(
            new AttributeInConstraint('provider', [12]),
            new RawConstraint('1=0 OR 1=1'),
        ));

        $sql = $where;

        // Precise rather than a backtracking-prone regex: the attribute
        // fragment's own EXISTS subquery already contains internal ANDs,
        // so a generic "(...)AND(...)" pattern would be ambiguous. This
        // checks the exact shape build() must produce: each fragment
        // individually wrapped, joined by "\n  AND ".
        self::assertStringStartsWith('(', $sql, 'The first fragment must be parenthesised.');
        self::assertStringEndsWith(')', $sql, 'The last fragment must be parenthesised.');
        self::assertStringContainsString(
            ")\n  AND (",
            $sql,
            'Fragments must be joined as "(fragment)\n  AND (fragment)", each individually parenthesised.'
        );

        self::assertNotContains(
            $shouldBeExcluded,
            $this->runWhere($sql),
            "A RawConstraint containing a top-level OR must not void the attribute constraint that precedes it."
        );
    }

    /**
     * When boolean-mode stripping reduces the search text to
     * nothing (e.g. the user typed only "+++"), the built fragment must
     * fail CLOSED (match nothing) rather than open (match everything). A
     * search a user typed something into must never silently become "no
     * filter at all".
     */
    public function test_an_over_stripped_search_fails_closed_to_no_results(): void
    {
        $this->makeIndexRow('graphic design basics');

        $where = $this->builder->build(
            ConstraintSet::of(new SearchTextConstraint('+++'))
        );

        // Wrapped in parens like every other fragment.
        self::assertSame('(0=1)', trim($where));
        self::assertSame(
            [],
            $this->runWhere($where),
            'An unusable search must match nothing, even though rows exist in the index.'
        );
    }

    /**
     * Proves ESCAPING, not merely stripping. Asserting only that
     * a substring like "DROP TABLE ...;" is absent from the built SQL
     * passes even with $wpdb->prepare() removed entirely, because the
     * stripped-character regex alone drops the trailing `;` -- the
     * attacker's words survive untouched. This version instead asserts the
     * quote character in the injected literal comes out backslash-escaped
     * (proof prepare() ran), that the resulting query executes cleanly, and
     * that the table the payload names is never actually dropped.
     */
    public function test_it_escapes_a_sql_injection_attempt_in_search_text(): void
    {
        global $wpdb;

        $probeTable = $wpdb->prefix . 'cd_injection_probe_test';

        $wpdb->query("DROP TABLE IF EXISTS {$probeTable}");
        $wpdb->query("CREATE TABLE {$probeTable} (id INT)");

        try {
            $injected = "graphic'); DROP TABLE {$probeTable}; --";

            $where = $this->builder->build(
                ConstraintSet::of(new SearchTextConstraint($injected))
            );

            $sql = $where;

            self::assertStringContainsString(
                "\\'",
                $sql,
                "Expected the quote in the injected literal to come out backslash-escaped by \$wpdb->prepare()."
            );

            $suppressed = $wpdb->suppress_errors(true);
            $wpdb->get_results("SELECT course_id FROM " . $this->schema->metaLookupTable() . " i WHERE {$sql}");
            $error = $wpdb->last_error;
            $wpdb->suppress_errors($suppressed);

            self::assertSame('', $error, 'An escaped injection attempt must still execute cleanly.');

            self::assertSame(
                $probeTable,
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $probeTable)),
                'The injected DROP TABLE statement must never actually have executed.'
            );
        } finally {
            $wpdb->query("DROP TABLE IF EXISTS {$probeTable}");
        }
    }

    /**
     * The built SQL must always be executable, including for
     * invalid-UTF-8 input reachable from a raw `?q=` request parameter.
     * Boolean-mode operator stripping alone does not guarantee this --
     * MySQL rejects a query containing invalid byte sequences outright
     * regardless of which characters were stripped, so invalid bytes must
     * be scrubbed before the text ever reaches $wpdb->prepare().
     */
    /**
     * buildUnknown() and the `course_discovery/build_constraint_sql`
     * filter are the documented third-party constraint seam -- previously
     * completely untested, unlike the sibling seams `course_discovery/order`
     * and `indexed_course`, which both have coverage. This proves the whole
     * seam end to end: a custom Constraint implementation the builder has
     * never heard of, turned into SQL by a filter into a real EXISTS fragment, and
     * that fragment actually filtering a query.
     */
    public function test_a_third_party_constraint_is_built_via_the_filter_and_filters_correctly(): void
    {
        $matching = $this->makeIndexRow('course a');
        $this->addAttributeValue($matching, 'skill_level', 5);

        $nonMatching = $this->makeIndexRow('course b');
        $this->addAttributeValue($nonMatching, 'skill_level', 1);

        $constraint = new class implements Constraint {
            public function minimumSkillLevel(): int
            {
                return 5;
            }
        };

        $buildCustom = function (
            mixed $built,
            Constraint $candidate,
            \wpdb $db,
            Schema $schema
        ) use ($constraint): mixed {
            if ($candidate !== $constraint) {
                return $built;
            }

            return $db->prepare(
                'EXISTS (SELECT 1 FROM %i f
                         WHERE f.course_id = i.course_id
                           AND f.attribute = %s
                           AND f.value_id >= %d)',
                $schema->attributeLookupTable(),
                'skill_level',
                $constraint->minimumSkillLevel()
            );
        };

        add_filter('course_discovery/build_constraint_sql', $buildCustom, 10, 4);

        try {
            $where = $this->builder->build(ConstraintSet::of($constraint));
        } finally {
            remove_filter('course_discovery/build_constraint_sql', $buildCustom, 10);
        }

        self::assertStringContainsString('EXISTS', $where);
        self::assertSame(
            [$matching],
            $this->runWhere($where),
            'A third-party constraint built via the filter must actually filter the query.'
        );
    }

    /**
     * A filter that cannot (or does not) build SQL for the constraint
     * must be ignored safely -- the constraint contributes no fragment, and
     * build() falls back to its usual "no fragment => true predicate"
     * behaviour, rather than a non-string leaking into the query or the
     * whole search failing outright.
     */
    public function test_a_non_string_build_constraint_sql_filter_result_is_ignored_safely(): void
    {
        $row = $this->makeIndexRow('course a');

        $constraint = new class implements Constraint {
        };

        $badFilter = static fn (): array => ['not', 'a', 'string'];

        add_filter('course_discovery/build_constraint_sql', $badFilter);

        try {
            $where = $this->builder->build(ConstraintSet::of($constraint));
        } finally {
            remove_filter('course_discovery/build_constraint_sql', $badFilter);
        }

        self::assertSame(
            '1=1',
            trim($where),
            'An unknown constraint nobody could build SQL for must contribute no fragment at all.'
        );
        self::assertSame(
            [$row],
            $this->runWhere($where),
            'A non-string filter result must not fatal, and must not filter out rows that should match.'
        );
    }

    public function test_it_strips_boolean_mode_operators_from_user_input(): void
    {
        global $wpdb;

        $indexTable = $this->schema->metaLookupTable();

        $cases = [
            'boolean-mode operators' => 'design +@#$ "unclosed',
            'invalid UTF-8 bytes'    => "design \xff\xfe search",
        ];

        foreach ($cases as $label => $input) {
            $where = $this->builder->build(
                ConstraintSet::of(new SearchTextConstraint($input))
            );

            $suppressed = $wpdb->suppress_errors(true);
            $wpdb->get_results("SELECT course_id FROM {$indexTable} i WHERE " . $where);
            $error = $wpdb->last_error;
            $wpdb->suppress_errors($suppressed);

            self::assertSame('', $error, "Built search SQL must always be executable ({$label}).");
        }
    }
}
