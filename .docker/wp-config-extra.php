// Extra wp-config.php directives, injected via WORDPRESS_CONFIG_EXTRA by
// .docker/entrypoint.sh.
//
// NO <?php OPENING TAG, deliberately: the base image's wp-config-docker.php
// passes this content to eval(), which expects bare statements and raises a
// parse error on an opening tag. The .php extension is kept only so editors
// still highlight it.
//
// Note what is NOT here: promoting X-Forwarded-Proto to $_SERVER['HTTPS'].
// wp-config-docker.php already does that unconditionally, so behind a
// TLS-terminating edge proxy WordPress sees the request as HTTPS without
// help. What it cannot infer is the canonical public hostname, which is
// what this file supplies.

// Without a pinned WP_HOME/WP_SITEURL, WordPress falls back to whatever
// host reached PHP -- the container's internal address behind a proxy --
// and bakes that into every generated URL, asset link and redirect. Pinning
// them to the platform's public domain also means the site survives being
// redeployed onto a different internal host with no database rewrite.
//
// RAILWAY_PUBLIC_DOMAIN is injected by Railway (form: example.up.railway.app);
// CD_SITE_DOMAIN is the manual equivalent for any other host, including the
// local compose smoke test.
$cd_domain = getenv('RAILWAY_PUBLIC_DOMAIN') ?: getenv('CD_SITE_DOMAIN');

if ($cd_domain) {
    // Defaults to https because every managed platform terminates TLS for
    // you; the local compose harness sets CD_SITE_SCHEME=http, where there
    // is no certificate and forcing https would make the site unreachable.
    $cd_scheme = getenv('CD_SITE_SCHEME') ?: 'https';

    define('WP_HOME', $cd_scheme . '://' . $cd_domain);
    define('WP_SITEURL', WP_HOME);

    if ($cd_scheme === 'https') {
        // Refuse to serve wp-admin or wp-login over plain HTTP, so an
        // administrator password cannot be submitted in clear text.
        define('FORCE_SSL_ADMIN', true);
    }
}

// The container filesystem is rebuilt from the image on every deploy, so
// edits made through the admin plugin/theme editor would silently vanish --
// worse, that editor is a direct arbitrary-PHP-execution path for anyone who
// gets an administrator session. Disabling it costs nothing here because the
// code ships in the image.
define('DISALLOW_FILE_EDIT', true);
