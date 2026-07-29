<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Support;

use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * Guarantees every filter — including ones added later by third parties —
 * satisfies the contract.
 *
 * Opt in by extending this and implementing the two abstract methods.
 */
abstract class FilterContractTestCase extends IntegrationTestCase
{
    abstract protected function makeFilter(): Filter;

    /**
     * Values that should produce a constraint for this filter.
     */
    abstract protected function validValues(): FilterValues;

    final public function test_contract_key_is_stable(): void
    {
        $filter = $this->makeFilter();

        self::assertTrue(
            $filter->key()->equals($this->makeFilter()->key()),
            'key() must be deterministic.'
        );
    }

    final public function test_contract_label_is_non_empty(): void
    {
        self::assertNotSame('', trim($this->makeFilter()->label()));
    }

    final public function test_contract_empty_values_produce_no_constraint(): void
    {
        self::assertNull(
            $this->makeFilter()->constrain(FilterValues::empty()),
            'An empty selection must be omitted from the query entirely.'
        );
    }

    final public function test_contract_valid_values_produce_a_constraint(): void
    {
        self::assertNotNull($this->makeFilter()->constrain($this->validValues()));
    }

    final public function test_contract_garbage_values_do_not_throw(): void
    {
        $filter = $this->makeFilter();

        foreach ([['0'], ['-1'], ['abc'], ['<script>'], ["' OR 1=1"]] as $garbage) {
            // constrain()'s declared return type (?Constraint) already
            // guarantees the result is null or a Constraint, so asserting
            // that shape would be a tautology PHPStan rejects at level 9
            // (staticMethod.alreadyNarrowedType / instanceof.alwaysTrue) —
            // and PHPUnit would flag assertTrue(true) as risky besides. The
            // one thing the type system cannot guarantee, and the one this
            // test exists to pin, is that garbage input never throws.
            $threw = null;

            try {
                $filter->constrain(FilterValues::fromStrings($garbage));
            } catch (\Throwable $e) {
                $threw = $e;
            }

            self::assertNull(
                $threw,
                sprintf('constrain(%s) threw %s.', json_encode($garbage), $threw === null ? '' : $threw::class)
            );
        }
    }

    final public function test_contract_options_are_consistent_with_input_type(): void
    {
        $filter = $this->makeFilter();

        if ($filter->inputType() === FilterInputType::Text) {
            self::assertTrue($filter->options()->isEmpty(), 'A text filter offers no options.');

            return;
        }

        // Unlike the text-filter branch above, `assertGreaterThanOrEqual(0,
        // ...->count())` would be a tautology here: count() returns int,
        // never negative, so the assertion could never fail regardless of
        // what the filter does. It also never checked the thing its own
        // comment claimed ("must offer choices to be usable"). The real
        // guarantee this contract needs for a choice-based filter is that
        // what options() ADVERTISES is something constrain() can actually
        // ACT ON -- a filter offering term slugs while constrain() expects
        // term ids would pass a shape check like the old one and still be
        // broken. Skipped rather than failed when a bare fixture legitimately
        // has no data to offer yet.
        $options = $filter->options();

        if ($options->isEmpty()) {
            self::markTestSkipped('This filter has no options under the current fixture data; nothing to check.');
        }

        $firstOption = null;

        foreach ($options as $option) {
            $firstOption = $option;

            break;
        }

        if ($firstOption === null) {
            self::fail('options() reported non-empty but produced no first element.');
        }

        self::assertNotNull(
            $filter->constrain(FilterValues::fromStrings([$firstOption->value])),
            sprintf(
                'options() advertised value "%s" but constrain() returned null for it -- an advertised option must be actionable.',
                $firstOption->value
            )
        );
    }

    /**
     * options() takes an optional $context parameter so a
     * later, faceted implementation ("only show providers with results
     * under the current selection") can be added without another breaking
     * change to this interface. Every filter today is free to ignore it,
     * but the interface must accept both an explicit null and a real
     * SearchCriteria without error, and omitting the argument entirely
     * must behave exactly like passing null -- PHP does not require an
     * implementing class to repeat the interface's default value, so this
     * is a genuine behavioural check, not one already guaranteed by the
     * declared parameter type.
     */
    final public function test_contract_options_accepts_an_optional_criteria_context(): void
    {
        $filter = $this->makeFilter();

        self::assertSame(
            $filter->options()->count(),
            $filter->options(null)->count(),
            'options() with no argument must behave exactly like options(null).'
        );

        $threw = null;

        try {
            $filter->options(SearchCriteria::empty());
        } catch (Throwable $e) {
            $threw = $e;
        }

        self::assertNull(
            $threw,
            sprintf(
                'options(SearchCriteria) threw %s; implementations may ignore $context but must accept it.',
                $threw === null ? '' : $threw::class
            )
        );
    }

    /**
     * description() on the interface (aria-describedby
     * copy / a placeholder hint, e.g. for the keyword field). Its declared
     * return type (?string) already guarantees the shape, so re-asserting
     * "string or null" would be the same kind of tautology just removed
     * above; what is not guaranteed is that calling it is safe.
     */
    final public function test_contract_description_does_not_throw(): void
    {
        $threw = null;

        try {
            $this->makeFilter()->description();
        } catch (Throwable $e) {
            $threw = $e;
        }

        self::assertNull(
            $threw,
            sprintf('description() threw %s.', $threw === null ? '' : $threw::class)
        );
    }
}
