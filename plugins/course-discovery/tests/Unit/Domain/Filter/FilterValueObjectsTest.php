<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain\Filter;

use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FilterValueObjectsTest extends TestCase
{
    public function test_a_key_accepts_a_safe_identifier(): void
    {
        self::assertSame('provider', FilterKey::fromString('provider')->toString());
        self::assertSame('start_date', FilterKey::fromString('start_date')->toString());
    }

    public function test_a_key_rejects_anything_unsafe_for_a_query_parameter(): void
    {
        foreach (['has space', 'UPPER', 'semi;colon', 'br[acket]', '../etc'] as $bad) {
            try {
                FilterKey::fromString($bad);
                self::fail(sprintf('Expected "%s" to be rejected.', $bad));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($bad, $e->getMessage());
            }
        }

        // Asserted separately: assertStringContainsString('', $message) is
        // vacuously true for any message, so the empty string needs its own
        // meaningful assertion instead of the loop above.
        try {
            FilterKey::fromString('');
            self::fail('Expected "" to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('must match', $e->getMessage());
        }
    }

    public function test_a_key_rejects_a_trailing_newline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilterKey::fromString("provider\n");
    }

    public function test_a_key_accepts_a_64_character_key(): void
    {
        $key = str_repeat('a', 64);

        self::assertSame($key, FilterKey::fromString($key)->toString());
    }

    public function test_a_key_rejects_a_65_character_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilterKey::fromString(str_repeat('a', 65));
    }

    public function test_a_key_exposes_its_query_parameter_name(): void
    {
        self::assertSame('provider', FilterKey::fromString('provider')->queryParam());
    }

    public function test_keys_are_equal_only_when_their_value_matches(): void
    {
        self::assertTrue(
            FilterKey::fromString('provider')->equals(FilterKey::fromString('provider'))
        );
        self::assertFalse(
            FilterKey::fromString('provider')->equals(FilterKey::fromString('start_date'))
        );
    }

    public function test_values_deduplicate_and_preserve_order(): void
    {
        $values = FilterValues::fromInts([12, 47, 12]);

        self::assertSame([12, 47], $values->toInts());
        self::assertCount(2, $values);
    }

    public function test_empty_values_report_themselves_empty(): void
    {
        self::assertTrue(FilterValues::empty()->isEmpty());
        self::assertFalse(FilterValues::fromInts([1])->isEmpty());
    }

    public function test_string_values_round_trip(): void
    {
        self::assertSame(['a', 'b'], FilterValues::fromStrings(['a', 'b', 'a'])->toStrings());
    }

    public function test_int_values_expose_themselves_as_strings(): void
    {
        self::assertSame(['12', '47'], FilterValues::fromInts([12, 47])->toStrings());
    }

    public function test_to_ints_discards_non_numeric_and_non_positive_entries(): void
    {
        $values = FilterValues::fromStrings(['abc', '-1', '1.5', '1e3', ' 12', '', '0']);

        self::assertSame([], $values->toInts());
    }

    public function test_to_ints_keeps_valid_entries_and_discards_invalid_ones(): void
    {
        $values = FilterValues::fromStrings(['12', 'abc', '47']);

        self::assertSame([12, 47], $values->toInts());
    }

    public function test_to_ints_discards_a_value_that_overflows_php_int_max(): void
    {
        $values = FilterValues::fromStrings(['99999999999999999999']);

        self::assertSame([], $values->toInts());
    }

    public function test_options_are_iterable_and_countable(): void
    {
        $options = FilterOptions::fromArray([
            new FilterOption('12', 'Sunderland'),
            new FilterOption('47', 'De Montfort'),
        ]);

        self::assertCount(2, $options);

        $labels = [];
        $values = [];

        foreach ($options as $option) {
            $labels[] = $option->label;
            $values[] = $option->value;
        }

        self::assertSame(['Sunderland', 'De Montfort'], $labels);
        self::assertSame(['12', '47'], $values);
    }

    public function test_empty_options_report_themselves_empty(): void
    {
        self::assertTrue(FilterOptions::empty()->isEmpty());
    }

    public function test_input_types_are_a_closed_set(): void
    {
        self::assertSame('text', FilterInputType::Text->value);
        self::assertSame('checkbox_group', FilterInputType::CheckboxGroup->value);
        self::assertSame('combobox_multi', FilterInputType::ComboboxMulti->value);
    }
}
