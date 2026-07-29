<?php

declare(strict_types=1);

namespace CourseDiscovery\ContentModel;

use CourseDiscovery\Domain\StartDate;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The WordPress-facing edge of start dates: parsing user input, reading stored
 * meta, and localised rendering.
 *
 * The date RULES themselves — valid year range, valid month, the YYYYMM
 * sort-key encoding, the month vocabulary, and both string forms — live in
 * Domain\StartDate and are delegated to from here. This class must not restate
 * any of them. What it adds is everything the domain cannot own: WordPress
 * calls (get_post_meta, wp_date), the storage shape written to postmeta, and a
 * lenient error policy.
 *
 * Error policy is the real difference between the two classes. Domain\StartDate
 * is strict: an invalid date is unconstructable and throws. Stored meta cannot
 * be trusted (WP-CLI, imports, direct DB writes), so everything here degrades
 * to null or '' instead. toDomain() is the single bridge between the two.
 */
final class StartDates
{
    public const META_KEY = '_cd_course_start_dates';

    /**
     * Parses "03-2026" or "March-2026" into the sort key 202603, or null if
     * the string is not a date this system accepts.
     *
     * Kept as a sort-key-returning wrapper because every caller here stores or
     * compares sort keys rather than objects; the parsing itself, and the
     * month vocabulary it needs, belong to Domain\StartDate.
     */
    public static function parse(string $raw): ?int
    {
        return StartDate::tryFromInput($raw)?->sortKey();
    }

    /**
     * Renders the sort key 202603 as the fixed, ALWAYS-ENGLISH string
     * "March 2026", regardless of site locale.
     *
     * NOT for user-facing WordPress UI -- use formatLocalised() there, which
     * goes through wp_date(). One-way: use formatForInput() when the value
     * must round-trip back through parse(). Degrades to an empty string for
     * an out-of-range month rather than erroring, since stored values may not
     * be well-formed.
     */
    public static function format(int $sortKey): string
    {
        return self::toDomain($sortKey)?->toDisplay() ?? '';
    }

    /**
     * Renders the sort key 202603 as "March 2026", translated into the site's
     * active locale via wp_date() -- e.g. "mars 2026" under French, while
     * format() would still return English. This is the formatter user-facing
     * WordPress UI should call (e.g. AdminColumns::renderNextStart()).
     *
     * Degrades to an empty string for an out-of-range month, same as format().
     *
     * The UTC timezone argument to wp_date() is deliberate: a bare month/year
     * has no timezone of its own, so the instant gmmktime() builds (1st of the
     * month, 00:00 UTC) must be read back in that same zone. Omitting it lets
     * wp_date() re-project into the site's configured timezone, which rolls
     * negative-offset sites (e.g. America/New_York) back into the previous
     * month -- 202603 would render "February 2026" instead of "March 2026".
     */
    public static function formatLocalised(int $sortKey): string
    {
        $date = self::toDomain($sortKey);

        if ($date === null) {
            return '';
        }

        $timestamp = gmmktime(0, 0, 0, $date->month, 1, $date->year);

        if ($timestamp === false) {
            return '';
        }

        $localised = wp_date('F Y', $timestamp, new DateTimeZone('UTC'));

        return $localised !== false ? $localised : '';
    }

    /**
     * Renders the sort key 202603 as the round-trippable form "03-2026" for
     * editable form fields: parse(formatForInput($key)) === $key for every
     * valid key. Degrades to an empty string for an out-of-range month, same
     * as format().
     */
    public static function formatForInput(int $sortKey): string
    {
        return self::toDomain($sortKey)?->toInputValue() ?? '';
    }

    /**
     * @param  list<string> $raw
     * @return list<int>    unique sort keys, chronologically ascending
     */
    public static function normaliseList(array $raw): array
    {
        $keys = [];

        foreach ($raw as $entry) {
            $parsed = self::parse($entry);

            if ($parsed !== null) {
                $keys[] = $parsed;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Reads the stored start-date meta for a course and narrows it to a list
     * of integer sort keys. The one WordPress-touching member of an otherwise
     * pure class, kept contained so the parsing/formatting methods stay
     * unit-testable without WordPress loaded.
     *
     * Stored meta cannot be assumed well-formed (WP-CLI, imports, direct DB
     * writes): a non-array value yields an empty list, numeric strings are
     * converted with intval(), anything else is discarded.
     *
     * @return list<int>
     */
    public static function storedKeys(int $postId): array
    {
        /** @var mixed $stored */
        $stored = get_post_meta($postId, self::META_KEY, true);

        if (! is_array($stored)) {
            return [];
        }

        return self::narrowToIntKeys($stored);
    }

    /**
     * Pure helper: narrows a mixed array to a list<int>. Kept free of
     * WordPress calls so the normalisation logic itself stays
     * unit-testable in isolation from storedKeys()'s get_post_meta()
     * call above.
     *
     * @param  array<mixed> $values
     * @return list<int>
     */
    private static function narrowToIntKeys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $keys[] = $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $keys[] = intval($value);
            }
        }

        return $keys;
    }

    /**
     * The one bridge between the domain's strict rules and this layer's
     * lenient error policy: every formatter here routes through it, so the
     * validity rules exist in exactly one place (Domain\StartDate) while
     * malformed stored data still degrades to '' instead of fatalling a page.
     *
     * Rejects a month outside 1..12 AND a year outside the domain's accepted
     * range — a sort key such as 199912 can only have reached the database by
     * bypassing parse(), so rendering it would be presenting data the system
     * never considered valid.
     */
    private static function toDomain(int $sortKey): ?StartDate
    {
        try {
            return StartDate::fromSortKey($sortKey);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
