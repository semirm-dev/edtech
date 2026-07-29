<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Unit\Domain;

use CourseDiscovery\Domain\Money;
use CourseDiscovery\Domain\SinglePrice;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_holds_integer_minor_units(): void
    {
        $money = Money::fromMinor(95000, 'GBP');

        self::assertSame(95000, $money->minor);
        self::assertSame('GBP', $money->currency);
    }

    public function test_it_formats_with_two_decimal_places(): void
    {
        self::assertSame('£950.00', Money::fromMinor(95000, 'GBP')->format());
        self::assertSame('£1,200.50', Money::fromMinor(120050, 'GBP')->format());
    }

    public function test_it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(-1, 'GBP');
    }

    public function test_single_price_exposes_the_pricing_contract(): void
    {
        $pricing = new SinglePrice(Money::fromMinor(95000, 'GBP'));

        self::assertSame('£950.00', $pricing->format());
        self::assertSame(95000, $pricing->lowestMinor());
    }

    public function test_it_normalises_a_lowercase_currency_code(): void
    {
        self::assertSame('GBP', Money::fromMinor(95000, 'gbp')->currency);
    }

    public function test_it_rejects_a_two_letter_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(95000, 'GB');
    }

    public function test_it_rejects_a_four_letter_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(95000, 'GBPX');
    }

    public function test_it_rejects_a_multibyte_currency_code_that_is_three_bytes_long(): void
    {
        // '€' is a single character but 3 bytes in UTF-8: strlen() === 3
        // used to let it through as a "3-letter code".
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, '€');
    }

    public function test_it_formats_an_unmapped_currency_with_a_code_prefix(): void
    {
        self::assertSame('JPY 950.00', Money::fromMinor(95000, 'JPY')->format());
    }

    public function test_it_formats_a_large_amount_without_losing_precision(): void
    {
        // 9007199254740993 is 2^53 + 1: float division (minor / 100) loses
        // precision here and previously rendered "...09.92" instead of
        // "...09.93".
        self::assertSame(
            '£90,071,992,547,409.93',
            Money::fromMinor(9007199254740993, 'GBP')->format()
        );
    }
}
