#!/usr/bin/env bash
# Container entrypoint: prepare -> initialise -> serve, in that order, in a
# single process tree.
#
# The compose stack this replaces split these across two services (a
# long-running `wordpress` and a one-shot `init` on the WP-CLI image,
# sharing a volume). Railway has no one-shot-sidecar concept, so the three
# phases are sequenced here instead. Nothing runs in the background and
# nothing polls for another container to appear.
set -euo pipefail

# --- Apache must listen on the port the platform assigns -------------------
#
# Railway injects PORT and routes to it; plain `docker run`/compose does
# not, hence the 80 fallback. Both the Listen directive and the vhost need
# it -- Apache fails to start with "NameVirtualHost ... has no VirtualHosts"
# style breakage if only one is changed.
PORT="${PORT:-80}"
sed -ri "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \\*:80>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf

# --- Exactly one MPM ------------------------------------------------------
#
# Apache aborts with "AH00534: apache2: Configuration error: More than one
# MPM loaded" if mods-enabled holds more than one mpm_*.load. mod_php
# requires prefork, and the base image ships prefork alone -- but a build
# elsewhere can end up with mpm_event enabled beside it (Debian's apache2
# package enables event by default, so anything that reinstalls or upgrades
# apache2 during a build reintroduces it).
#
# That is exactly how this bit: every local build was fine, and the build
# Railway produced from the identical Dockerfile and an identical base image
# digest was not. Rather than depend on build-time state being right,
# normalise it here so the running container is correct either way. Both
# operations are no-ops when the image is already correct.
for mpm in event worker; do
    rm -f "/etc/apache2/mods-enabled/mpm_${mpm}.load" \
          "/etc/apache2/mods-enabled/mpm_${mpm}.conf"
done

if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
    ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi

# --- wp-config.php additions ----------------------------------------------
#
# The base image evaluates WORDPRESS_CONFIG_EXTRA inside the generated
# wp-config.php. Keeping the snippet in a real file under version control
# beats pasting multi-line PHP into a hosting dashboard's variable editor,
# where a stray newline is silently accepted and debugged later. An
# explicitly-set variable still wins, so a deploy can override without a
# rebuild.
if [ -z "${WORDPRESS_CONFIG_EXTRA:-}" ]; then
    WORDPRESS_CONFIG_EXTRA="$(cat /docker-init/wp-config-extra.php)"
    export WORDPRESS_CONFIG_EXTRA
fi

# --- Phase 1: let the base image lay down core and write wp-config.php -----
#
# The image's entrypoint runs that setup only when its first argument
# matches `apache2*` or is `php-fpm` (read from
# /usr/local/bin/docker-entrypoint.sh), and then execs that argument.
# `apache2 -v` satisfies the guard, prints the version and exits -- so the
# full setup runs to completion without starting a server, and phase 2 gets
# an installed core and a valid wp-config.php to work against. Phase 3
# re-runs the same setup, which is idempotent: core is already present and
# wp-config.php is only written when missing.
echo "==> Preparing WordPress core and configuration..."
docker-entrypoint.sh apache2 -v > /dev/null

# --- Phase 2: site initialisation (idempotent) -----------------------------
/docker-init/init.sh

# --- Phase 3: serve --------------------------------------------------------
#
# Config test first. Apache's own failure output names the problem but not
# the state that caused it, and a crash-looping container reprints that one
# line forever without ever showing what is actually enabled -- which turned
# a one-line misconfiguration into a long diagnosis. Dumping the module list
# on failure makes the next occurrence self-explanatory.
if ! apache2ctl configtest > /dev/null 2>&1; then
    echo "ERROR: Apache configuration test failed:" >&2
    apache2ctl configtest >&2 || true
    echo "Enabled modules:" >&2
    ls -1 /etc/apache2/mods-enabled/ >&2
    exit 1
fi

echo "==> Starting Apache on port ${PORT}..."
exec docker-entrypoint.sh apache2-foreground
