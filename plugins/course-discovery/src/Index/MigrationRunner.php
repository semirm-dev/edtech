<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use CourseDiscovery\Index\Migrations\M001CreateLookupTables;
use CourseDiscovery\Index\Migrations\M002AlterAttributeValueUnsigned;
use CourseDiscovery\Index\Migrations\M003AddTitleColumn;
use RuntimeException;
use wpdb;

/**
 * Applies pending schema migrations exactly once, in version order.
 *
 * The applied version lives in the options table, so a fresh clone plus
 * plugin activation reproduces the schema with no manual step.
 */
final class MigrationRunner
{
    public const OPTION = 'cd_schema_version';

    public function __construct(
        private readonly wpdb $db,
        private readonly Schema $schema,
    ) {
    }

    /**
     * @return list<Migration>
     */
    private function migrations(): array
    {
        $migrations = [
            new M001CreateLookupTables(),
            new M002AlterAttributeValueUnsigned(),
            new M003AddTitleColumn(),
        ];

        usort($migrations, static fn (Migration $a, Migration $b): int => $a->version() <=> $b->version());

        return $migrations;
    }

    public function currentVersion(): int
    {
        $stored = get_option(self::OPTION, 0);

        return is_numeric($stored) ? (int) $stored : 0;
    }

    /**
     * The newest version any known migration declares. Pure computation --
     * building the fixed migration list costs nothing beyond a handful of
     * object allocations, no database or option access -- so callers can
     * compare it against currentVersion() cheaply (see runIfPending()).
     */
    public function latestVersion(): int
    {
        $migrations = $this->migrations();

        if ($migrations === []) {
            return 0;
        }

        return $migrations[count($migrations) - 1]->version();
    }

    /**
     * Runs any pending migrations, but only if the recorded version is
     * behind the latest known one.
     *
     * A site activated before a later migration shipped would otherwise
     * keep the old schema forever, since activation hooks never run again.
     * Registered on `admin_init` in Plugin::boot() so that gap closes
     * itself on the next admin page load. Cheap on the common (up-to-date)
     * path: just a get_option() read and a no-op loop when nothing is
     * pending.
     */
    public function runIfPending(): void
    {
        if ($this->currentVersion() < $this->latestVersion()) {
            $this->run();
        }
    }

    /**
     * @return list<int> versions applied by this call
     */
    public function run(): array
    {
        $current = $this->currentVersion();
        $applied = [];

        foreach ($this->migrations() as $migration) {
            if ($migration->version() <= $current) {
                continue;
            }

            $migration->up($this->db, $this->schema, $this->execute(...));
            update_option(self::OPTION, $migration->version(), false);

            $applied[] = $migration->version();
        }

        return $applied;
    }

    /**
     * $wpdb->query() returns false on failure rather than throwing, so
     * without this check a migration could be recorded as applied even
     * though its schema change never landed. Every migration must route
     * its statements through this method (via the callable passed to
     * Migration::up()) rather than calling $db->query() directly.
     */
    private function execute(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException(sprintf('Migration query failed: %s', $this->db->last_error));
        }
    }
}
