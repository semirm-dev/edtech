#!/usr/bin/env bash
# Seeds demo content (DDEV). Idempotent: DELETES EVERY existing cd_course,
# cd_instructor and cd_provider post -- including any created by hand in
# wp-admin, not only previously seeded ones -- then recreates the fixture
# set defined below.
set -euo pipefail

# This script runs in two places: on the host against DDEV, and inside the
# deployment image (.docker/init.sh), where there is no `ddev` binary and
# WP-CLI is on PATH directly. CD_SEED_WP_NATIVE=1 selects the latter.
#
# One file with two wrappers, rather than a second hand-synced copy for the
# container: an earlier revision of this project did keep a duplicate, and
# the two drifted -- the copy was still creating two locations and one
# category tree after this one had grown to three and four. Fixtures that
# differ between environments are worse than no fixtures, because the
# difference only shows up as a test that passes in one place.
if [ -n "${CD_SEED_WP_NATIVE:-}" ]; then
    wp() { command wp --allow-root "$@"; }
else
    wp() { ddev wp "$@"; }
fi

echo "WARNING: this will delete ALL courses, instructors and providers (cd_course, cd_instructor, cd_provider posts), including any authored by hand in wp-admin."
echo "Removing previously seeded content..."
for type in cd_course cd_instructor cd_provider; do
    ids=$(wp post list --post_type="$type" --format=ids)
    if [ -n "$ids" ]; then wp post delete $ids --force; fi
done

# Term creation (unlike the posts above) is not wiped each run, so re-running
# would fail on the second invocation with "term already exists" under set -e.
# Look up an existing term's ID rather than assuming creation always
# succeeds, so the script tolerates being run more than once. Child terms are
# looked up the same way (rather than swallowed with `|| true`) so that a
# pre-existing, differently-parented term is corrected instead of silently
# left mis-parented with a passing exit status.
term() {
    local taxonomy="$1" name="$2" slug="$3" parent="${4:-0}" id
    id=$(wp term list "$taxonomy" --slug="$slug" --field=term_id)

    if [ -z "$id" ]; then
        id=$(wp term create "$taxonomy" "$name" --slug="$slug" --parent="$parent" --porcelain)
    else
        wp term update "$taxonomy" "$id" --parent="$parent" > /dev/null
    fi

    printf '%s' "$id"
}

echo "Creating locations..."
term cd_location "India" india > /dev/null
term cd_location "China" china > /dev/null
term cd_location "United Kingdom" united-kingdom > /dev/null

echo "Creating categories..."
design=$(term cd_course_category "Design" design)
term cd_course_category "Graphic Design" graphic-design "$design" > /dev/null

technology=$(term cd_course_category "Technology" technology)
term cd_course_category "Data Science" data-science "$technology" > /dev/null
term cd_course_category "Web Development" web-development "$technology" > /dev/null

business=$(term cd_course_category "Business" business)
term cd_course_category "Marketing" marketing "$business" > /dev/null

echo "Creating providers..."
provider() {
    local title="$1" location="$2" id
    id=$(wp post create --post_type=cd_provider --post_title="$title" --post_status=publish --porcelain)
    wp post term set "$id" cd_location "$location" > /dev/null
    printf '%s' "$id"
}

# One variable per key, read back below through indirect expansion, rather
# than an associative array (`declare -A PROVIDER=([sunderland]=...)`).
#
# Associative arrays need bash 4, and macOS still ships bash 3.2.57 as
# /bin/bash -- which `#!/usr/bin/env bash` picks up. There, PROVIDER is a
# plain indexed array, so the subscript is evaluated as ARITHMETIC: the
# literal `[sunderland]` reads a variable *named* sunderland, which does not
# exist, and `set -u` aborts the whole seed with
#
#     ./bin/seed.sh: line 75: sunderland: unbound variable
#
# Nothing else in this script needs bash 4, so keep it running on the bash a
# reviewer already has instead of requiring `brew install bash`.
PROVIDER_sunderland=$(provider "University of Sunderland" india)
PROVIDER_dmu=$(provider "De Montfort University" china)
PROVIDER_leeds=$(provider "University of Leeds" india)
PROVIDER_coventry=$(provider "Coventry University" united-kingdom)

echo "Creating instructors..."
instructor() {
    wp post create --post_type=cd_instructor --post_title="$1" --post_status=publish --porcelain
}

INSTRUCTOR_ada=$(instructor "Ada Lovelace")
INSTRUCTOR_alan=$(instructor "Alan Turing")
INSTRUCTOR_grace=$(instructor "Grace Hopper")
INSTRUCTOR_katherine=$(instructor "Katherine Johnson")

# Maps the readable keys used in the course table below to the post IDs
# created above: "sunderland dmu" -> "41 42".
ids_from() {
    local map="$1" key ref out=""

    for key in $2; do
        ref="${map}_${key}"

        # Indirect expansion, which behaves identically on bash 3.2 and 5.x.
        # An unknown key is simply an unset variable, so set -u would catch it
        # on the next line anyway -- but as "!ref: unbound variable", naming
        # neither the key nor the table row it came from. This says which.
        # Fatal only because create_course() assigns the result; see there.
        if [ -z "${!ref:-}" ]; then
            echo "seed.sh: unknown ${map} key '${key}' in the course table" >&2
            exit 1
        fi

        out="${out:+$out }${!ref}"
    done

    printf '%s' "$out"
}

# ACF's relationship field (cd_course_providers, cd_course_instructors) runs
# array_map('strval', ...) in its update_value, so a course saved through
# wp-admin stores these as arrays of numeric STRINGS, not integers.
json_string_ids() {
    local out="" id

    for id in $1; do
        out="${out:+$out,}\"$id\""
    done

    printf '[%s]' "$out"
}

create_course() {
    local title="$1" category="$2" providers="$3" instructors="$4" price="$5" starts="$6" excerpt="$7" content="$8" id
    local provider_ids instructor_ids

    id=$(wp post create --post_type=cd_course --post_title="$title" \
        --post_excerpt="$excerpt" --post_content="$content" \
        --post_status=publish --porcelain)

    wp post term set "$id" cd_course_category "$category" > /dev/null

    # ACF always writes a companion "_<field>" reference row pointing at the
    # field's key (see AcfFields::register()) so that get_field()'s strict-mode
    # lookup can find the field definition; without it get_field() returns
    # false and the raw meta is never formatted. Both that and the string-ID
    # shape above are reproduced here so seeded data is shaped identically to
    # admin-authored data -- otherwise an indexer built against the fixtures
    # would work by coincidence on seeded data and silently drop every admin
    # edit.
    # Resolved into variables first, deliberately. Nested inside the `wp`
    # arguments as "$(json_string_ids "$(ids_from PROVIDER ...)")", a failing
    # ids_from -- an unknown key, caught by set -u -- kills only its own
    # subshell: the enclosing `wp post meta update` still runs, storing an
    # empty [] and leaving a course silently unlinked from its provider. As
    # plain assignments the failure propagates and set -e stops the seed.
    # Separate `local` declaration above for the same reason: `local x=$(...)`
    # reports local's own exit status, masking the substitution's.
    provider_ids=$(ids_from PROVIDER "$providers")
    instructor_ids=$(ids_from INSTRUCTOR "$instructors")

    wp post meta update "$id" cd_course_providers "$(json_string_ids "$provider_ids")" --format=json > /dev/null
    wp post meta update "$id" cd_course_instructors "$(json_string_ids "$instructor_ids")" --format=json > /dev/null
    wp post meta update "$id" cd_course_price "$price" > /dev/null

    wp post meta update "$id" _cd_course_providers field_cd_course_providers > /dev/null
    wp post meta update "$id" _cd_course_instructors field_cd_course_instructors > /dev/null
    wp post meta update "$id" _cd_course_price field_cd_course_price > /dev/null

    # _cd_course_start_dates is our own hand-rolled meta box (StartDatesMetaBox),
    # not an ACF field, so it correctly stores plain integers with no ACF
    # reference row -- do not give it the string/reference treatment above.
    # Courses with an empty start-date column get no meta row at all, which is
    # what "dates not announced yet" looks like in wp-admin, and exercises the
    # NULLs-last branch of the default "soonest" ordering.
    if [ -n "$starts" ]; then
        wp post meta update "$id" _cd_course_start_dates "[$starts]" --format=json > /dev/null
    fi

    printf '%s' "$id"
}

# title|category|providers|instructors|price|start dates|excerpt|content
#
# 20 courses against a 12-per-page default (Pagination::DEFAULT_PER_PAGE), so
# the results list always paginates into two pages.
#
# Two fixtures the E2E suite and the verification block below depend on --
# change them only together with e2e/tests/no-js.spec.ts:
#  * "Graphic Design Foundation" is the only course on BOTH providers, and the
#    only one on a CHILD category, so it proves the location traversal and the
#    parent-category rollup.
#  * "University of Sunderland" is deliberately NOT on "Data Science
#    Essentials", so filtering by it visibly narrows the result set.
# Both carry the earliest start dates, which under the default "soonest" order
# keeps them on page 1 no matter how many courses are added here.
echo "Creating courses..."
course_count=0

# The table is read on file descriptor 3, not stdin: `ddev wp` consumes stdin,
# so a plain `done <<'COURSES'` loses every row after the first.
while IFS='|' read -r title category providers instructors price starts excerpt content <&3; do
    if [ -z "$title" ]; then
        continue
    fi

    create_course "$title" "$category" "$providers" "$instructors" "$price" "$starts" "$excerpt" "$content" > /dev/null
    course_count=$((course_count + 1))
done 3<<'COURSES'
Graphic Design Foundation|graphic-design|sunderland dmu|ada|950|202601,202603|Learn the fundamentals of visual communication.|A full introduction to typography, colour and layout.
Data Science Essentials|data-science|dmu|alan|1200|202602|Statistics and machine learning from scratch.|Covers regression, classification and model evaluation.
Typography and Layout|graphic-design|sunderland|ada|780|202603|Type anatomy, hierarchy and grid systems.|Kerning, leading, baseline grids and multi-column editorial layouts.
Brand Identity Design|graphic-design|coventry|grace|1100|202604|Build a brand from logo to style guide.|Logo construction, colour systems, tone of voice and brand guidelines.
Motion Graphics Basics|graphic-design|leeds|ada|890|202605|Animate type and shapes for screen.|Keyframes, easing, storyboarding and exporting for web and social.
User Interface Design|design|sunderland coventry|grace|1350|202603,202609|Design usable, accessible product interfaces.|Component libraries, design tokens, prototyping and accessibility review.
Design Thinking Workshop|design|dmu|katherine|620|202606|A short, practical problem-framing course.|Empathy mapping, ideation, rapid prototyping and structured user testing.
Machine Learning Foundations|data-science|dmu leeds|alan|1850|202604|Supervised and unsupervised learning in depth.|Feature engineering, cross-validation, ensembles and neural network basics.
Statistics for Analysts|data-science|leeds|katherine|740|202607|The statistics behind everyday reporting.|Distributions, hypothesis testing, confidence intervals and sampling bias.
Data Visualisation with Python|data-science|coventry|alan|990|202605,202611|Turn datasets into charts people trust.|Matplotlib and Plotly, chart selection, annotation and colour accessibility.
Full-Stack Web Development|web-development|sunderland|grace|1600|202603,202609|Ship a database-backed web application.|HTTP, relational modelling, REST APIs, authentication and deployment.
Responsive Front-End Engineering|web-development|coventry|grace|1250|202606|Interfaces that work on every screen.|Modern CSS layout, progressive enhancement, performance budgets and testing.
Introduction to Cloud Platforms|technology|leeds|alan|1450|202608|Deploy and operate services in the cloud.|Compute, storage, networking, managed databases and infrastructure as code.
Cybersecurity Fundamentals|technology|dmu coventry|katherine|1700|202607|Defend applications against common attacks.|Threat modelling, injection, access control, secrets handling and logging.
Digital Marketing Strategy|marketing|sunderland|katherine|850|202609|Plan campaigns that reach the right people.|Audience segmentation, channel mix, budgeting and campaign measurement.
Content and SEO Writing|marketing|leeds|ada|560|202610|Write content search engines surface.|Keyword research, information architecture, on-page SEO and editorial calendars.
Social Media Analytics|marketing|coventry dmu|alan|720|202611|Measure what social activity is worth.|Engagement metrics, attribution models, dashboards and reporting cadence.
Project Management Essentials|business|leeds sunderland|grace|1050|202612|Deliver projects on time and in scope.|Scoping, estimation, risk registers, agile ceremonies and stakeholder reporting.
Entrepreneurship and Startups|business|coventry|katherine|1400||Take an idea from napkin to first customer.|Opportunity validation, lean canvas, pricing, funding options and pitching.
Business Analytics with SQL|business|dmu|alan|1150||Answer business questions with SQL.|Joins, aggregation, window functions, cohort analysis and reporting tables.
COURSES

echo "Created $course_count courses."

# `wp post meta update` fires none of IndexInvalidator's hooks (it listens on
# wp_after_insert_post / set_object_terms, both of which have already run by
# the time the meta above is written), so the lookup tables would otherwise
# hold rows with no price and no start date. Rebuild once at the end rather
# than reordering the writes -- the projection is derived, so rebuilding it is
# always the honest fix.
echo "Rebuilding the search index..."
wp course-discovery reindex

echo "Seed complete."

echo "Verifying fixtures..."

# Read the properties back from what was actually persisted, rather than
# trusting the commands above ran as intended.
c1=$(wp post list --post_type=cd_course --name=graphic-design-foundation --field=ID)

if [ -z "$c1" ]; then
    echo "VERIFICATION FAILED: no published course with the slug graphic-design-foundation" >&2
    exit 1
fi

# The location filter matches a course if ANY of its providers is in that
# location, and selecting a parent category must return courses filed under
# a child category -- both are exercised by this course's fixture data, so a
# silent regression here would be expensive later.
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

# Pagination is only visible when the unfiltered result set exceeds one page,
# so assert the seed actually clears DEFAULT_PER_PAGE rather than assuming it.
per_page=12
published_courses=$(wp post list --post_type=cd_course --post_status=publish --format=count)

if [ "$published_courses" -le "$per_page" ]; then
    echo "VERIFICATION FAILED: $published_courses published courses does not exceed the $per_page-per-page default, so the results list will never paginate" >&2
    exit 1
fi

echo "Fixtures verified: course $c1 has $provider_count providers across $unique_locations locations; its category's parent term_id is $c1_category_parent."
echo "$published_courses published courses at $per_page per page: $(( (published_courses + per_page - 1) / per_page )) pages."
