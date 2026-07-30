#!/usr/bin/env bash
# Builds installable plugin zips into dist/.
#
# Each zip has the plugin directory at its root, which is the shape
# `wp plugin install <file>` and wp-admin's "Upload Plugin" both require --
# WordPress unpacks the archive straight into wp-content/plugins/, so a zip
# whose root is anything else installs to the wrong path.
#
# Contents come from `git ls-files` with working-tree content: tracked files
# only, so a stray vendor/, .env or editor backup in the working directory can
# never end up in a release, while uncommitted changes still package (which is
# what makes this usable for verifying a fix before committing it).
#
# The tests/ directory is excluded. Nothing else is: neither plugin has a build
# step or a runtime Composer dependency, so what is tracked is what ships.
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGINS="course-discovery course-discovery-example-extension"
DIST="dist"

rm -rf "$DIST"
mkdir -p "$DIST"

for plugin in $PLUGINS; do
    src="plugins/$plugin"
    entry="$src/$plugin.php"

    if [ ! -f "$entry" ]; then
        echo "package.sh: no entry file at $entry" >&2
        exit 1
    fi

    # From the plugin header WordPress itself reads, so the filename can never
    # disagree with the version wp-admin displays.
    version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$entry" | head -1)

    if [ -z "$version" ]; then
        echo "package.sh: no 'Version:' header in $entry" >&2
        exit 1
    fi

    stage="$DIST/.stage/$plugin"
    mkdir -p "$stage"

    git ls-files "$src" | while IFS= read -r file; do
        rel="${file#"$src"/}"

        case "$rel" in
            tests/*) continue ;;
            # Repository housekeeping, not plugin code: a .gitkeep placeholder
            # from before src/ had content, and anything similar added later.
            .*|*/.*) continue ;;
        esac

        mkdir -p "$stage/$(dirname "$rel")"
        cp "$file" "$stage/$rel"
    done

    zip_path="$PWD/$DIST/$plugin-$version.zip"
    (cd "$DIST/.stage" && zip -qr "$zip_path" "$plugin")

    # A zip carrying a vendor/ directory would mean a runtime Composer
    # dependency crept in without the autoloader question being revisited --
    # see the autoloader docblock in course-discovery.php. Fail the build
    # rather than ship an archive whose class loading nobody has thought about.
    if unzip -l "$zip_path" | grep -q "$plugin/vendor/"; then
        echo "package.sh: $zip_path contains vendor/ -- see course-discovery.php's autoloader note" >&2
        exit 1
    fi

    printf '%s (%s files)\n' "$DIST/$plugin-$version.zip" "$(unzip -l "$zip_path" | tail -1 | awk '{print $2}')"
done

rm -rf "$DIST/.stage"
