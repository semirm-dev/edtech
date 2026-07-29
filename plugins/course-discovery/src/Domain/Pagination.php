<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain;

use InvalidArgumentException;

final readonly class Pagination
{
    private const DEFAULT_PER_PAGE = 12;
    private const MAX_PER_PAGE = 100;

    /**
     * Upper bound on page, chosen to keep page * perPage far below
     * PHP_INT_MAX so offset() cannot overflow to a float and break its
     * int return type. Page is untrusted input built from a URL query
     * parameter, and (int) casting an arbitrarily long digit string
     * saturates to PHP_INT_MAX rather than erroring.
     */
    private const MAX_PAGE = 10000;

    /** @var positive-int */
    public int $page;

    /** @var positive-int */
    public int $perPage;

    /**
     * Throws on out-of-range input — a value object must not be
     * constructible in an invalid state, which is right for callers that
     * already know $page/$perPage are valid (e.g. default(), or values
     * computed internally). This is the WRONG entry point for untrusted
     * input such as a `?cd_paged=` parameter: `?cd_paged=0`,
     * `?cd_paged=abc`, or `?cd_paged=99999` would all throw here, uncaught,
     * and fatal a public page. Use clamp() for anything derived from a
     * request instead.
     */
    public function __construct(int $page, int $perPage)
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException(
                sprintf('Page must be 1-%d, got %d.', self::MAX_PAGE, $page)
            );
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(
                sprintf('Per-page must be 1-%d, got %d.', self::MAX_PER_PAGE, $perPage)
            );
        }

        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * The entry point for untrusted input (e.g. a `?cd_paged=` parameter).
     * Clamps both values into range instead of throwing, since an
     * out-of-range request parameter is common and should fall back to
     * the nearest valid page rather than fatal a public page. The
     * constructor's throwing behaviour still applies for callers who
     * already know their input is valid — this is the one deliberate
     * exception to "invalid state is unconstructable", named explicitly
     * rather than hidden in a lenient constructor.
     */
    public static function clamp(int $page, int $perPage): self
    {
        $clampedPage = max(1, min($page, self::MAX_PAGE));
        $clampedPerPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return new self($clampedPage, $clampedPerPage);
    }

    public static function default(): self
    {
        return new self(1, self::DEFAULT_PER_PAGE);
    }

    /** @return int<0, max> */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
