<?php

/**
 * Blocks until the database accepts connections, or gives up.
 *
 * Deliberately plain PHP over mysqli rather than `wp db check`: that WP-CLI
 * command shells out to the mysqlcheck binary, which the wordpress base
 * image does not ship, so using it would mean installing a MySQL client
 * package purely to answer a yes/no question the bundled mysqli extension
 * already answers.
 *
 * Managed databases are not necessarily up when the app container starts --
 * on Railway the two services boot independently, with no compose-style
 * `depends_on: service_healthy` to sequence them -- so a first-boot install
 * that did not wait would fail on a database that becomes ready seconds
 * later.
 */

const MAX_ATTEMPTS = 60;
const SLEEP_SECONDS = 2;

$host = getenv('WORDPRESS_DB_HOST') ?: 'db';
$port = 3306;

// WORDPRESS_DB_HOST carries an optional :port suffix (the wordpress image's
// own convention, and how a managed database's host/port pair gets passed
// through as one value). An IPv6 literal would also contain colons, but
// managed providers hand out hostnames, so splitting on the last colon only
// when the tail is numeric is enough and cannot mangle a bare hostname.
if (preg_match('/^(.*):(\d+)$/', $host, $matches) === 1) {
    $host = $matches[1];
    $port = (int) $matches[2];
}

$user = getenv('WORDPRESS_DB_USER') ?: '';
$password = getenv('WORDPRESS_DB_PASSWORD') ?: '';
$database = getenv('WORDPRESS_DB_NAME') ?: '';

// Return failures as false/errno rather than throwing, so the retry loop
// below controls the flow instead of a fatal on the first refused socket.
mysqli_report(MYSQLI_REPORT_OFF);

for ($attempt = 1; $attempt <= MAX_ATTEMPTS; $attempt++) {
    $link = @mysqli_connect($host, $user, $password, $database, $port);

    if ($link !== false) {
        mysqli_close($link);
        fwrite(STDERR, "Database reachable at {$host}:{$port}.\n");
        exit(0);
    }

    fwrite(STDERR, sprintf(
        "Waiting for database at %s:%d (%d/%d): %s\n",
        $host,
        $port,
        $attempt,
        MAX_ATTEMPTS,
        mysqli_connect_error()
    ));

    sleep(SLEEP_SECONDS);
}

fwrite(STDERR, sprintf(
    "ERROR: database at %s:%d not reachable after %d attempts (~%d seconds).\n"
        . "Check that the database service is running and that WORDPRESS_DB_HOST,\n"
        . "WORDPRESS_DB_USER, WORDPRESS_DB_PASSWORD and WORDPRESS_DB_NAME are set.\n",
    $host,
    $port,
    MAX_ATTEMPTS,
    MAX_ATTEMPTS * SLEEP_SECONDS
));

exit(1);
