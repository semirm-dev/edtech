<?php

declare(strict_types=1);

namespace CourseDiscovery\Domain\Constraint;

/**
 * Escape hatch for a restriction the typed constraints cannot express.
 *
 * Deliberately opt-in and greppable: raw SQL is not the ambient default, so
 * any use of this class is visible in review. Bindings are still passed
 * through the builder's $wpdb->prepare() call — never interpolate into
 * $sql.
 */
final readonly class RawConstraint implements Constraint
{
    /**
     * @param literal-string $sql Must be a literal written in the calling
     *        code, never assembled at runtime. The `literal-string` type is a
     *        STATIC hint PHPStan enforces only within THIS repo's source; it
     *        is not a runtime guard and PHPStan does not analyse third-party
     *        plugins, so `new RawConstraint($_GET['x'])` in a plugin is a SQL
     *        injection the CALLER alone is responsible for avoiding.
     * @param list<scalar> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {
    }
}
