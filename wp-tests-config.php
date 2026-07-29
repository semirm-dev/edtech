<?php

declare(strict_types=1);

$testsDbName = getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test';

// Guard against ever pointing the test suite at the development database:
// wordpress_test's tables get wiped/reinstalled on every run.
if ($testsDbName === 'db') {
    fwrite(STDERR, "Refusing to run: DB_NAME resolved to 'db', the development database. Set WP_TESTS_DB_NAME to something else (default: wordpress_test).\n");
    exit(1);
}

define('DB_NAME', $testsDbName);
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'root');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'localhost');
define('WP_TESTS_EMAIL', 'admin@example.com');
define('WP_TESTS_TITLE', 'Course Discovery Tests');
define('WP_PHP_BINARY', 'php');
define('ABSPATH', __DIR__ . '/wp/');
