<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use InvalidArgumentException;

/**
 * An amount in integer minor units (pence, cents) plus an ISO currency code.
 *
 * Never a float: binary floating point cannot represent 0.1 exactly, and
 * money that does not add up is worse than money that is awkward to format.
 */
final readonly class Money
{
    private const SYMBOLS = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'];

    /**
     * @param int<0, max> $minor
     */
    private function __construct(
        public int $minor,
        public string $currency,
    ) {
    }

    public static function fromMinor(int $minor, string $currency): self
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        if (! preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw new InvalidArgumentException(sprintf('Currency must be a 3-letter code, got "%s".', $currency));
        }

        return new self($minor, strtoupper($currency));
    }

    public function format(): string
    {
        $symbol = self::SYMBOLS[$this->currency] ?? ($this->currency . ' ');

        $whole = intdiv($this->minor, 100);
        $fraction = $this->minor % 100;

        return $symbol . number_format($whole) . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
