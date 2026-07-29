# Production image for Railway (and any other plain-Docker host).
#
# DDEV is the development environment and is not involved here: DDEV serves
# the site from wp/ as its docroot and bind-mounts plugins/ into
# wp/wp-content/plugins/, neither of which exists on a PaaS. This image is
# self-contained instead -- WordPress core, ACF, both plugins, the Composer
# autoloader and WP-CLI are all baked in, and the only external dependency
# at runtime is the database.

# ---- Stage 1: generate the Composer autoloader ----------------------------
#
# The plugin has no third-party runtime dependency -- composer.json's
# "require" is just a PHP version floor. The entire point of this stage is
# the PSR-4 class map itself (CourseDiscovery\ -> plugins/course-discovery/
# src/, per composer.json's "autoload" block): the plugin bootstrap
# (plugins/course-discovery/course-discovery.php) walks upward from its own
# directory looking for vendor/autoload.php, and without it the bootstrap
# hits its "run composer install" notice branch and returns without
# booting -- `wp plugin activate course-discovery` then fatals with
# "Class CourseDiscovery\Plugin not found".
#
# Only composer.json, composer.lock and the autoloaded source tree are
# copied in -- not the whole repo -- so a stale host vendor/ can never leak
# in, and .dockerignore excludes vendor/ from the build context as a second
# guard against the same thing.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
COPY plugins/course-discovery/src ./plugins/course-discovery/src

# --no-dev: phpunit/phpstan/etc are dev-only tooling with no runtime code
# path through the plugin bootstrap or any src/ class -- they live solely
# under composer.json's "require-dev". Dropping them is correct for a
# runtime image, not a shortcut that risks missing a real dependency.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---- Stage 2: the WordPress image itself -----------------------------------
FROM wordpress:php8.4-apache

# ACF (free) at a pinned version, matching README §3's "ACF free edition"
# row. PRO is licence-gated and cannot be redistributed, which is why
# repeating start dates use the hand-built StartDatesMetaBox rather than
# PRO's Repeater field.
ARG ACF_VERSION=6.8.6

# The base image (Debian trixie) ships curl but not unzip.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

# WP-CLI. The compose stack this replaces ran install/seed/reindex in a
# separate one-shot `init` service on the wordpress:cli-php8.4 image,
# because wordpress:php8.4-apache does not ship WP-CLI. Railway has no
# equivalent of a one-shot sidecar sharing a volume -- a service is a
# long-running process -- so the CLI is baked into this image instead and
# .docker/init.sh runs from the entrypoint before Apache starts.
RUN set -eux; \
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
        -o /usr/local/bin/wp; \
    chmod +x /usr/local/bin/wp; \
    wp --allow-root --version

RUN set -eux; \
    curl -fsSL "https://downloads.wordpress.org/plugin/advanced-custom-fields.${ACF_VERSION}.zip" \
        -o /tmp/acf.zip; \
    unzip -q /tmp/acf.zip -d /usr/src/wordpress/wp-content/plugins/; \
    rm /tmp/acf.zip

# Everything below lands in /usr/src/wordpress, NOT /var/www/html.
#
# The base image ships /var/www/html EMPTY and keeps core in
# /usr/src/wordpress; its entrypoint copies the whole of /usr/src/wordpress
# into /var/www/html at container start, but only when /var/www/html has
# neither index.php nor wp-includes/version.php (verified by reading
# /usr/local/bin/docker-entrypoint.sh in the image). Writing our files to
# /var/www/html directly would leave the directory non-empty in a way that
# is easy to get wrong; staging them in /usr/src/wordpress lets that same
# proven copy carry them across, so there is exactly one mechanism placing
# files, not two.
COPY plugins/course-discovery /usr/src/wordpress/wp-content/plugins/course-discovery
COPY plugins/course-discovery-example-extension /usr/src/wordpress/wp-content/plugins/course-discovery-example-extension

# The SAME plugin source, a second time, at the path Composer's generated
# autoloader expects.
#
# vendor/composer/autoload_psr4.php resolves the CourseDiscovery namespace
# as dirname($vendorDir) . '/plugins/course-discovery/src' -- computed
# dynamically via dirname(__DIR__) on every request, not baked in from
# wherever `composer install` ran. With vendor/ at /var/www/html/vendor
# that is /var/www/html/plugins/course-discovery/src. This image serves
# wp-content straight from /var/www/html (a flat layout, unlike DDEV's
# docroot-under-wp/ one), so without this second copy PHP looks for a
# directory that does not exist and the plugin fatals on activation.
#
# Copying ~900K twice is the cheapest correct fix: a symlink would satisfy
# the autoloader but makes WordPress's plugin_dir_url()/plugin_basename()
# resolution depend on symlink handling, and moving vendor/ instead would
# mean patching generated Composer files.
COPY plugins/course-discovery /usr/src/wordpress/plugins/course-discovery
COPY --from=vendor /app/vendor /usr/src/wordpress/vendor

# Docroot policy: AllowOverride All (WordPress's .htaccess is inert without
# it, so every pretty permalink 404s), no directory listings, and no HTTP
# access to the vendor/ and plugins/ trees that the layout above puts inside
# the docroot. See the file itself for the details.
COPY .docker/apache-course-discovery.conf /etc/apache2/conf-available/course-discovery.conf
RUN a2enconf course-discovery

COPY .docker/ /docker-init/
# bin/seed.sh is the single source of fixture truth, shared with the DDEV
# workflow -- see its CD_SEED_WP_NATIVE branch. It is copied rather than
# duplicated precisely so the two cannot drift.
COPY bin/seed.sh /docker-init/seed.sh

RUN chmod +x /docker-init/*.sh

ENTRYPOINT ["/docker-init/entrypoint.sh"]
