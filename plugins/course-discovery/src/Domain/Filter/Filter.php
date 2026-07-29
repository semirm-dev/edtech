<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

use CourseDiscovery\Domain\Constraint\Constraint;

/**
 * One way of narrowing a course search.
 *
 * Implementations are final with no AbstractFilter — shared behaviour is
 * composed in, not inherited. constrain() returns a declarative Constraint
 * rather than building SQL, so a filter is unit-testable without a
 * database and a third-party filter cannot inject SQL.
 */
interface Filter
{
    public function key(): FilterKey;

    /**
     * Human-readable, already translated.
     *
     * The `__()` call runs at construction, not when label() is called,
     * and the text domain only loads on `init` priority 0 (see
     * Plugin::boot()). Anything that builds filters must stay lazy and
     * not resolve before `init`, or the string ships untranslated for the
     * rest of the request with no second chance to retranslate it.
     */
    public function label(): string;

    public function inputType(): FilterInputType;

    /**
     * The choices to offer. Empty for free-text filters.
     *
     * $context is the rest of the current search (e.g. so a "provider"
     * filter could one day offer only providers with results under an
     * already-selected location); every implementation today ignores it.
     * Kept as an optional parameter now so adding real use later isn't a
     * breaking signature change. Implementations MAY ignore $context
     * entirely.
     */
    public function options(?SearchCriteria $context = null): FilterOptions;

    /**
     * A short hint for the input: placeholder text for a free-text field,
     * or aria-describedby copy for a combobox. Null when the filter has
     * nothing to add beyond its label.
     */
    public function description(): ?string;

    /**
     * Null when the selection cannot restrict anything — an empty set, or
     * values that resolve to nothing — so the filter is omitted from the
     * query rather than building something meaningless.
     *
     * TRAP for attribute-backed implementations: guard on the CONVERTED
     * values, not the raw selection. FilterValues::toInts() silently
     * discards invalid entries, so the obvious
     * `$values->isEmpty() ? null : new AttributeInConstraint($key, $values->toInts())`
     * passes a quick check but THROWS on garbage input (e.g. ['abc']),
     * since toInts() then returns [] to a constructor that rejects an
     * empty list. Guard on `$values->toInts()` itself instead.
     */
    public function constrain(FilterValues $values): ?Constraint;
}
