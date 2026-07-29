<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use InvalidArgumentException;

/**
 * A course start date at month granularity.
 *
 * Stored and compared as an integer sort key (YYYYMM) so that chronological
 * ordering is structural rather than dependent on a comparison callback.
 * Display strings are always derived, never stored.
 */
final readonly class StartDate
{
    /** Number to name, for rendering — see toDisplay(). */
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Name to number, for parsing — see tryFromInput(). Deliberately the
     * explicit inverse of MONTH_NAMES rather than a derived array_flip: both
     * are private to this one class, so they cannot drift out of a single
     * file, and a plain lookup reads better than deriving one from the other
     * on every call. The month vocabulary still has exactly one owner.
     */
    private const MONTH_NUMBERS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * @param int<1, 12> $month
     */
    private function __construct(
        public int $year,
        public int $month,
    ) {
    }

    public static function fromYearMonth(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(sprintf('Month must be 1-12, got %d.', $month));
        }

        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException(sprintf('Year must be 2000-2100, got %d.', $year));
        }

        return new self($year, $month);
    }

    public static function fromSortKey(int $sortKey): self
    {
        return self::fromYearMonth(intdiv($sortKey, 100), $sortKey % 100);
    }

    /**
     * Parses the round-trippable input form — the exact inverse of
     * toInputValue(), so this class owns the "{month}-{year}" format in both
     * directions rather than defining it for output and leaving input to a
     * caller. Accepts a numeric or named month, any case: "03-2026",
     * "March-2026", "march-2026".
     *
     * Non-throwing, hence tryFrom… (matching PHP's own enum convention): this
     * is the entry point for untrusted user input, where a malformed string is
     * an expected outcome rather than a programming error. Every other
     * constructor here throws, because reaching them with an invalid date means
     * a bug upstream.
     */
    public static function tryFromInput(string $raw): ?self
    {
        if (! preg_match('/^([A-Za-z]+|\d{1,2})-(\d{4})$/', trim($raw), $matches)) {
            return null;
        }

        $monthPart = $matches[1];
        $year = (int) $matches[2];

        $month = ctype_digit($monthPart)
            ? (int) $monthPart
            : (self::MONTH_NUMBERS[strtolower($monthPart)] ?? 0);

        try {
            return self::fromYearMonth($year, $month);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function sortKey(): int
    {
        return $this->year * 100 + $this->month;
    }

    public function toDisplay(): string
    {
        return self::MONTH_NAMES[$this->month] . ' ' . $this->year;
    }

    /**
     * The round-trippable form used to populate editable inputs.
     */
    public function toInputValue(): string
    {
        return sprintf('%02d-%d', $this->month, $this->year);
    }

    public function equals(self $other): bool
    {
        return $this->sortKey() === $other->sortKey();
    }
}
