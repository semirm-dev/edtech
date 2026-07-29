<?php

declare(strict_types=1);

/**
 * Ensures the integration test database exists before PHPUnit runs.
 *
 * Run as a step in the `test:integration` composer script (see
 * composer.json) so a fresh clone/`ddev start` never needs an undocumented
 * manual `CREATE DATABASE` step. Reads the same WP_TESTS_DB_* env vars, with
 * the same defaults, as wp-tests-config.php, so the database this script
 * creates and the one the tests connect to can never disagree.
 *
 * A composer script (rather than a DDEV post-start hook) was chosen because
 * it runs every time the suite runs, regardless of whether the developer
 * restarted DDEV since the last `composer install` — a post-start hook only
 * fires on `ddev start`/`ddev restart` and would miss a stale/dropped
 * database in between.
 */

$dbHost = getenv('WP_TESTS_DB_HOST') ?: 'db';
$dbUser = getenv('WP_TESTS_DB_USER') ?: 'root';
$dbPassword = getenv('WP_TESTS_DB_PASSWORD') ?: 'root';
$dbName = getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test';

if ($dbName === 'db') {
    fwrite(STDERR, "Refusing to create/use database 'db' — that's the development database.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

$connection = mysqli_connect($dbHost, $dbUser, $dbPassword);

if ($connection === false) {
    fwrite(STDERR, sprintf("Could not connect to MySQL at '%s': %s\n", $dbHost, mysqli_connect_error()));
    exit(1);
}

$escapedName = $connection->real_escape_string($dbName);

if (! $connection->query(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $escapedName))) {
    fwrite(STDERR, sprintf("Could not create database '%s': %s\n", $dbName, $connection->error));
    $connection->close();
    exit(1);
}

$connection->close();
