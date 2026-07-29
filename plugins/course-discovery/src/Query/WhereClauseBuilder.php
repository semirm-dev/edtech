<?php

declare(strict_types=1);

namespace CourseDiscovery\Query;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Constraint\ConstraintSet;
use CourseDiscovery\Domain\Constraint\AttributeInConstraint;
use CourseDiscovery\Domain\Constraint\RawConstraint;
use CourseDiscovery\Domain\Constraint\SearchTextConstraint;
use CourseDiscovery\Index\Schema;
use RuntimeException;
use wpdb;

/**
 * Turns declarative constraints into a prepared WHERE clause.
 *
 * The single place in the codebase that generates SQL for course search, so
 * escaping is audited here rather than across every filter.
 *
 * Every fragment (including any a `course_discovery/build_constraint_sql`
 * filter returns) assumes the outer query aliases the index table as `i`
 * (`FROM {index_table} i`): the attribute EXISTS subquery correlates on
 * `i.course_id` and search-text matches `i.search_text`.
 *
 * build() wraps every fragment in parentheses before joining with AND, so
 * a fragment with a top-level OR (from a RawConstraint or a third-party
 * filter) cannot widen or silently void the other constraints in the set.
 */
final class WhereClauseBuilder
{
    public function __construct(
        private readonly wpdb $db,
        private readonly Schema $schema,
    ) {
    }

    /**
     * Builds a WHERE-clause fragment from a set ready to embed into a query.
     *
     * WARNING: the returned string is NOT plain SQL text, and must not be
     * passed through $wpdb->prepare() again. prepare() escapes any literal `%`
     * in the user's data into a placeholder-escape token that only a
     * subsequent $wpdb query method (query(), get_results(), get_var(), ...)
     * resolves back to a literal `%`. Do not log it, use it as a cache key,
     * echo it, or hand it to any non-wpdb SQL client — each would see the raw
     * escape tokens instead of the user's actual text.
     */
    public function build(ConstraintSet $set): string
    {
        $fragments = [];

        foreach ($set->all() as $constraint) {
            $fragment = $this->buildOne($constraint);

            if ($fragment !== null) {
                // Parenthesised so a fragment with a top-level OR cannot
                // recombine with the surrounding ANDs: without it,
                // `EXISTS(...) AND 1=0 OR 1=1` parses as
                // `(EXISTS(...) AND 1=0) OR 1=1`, voiding every other
                // constraint. See the class docblock.
                $fragments[] = '(' . $fragment . ')';
            }
        }

        if ($fragments === []) {
            return '1=1';
        }

        return implode("\n  AND ", $fragments);
    }

    private function buildOne(Constraint $constraint): ?string
    {
        return match (true) {
            $constraint instanceof AttributeInConstraint  => $this->buildAttribute($constraint),
            $constraint instanceof SearchTextConstraint => $this->buildSearchText($constraint),
            $constraint instanceof RawConstraint      => $this->buildRaw($constraint),
            default                                    => $this->buildUnknown($constraint),
        };
    }

    /**
     * An EXISTS subquery per attribute: AND between filters, OR (via IN)
     * within one.
     *
     * Correlates on i.course_id: assumes the outer query aliases the index
     * table `i`, see the class docblock.
     */
    private function buildAttribute(AttributeInConstraint $constraint): string
    {
        $placeholders = implode(', ', array_fill(0, count($constraint->valueIds), '%d'));

        // %i (identifier placeholder) keeps the query template a true
        // literal-string; interpolating the table name directly would not.
        $sql = $this->db->prepare(
            "EXISTS (SELECT 1 FROM %i f
                     WHERE f.course_id = i.course_id
                       AND f.attribute = %s
                       AND f.value_id IN ({$placeholders}))",
            $this->schema->attributeLookupTable(),
            $constraint->attribute,
            ...$constraint->valueIds
        );

        if ($sql === null) {
            throw new RuntimeException('Failed to prepare attribute constraint SQL.');
        }

        return $sql;
    }

    /**
     * Boolean-mode operators (+, -, *, ", (, )) are stripped, not escaped:
     * MySQL treats them as syntax, so leaving them in a user's phrase yields
     * parse errors. Text is first scrubbed to valid UTF-8, since MySQL
     * rejects a query containing invalid byte sequences outright and a raw
     * `?q=` parameter can easily contain them. Matches i.search_text (assumes
     * the outer query aliases the index table `i`).
     */
    private function buildSearchText(SearchTextConstraint $constraint): string
    {
        $utf8Safe = mb_scrub($constraint->terms, 'UTF-8');

        $cleaned = preg_replace('/[+\-><()~*"@;]+/', ' ', $utf8Safe) ?? '';
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        if ($cleaned === '') {
            // Fail CLOSED: a search that reduces to nothing (e.g. "+++" or a
            // lone quote) must return no results, not the whole catalogue.
            return '0=1';
        }

        $sql = $this->db->prepare(
            'MATCH (i.search_text) AGAINST (%s IN BOOLEAN MODE)',
            $cleaned
        );

        if ($sql === null) {
            throw new RuntimeException('Failed to prepare search-text constraint SQL.');
        }

        return $sql;
    }

    private function buildRaw(RawConstraint $constraint): string
    {
        if ($constraint->bindings === []) {
            return $constraint->sql;
        }

        $sql = $this->db->prepare($constraint->sql, $constraint->bindings);

        if ($sql === null) {
            throw new RuntimeException('Failed to prepare raw constraint SQL.');
        }

        return $sql;
    }

    /**
     * A third-party constraint type: let plugins turn it into SQL, ignore
     * it if none can rather than failing the whole query.
     *
     * Security contract for `course_discovery/build_constraint_sql` -- the
     * returned string is used VERBATIM AND UNESCAPED, so a callback MUST:
     *  - return SQL already passed through $wpdb->prepare() (no unescaped
     *    user input);
     *  - return a self-contained boolean expression, parenthesised internally
     *    if it uses a top-level OR (build() only wraps the whole fragment);
     *  - assume the outer query aliases the index table `i`.
     */
    private function buildUnknown(Constraint $constraint): ?string
    {
        /** @var mixed $built */
        $built = apply_filters('course_discovery/build_constraint_sql', null, $constraint, $this->db, $this->schema);

        return is_string($built) && $built !== '' ? $built : null;
    }
}
