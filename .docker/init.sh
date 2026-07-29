#!/usr/bin/env bash
# Idempotent site initialisation, run from entrypoint.sh on EVERY container
# start -- not once at provisioning time.
#
# That distinction drives the whole design below. The compose stack this
# replaces ran the equivalent in a one-shot `init` service, so "runs again"
# meant "an operator re-ran it". Here a redeploy, a crash-restart or a
# platform-initiated move all re-enter this script against a database that
# is already populated. Every step therefore checks the world before
# changing it, and the seed -- which is destructive by design -- will not
# fire against a site that already has courses unless explicitly told to.
set -euo pipefail

cd /var/www/html

wp() { command wp --allow-root "$@"; }

# --- Resolve the public URL ------------------------------------------------
# RAILWAY_PUBLIC_DOMAIN is injected by Railway (example.up.railway.app);
# CD_SITE_URL is the manual equivalent for any other host. Kept consistent
# with .docker/wp-config-extra.php, which pins WP_HOME/WP_SITEURL from the
# same inputs.
if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
    CD_SITE_URL="${CD_SITE_SCHEME:-https}://${RAILWAY_PUBLIC_DOMAIN}"
fi
CD_SITE_URL="${CD_SITE_URL:-http://localhost:8080}"

CD_ADMIN_USER="${CD_ADMIN_USER:-admin}"
CD_ADMIN_EMAIL="${CD_ADMIN_EMAIL:-admin@example.com}"

# Record whether a password was actually supplied BEFORE defaulting, so the
# guard below can tell "the variable never arrived" apart from "it arrived
# holding a placeholder". Applying the default first collapses both into
# the string "admin" and loses the distinction -- which is exactly the
# ambiguity that made a real deployment failure take several rounds to
# diagnose.
CD_ADMIN_PASSWORD_SUPPLIED="${CD_ADMIN_PASSWORD:+yes}"
CD_ADMIN_PASSWORD="${CD_ADMIN_PASSWORD:-admin}"

# --- Secrets guard ---------------------------------------------------------
# Refuse to install a non-local site while the admin password is still a
# known default. The defaults above exist so a first local boot works with
# zero configuration -- but the same defaults reaching a public URL would
# mean a real, internet-reachable administrator account protected by
# "admin".
#
# Only the admin password is checked. The compose stack also guarded
# DB_PASSWORD and DB_ROOT_PASSWORD because its own .env.example supplied
# weak defaults for them; a managed database generates its own strong
# credentials and exposes no root account to this container, so there is no
# equivalent default to catch.
#
# is_localhost_url does a real authority parse rather than a
# `case "$url" in http://localhost:*)` glob. A glob prefix-match would
# misclassify "http://localhost:8080@attacker.com" as localhost: per
# standard URL parsing the "user:pass@" form before an '@' is userinfo, not
# the host, so that URL's real host is attacker.com -- but a prefix glob
# never notices the '@'. That would let a genuinely public URL past the
# guard.
is_localhost_url() {
    local url="$1" authority host

    authority="${url#*://}"

    # Keep everything after the LAST '@' -- this is what defeats the
    # "localhost:8080@attacker.com" case above.
    authority="${authority##*@}"

    # Drop path/query/fragment.
    authority="${authority%%/*}"

    # Drop the port. Bracketed IPv6 literals ([::1]:port) are handled
    # separately since cutting at the first colon would mangle "::1".
    case "$authority" in
        \[*\]*)
            host="${authority#\[}"
            host="${host%%]*}"
            ;;
        *)
            host="${authority%%:*}"
            ;;
    esac

    # Exact match only, never a prefix match.
    case "$host" in
        localhost|127.0.0.1|::1) return 0 ;;
        *) return 1 ;;
    esac
}

if ! is_localhost_url "${CD_SITE_URL}"; then
    case "${CD_ADMIN_PASSWORD}" in
        admin|password|changeme|'')
            # Say WHICH failure this is. "Still a default" is ambiguous
            # between an unset variable and a literally-default one, and
            # those have different fixes -- one means the variable never
            # reached the container (wrong service, unapplied change), the
            # other means it arrived carrying a placeholder value. Without
            # this distinction the only way to tell them apart is to go
            # read the value in a dashboard.
            #
            # Naming the offending value is safe here precisely because
            # every branch that reaches it is a publicly-known placeholder,
            # never a real secret. The passing path below prints a length
            # and nothing else, for the same reason in reverse.
            if [ -z "${CD_ADMIN_PASSWORD_SUPPLIED}" ]; then
                diagnosis="CD_ADMIN_PASSWORD is empty or not set at all.
  The variable is either missing from this service, set on a different
  service, or the change has not been applied/redeployed yet."
            else
                diagnosis="CD_ADMIN_PASSWORD is set to the placeholder value '${CD_ADMIN_PASSWORD}'."
            fi

            cat >&2 <<EOF
ERROR: refusing to initialise -- unusable admin password on a public deploy.

  Site URL: ${CD_SITE_URL}
  ${diagnosis}

This does not look like localhost, so the site is publicly reachable.
Installing a public site with default credentials is a real security risk.

Fix: set CD_ADMIN_PASSWORD to a strong, unique value on the service that
runs WordPress (not on the database service), apply the change, and
redeploy. Generate one with:

  openssl rand -base64 24
EOF
            exit 1
            ;;
    esac

    # Confirms the variable actually arrived, without putting a secret in a
    # log that Railway retains and replays.
    echo "==> Admin password supplied (${#CD_ADMIN_PASSWORD} characters)."
fi
# --- End secrets guard -----------------------------------------------------

echo "==> Waiting for the database..."
php /docker-init/wait-for-db.php

if ! wp core is-installed > /dev/null 2>&1; then
    echo "==> Installing WordPress at ${CD_SITE_URL}..."
    wp core install \
        --url="${CD_SITE_URL}" \
        --title="Course Discovery" \
        --admin_user="${CD_ADMIN_USER}" \
        --admin_password="${CD_ADMIN_PASSWORD}" \
        --admin_email="${CD_ADMIN_EMAIL}" \
        --skip-email
else
    echo "==> WordPress already installed; skipping install."
fi

# Safety net for a database carried over from an install predating this
# image, where ACF may never have been present. Pinned to the same version
# the Dockerfile bakes so this fallback cannot drift onto a different
# release than the primary path.
if ! wp plugin is-installed advanced-custom-fields > /dev/null 2>&1; then
    echo "==> ACF missing; installing ${CD_ACF_VERSION:-6.8.6}..."
    wp plugin install advanced-custom-fields --version="${CD_ACF_VERSION:-6.8.6}"
fi

echo "==> Activating plugins..."
wp plugin activate advanced-custom-fields course-discovery course-discovery-example-extension

# The plugin's front end relies on pretty permalinks: the discovery page
# lives at /find-courses/ and its filters build URLs against that path.
#
# Soft flush deliberately -- no --hard. The --hard variant asks WordPress to
# regenerate .htaccess, which it refuses to do under WP-CLI (got_mod_rewrite()
# is false because the CLI SAPI sets no $_SERVER['SERVER_SOFTWARE']); it
# prints "Regenerating a .htaccess file requires special configuration" and
# carries on, so the flag bought a warning and nothing else. The equivalent
# rewrite rules ship in .docker/apache-course-discovery.conf instead, which
# is why no .htaccess is needed here.
echo "==> Ensuring pretty permalinks..."
wp rewrite structure '/%postname%/'
wp rewrite flush

# --- Seed ------------------------------------------------------------------
# bin/seed.sh DELETES every cd_course, cd_instructor and cd_provider post
# before recreating its fixtures -- including anything authored by hand in
# wp-admin. That is correct for a development reset and wrong to run
# unconditionally on a server that restarts, so:
#
#   auto  (default) seed only when there are no courses -- i.e. first boot
#   force            always reseed, discarding hand-authored content
#   skip             never seed
CD_SEED="${CD_SEED:-auto}"
course_count="$(wp post list --post_type=cd_course --format=count)"

case "${CD_SEED}" in
    force)
        echo "==> CD_SEED=force: reseeding (discards existing courses)..."
        CD_SEED_WP_NATIVE=1 bash /docker-init/seed.sh
        ;;
    skip)
        echo "==> CD_SEED=skip: leaving existing content alone."
        ;;
    auto)
        if [ "${course_count}" = "0" ]; then
            echo "==> No courses found; seeding demo content..."
            CD_SEED_WP_NATIVE=1 bash /docker-init/seed.sh
        else
            echo "==> ${course_count} courses already present; skipping seed."
        fi
        ;;
    *)
        echo "ERROR: CD_SEED must be one of auto, force, skip (got '${CD_SEED}')." >&2
        exit 1
        ;;
esac

echo "==> Publishing the discovery page..."
if ! wp post list --post_type=page --name=find-courses --field=ID | grep -q .; then
    wp post create --post_type=page --post_title="Find Courses" \
        --post_name=find-courses --post_content='[course_discovery]' \
        --post_status=publish
else
    echo "    /find-courses/ already exists."
fi

# Always rebuild, not just after a seed. The lookup tables are a derived
# projection, so rebuilding is never destructive -- and this is the step
# that picks up a migration shipped since the last deploy, since
# MigrationRunner otherwise waits for an admin_init.
echo "==> Rebuilding the search index..."
wp course-discovery reindex

echo "==> Init complete: ${CD_SITE_URL}/find-courses/"
