#!/usr/bin/env bash
# Seeds demo content (DDEV). Idempotent: DELETES EVERY existing cd_course,
# cd_instructor and cd_provider post -- including any created by hand in
# wp-admin, not only previously seeded ones -- then recreates the fixture
# set defined below.
set -euo pipefail

wp() { ddev wp "$@"; }

echo "WARNING: this will delete ALL courses, instructors and providers (cd_course, cd_instructor, cd_provider posts), including any authored by hand in wp-admin."
echo "Removing previously seeded content..."
for type in cd_course cd_instructor cd_provider; do
    ids=$(wp post list --post_type="$type" --format=ids)
    if [ -n "$ids" ]; then wp post delete $ids --force; fi
done

echo "Creating locations..."
wp term create cd_location "India" --slug=india --porcelain > /dev/null || true
wp term create cd_location "China" --slug=china --porcelain > /dev/null || true

echo "Creating categories..."
# Term creation (unlike the posts above) is not wiped each run, so re-running
# would fail on the second invocation with "term already exists" under set -e.
# Look up an existing term's ID rather than assuming creation always
# succeeds, so the script tolerates being run more than once. graphic-design
# is looked up the same way as design (rather than swallowed with `|| true`)
# so that a pre-existing, differently-parented term is corrected instead of
# silently left mis-parented with a passing exit status.
design=$(wp term list cd_course_category --slug=design --field=term_id)
if [ -z "$design" ]; then
    design=$(wp term create cd_course_category "Design" --slug=design --porcelain)
fi

graphic_design=$(wp term list cd_course_category --slug=graphic-design --field=term_id)
if [ -z "$graphic_design" ]; then
    graphic_design=$(wp term create cd_course_category "Graphic Design" --slug=graphic-design --parent="$design" --porcelain)
else
    wp term update cd_course_category "$graphic_design" --parent="$design" > /dev/null
fi

echo "Creating providers..."
uosd=$(wp post create --post_type=cd_provider --post_title="University of Sunderland" --post_status=publish --porcelain)
dmu=$(wp post create --post_type=cd_provider --post_title="De Montfort University" --post_status=publish --porcelain)
wp post term set "$uosd" cd_location india
wp post term set "$dmu" cd_location china

echo "Creating instructors..."
ada=$(wp post create --post_type=cd_instructor --post_title="Ada Lovelace" --post_status=publish --porcelain)
alan=$(wp post create --post_type=cd_instructor --post_title="Alan Turing" --post_status=publish --porcelain)

echo "Creating courses..."
c1=$(wp post create --post_type=cd_course --post_title="Graphic Design Foundation" \
    --post_excerpt="Learn the fundamentals of visual communication." \
    --post_content="A full introduction to typography, colour and layout." \
    --post_status=publish --porcelain)
c2=$(wp post create --post_type=cd_course --post_title="Data Science Essentials" \
    --post_excerpt="Statistics and machine learning from scratch." \
    --post_content="Covers regression, classification and model evaluation." \
    --post_status=publish --porcelain)

wp post term set "$c1" cd_course_category graphic-design
wp post term set "$c2" cd_course_category design

# ACF's relationship field (cd_course_providers, cd_course_instructors) runs
# array_map('strval', ...) in its update_value, so a course saved through
# wp-admin stores these as arrays of numeric STRINGS, not integers. ACF also
# always writes a companion "_<field>" reference row pointing at the field's
# key (see AcfFields::register()) so that get_field()'s strict-mode lookup
# can find the field definition; without it get_field() returns false and
# the raw meta is never formatted. Both properties are reproduced here so
# seeded data is shaped identically to admin-authored data -- otherwise an
# indexer built against the fixtures would work by coincidence on seeded
# data and silently drop every admin edit.
wp post meta update "$c1" cd_course_providers "[\"$uosd\",\"$dmu\"]" --format=json
wp post meta update "$c2" cd_course_providers "[\"$dmu\"]" --format=json
wp post meta update "$c1" cd_course_instructors "[\"$ada\"]" --format=json
wp post meta update "$c2" cd_course_instructors "[\"$alan\"]" --format=json
wp post meta update "$c1" cd_course_price 950
wp post meta update "$c2" cd_course_price 1200

wp post meta update "$c1" _cd_course_providers field_cd_course_providers
wp post meta update "$c2" _cd_course_providers field_cd_course_providers
wp post meta update "$c1" _cd_course_instructors field_cd_course_instructors
wp post meta update "$c2" _cd_course_instructors field_cd_course_instructors
wp post meta update "$c1" _cd_course_price field_cd_course_price
wp post meta update "$c2" _cd_course_price field_cd_course_price

# _cd_course_start_dates is our own hand-rolled meta box (StartDatesMetaBox),
# not an ACF field, so it correctly stores plain integers with no ACF
# reference row -- do not give it the string/reference treatment above.
wp post meta update "$c1" _cd_course_start_dates '[202601,202603]' --format=json
wp post meta update "$c2" _cd_course_start_dates '[202609]' --format=json

echo "Seed complete."

echo "Verifying fixtures..."

# The location filter matches a course if ANY of its providers is in that
# location, and selecting a parent category must return courses filed under
# a child category -- both are exercised by the first course's fixture data,
# so a silent regression here would be expensive later. Read the properties
# back from what was actually persisted, rather than trusting the commands
# above ran as intended.
provider_ids=$(wp post meta get "$c1" cd_course_providers --format=json | tr -d '[]"' | tr ',' ' ')
provider_count=$(wc -w <<< "$provider_ids")

if [ "$provider_count" -ne 2 ]; then
    echo "VERIFICATION FAILED: expected course $c1 to have exactly 2 providers, found $provider_count (${provider_ids})" >&2
    exit 1
fi

locations=""
for pid in $provider_ids; do
    loc=$(wp post term list "$pid" cd_location --field=slug)
    locations="$locations $loc"
done

unique_locations=$(tr ' ' '\n' <<< "$locations" | sed '/^$/d' | sort -u | wc -l)

if [ "$unique_locations" -lt 2 ]; then
    echo "VERIFICATION FAILED: expected course $c1's providers to be in more than one location, found:${locations}" >&2
    exit 1
fi

c1_category_parent=$(wp post term list "$c1" cd_course_category --field=parent)

if [ -z "$c1_category_parent" ] || [ "$c1_category_parent" = "0" ]; then
    echo "VERIFICATION FAILED: expected course $c1's category to have a parent category, got parent=${c1_category_parent}" >&2
    exit 1
fi

echo "Fixtures verified: course $c1 has $provider_count providers across $unique_locations locations; its category's parent term_id is $c1_category_parent."
