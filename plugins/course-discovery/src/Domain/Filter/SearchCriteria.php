<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Filter;

use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Domain\SortOrder;

/**
 * A search request as an object: the free-text term, the values picked per
 * filter, the sort order and the page — everything a user asked for, as one
 * immutable value. SearchService takes one of these and returns a SearchResult.
 *
 * Named "criteria" rather than "request" deliberately: it is a domain value
 * with no transport concerns, and "request" is already taken in WordPress by
 * WP_REST_Request and $_REQUEST. It is built FROM a request, not by one.
 *
 * Serialises to and from URL query parameters, which is what makes filtered
 * results shareable and the browser's back button work. Every mutation
 * returns a new instance, so a hook handler cannot alter a criteria object
 * another handler is holding.
 */
final readonly class SearchCriteria
{
    public const PARAM_TERM = 'q';
    public const PARAM_SORT = 'sort';
    public const PARAM_PAGE = 'paged';

    /**
     * Bounds untrusted term length (enforced in fromQueryParams()) so a
     * boolean-mode MATCH can't run against an arbitrarily long string —
     * not a rule about what a "real" search term looks like.
     */
    private const MAX_TERM_LENGTH = 200;

    /**
     * Bounds untrusted per-filter value count (enforced in
     * fromQueryParams()) so an IN clause can't get an attacker-controlled
     * placeholder count.
     */
    private const MAX_FILTER_VALUES = 50;

    /**
     * @param array<string, FilterValues> $filterValues the values picked per filter, keyed by filter key
     */
    private function __construct(
        public ?string $term,
        private array $filterValues,
        public SortOrder $sort,
        public Pagination $pagination,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, [], SortOrder::Soonest, Pagination::default());
    }

    /**
     * The only path that sets the free-text term. It's also the sole
     * storage for PARAM_TERM ('q'): KeywordFilter's key IS PARAM_TERM,
     * since free-text search is modelled as an ordinary filter, and
     * withFilter() delegates here for that key so the two APIs can't
     * disagree about the current term.
     */
    public function withTerm(?string $term): self
    {
        $trimmed = $term === null ? null : trim($term);

        return new self(
            $trimmed === '' ? null : $trimmed,
            $this->filterValues,
            $this->sort,
            $this->pagination
        );
    }

    /**
     * Sets the values selected for one filter. A call keyed on PARAM_TERM
     * ('q') delegates to withTerm() instead (using the first value, or
     * null when empty), since KeywordFilter's key IS PARAM_TERM and
     * writing to both would let them silently disagree. Never throws — a
     * third-party handler on the public `course_discovery/criteria` hook
     * passing the wrong shape here must not fatal the page.
     */
    public function withFilter(FilterKey $key, FilterValues $values): self
    {
        if ($key->queryParam() === self::PARAM_TERM) {
            return $this->withTerm($values->toStrings()[0] ?? null);
        }

        $filterValues = $this->filterValues;

        if ($values->isEmpty()) {
            unset($filterValues[$key->toString()]);
        } else {
            $filterValues[$key->toString()] = $values;
        }

        return new self($this->term, $filterValues, $this->sort, $this->pagination);
    }

    public function withSort(SortOrder $sort): self
    {
        return new self($this->term, $this->filterValues, $sort, $this->pagination);
    }

    public function withPagination(Pagination $pagination): self
    {
        return new self($this->term, $this->filterValues, $this->sort, $pagination);
    }

    /**
     * The values picked for one filter, whichever filter it is.
     *
     * The free-text term is stored in its own $term property rather than in
     * $filterValues (see withTerm() for why the two must not both hold it),
     * but KeywordFilter is an ordinary registered filter whose key IS
     * PARAM_TERM. Adapting it here means every caller — the search loop, the
     * form renderer — reads every filter the same way, instead of each one
     * re-implementing "unless it's the keyword filter".
     */
    public function valuesFor(FilterKey $key): FilterValues
    {
        if ($key->queryParam() === self::PARAM_TERM) {
            return $this->term === null
                ? FilterValues::empty()
                : FilterValues::fromStrings([$this->term]);
        }

        return $this->filterValues[$key->toString()] ?? FilterValues::empty();
    }

    /**
     * @return list<string>
     */
    public function activeFilterKeys(): array
    {
        return array_keys($this->filterValues);
    }

    /**
     * Defaults are omitted so a pristine search produces a clean URL. Page
     * SIZE (perPage) is never serialised, and fromQueryParams() never
     * reads it from the URL either — a forged ?perPage= would otherwise
     * force an expensive page size onto every future request that reuses
     * the URL; the caller supplies it out of band instead (see
     * fromQueryParams()'s third parameter).
     *
     * @return array<string, string|list<string>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->term !== null) {
            $params[self::PARAM_TERM] = $this->term;
        }

        foreach ($this->filterValues as $key => $values) {
            $params[$key] = $values->toStrings();
        }

        if ($this->sort !== SortOrder::Soonest) {
            $params[self::PARAM_SORT] = $this->sort->value;
        }

        if ($this->pagination->page > 1) {
            $params[self::PARAM_PAGE] = (string) $this->pagination->page;
        }

        return $params;
    }

    /**
     * Rebuilds criteria from untrusted input. Only keys in $known are
     * read, so an unknown parameter cannot introduce a filter, and
     * pagination is clamped rather than validated so a hostile ?paged=
     * can't throw on a public page. Page SIZE is not read from $params —
     * see toQueryParams() — the caller supplies it via $perPage, defaulting
     * to Pagination::default()->perPage. Term length and filter value
     * count are capped (MAX_TERM_LENGTH / MAX_FILTER_VALUES) to bound
     * query cost against a hostile request, not to validate content.
     *
     * @param array<string, mixed> $params
     * @param list<FilterKey>      $known
     */
    public static function fromQueryParams(array $params, array $known, ?int $perPage = null): self
    {
        $criteria = self::empty();

        $rawTerm = $params[self::PARAM_TERM] ?? null;

        if (is_string($rawTerm)) {
            $criteria = $criteria->withTerm(mb_substr($rawTerm, 0, self::MAX_TERM_LENGTH));
        }

        foreach ($known as $key) {
            // The keyword filter's key IS the term parameter. Reading it here
            // as well would apply the same text constraint twice — once from
            // the filter loop and once from the term. The term owns it.
            if ($key->queryParam() === self::PARAM_TERM) {
                continue;
            }

            $raw = $params[$key->queryParam()] ?? null;

            if ($raw === null) {
                continue;
            }

            $list = is_array($raw) ? array_slice($raw, 0, self::MAX_FILTER_VALUES) : [$raw];

            $strings = [];

            foreach ($list as $value) {
                if (is_string($value)) {
                    $strings[] = $value;
                } elseif (is_int($value)) {
                    $strings[] = (string) $value;
                }
            }

            if ($strings !== []) {
                $criteria = $criteria->withFilter($key, FilterValues::fromStrings($strings));
            }
        }

        $rawSort = $params[self::PARAM_SORT] ?? null;

        if (is_string($rawSort)) {
            $sort = SortOrder::tryFrom($rawSort);

            if ($sort instanceof SortOrder) {
                $criteria = $criteria->withSort($sort);
            }
        }

        $rawPage = $params[self::PARAM_PAGE] ?? null;
        $page = is_string($rawPage) || is_int($rawPage) ? (int) $rawPage : 1;

        return $criteria->withPagination(
            Pagination::clamp($page, $perPage ?? Pagination::default()->perPage)
        );
    }
}
