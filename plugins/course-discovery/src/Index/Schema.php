<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use wpdb;

/**
 * Names of the plugin's own lookup tables.
 *
 * "Lookup table" is the WordPress term (cf. WooCommerce's
 * wc_product_meta_lookup / wc_product_attributes_lookup) for a denormalised
 * projection kept in sync with the canonical wp_posts/wp_postmeta/term data,
 * so reads avoid the meta tables entirely. Deliberately not "index", which
 * collides with the SQL meaning of the word.
 *
 * Always derived from $wpdb->prefix so the plugin works on a site with a
 * non-default table prefix — a common hardening measure.
 */
final readonly class Schema
{
    /** One row per course: the scalar facts that are sorted and searched on. */
    private const META_LOOKUP_SUFFIX = 'cd_course_meta_lookup';

    /**
     * Many rows per course: the multi-valued facts that are filtered on
     * (provider, location, category, start date, plus anything a third-party
     * plugin registers). One row per course/attribute/value triple.
     */
    private const ATTRIBUTE_LOOKUP_SUFFIX = 'cd_course_attribute_lookup';

    public function __construct(private wpdb $db)
    {
    }

    public function metaLookupTable(): string
    {
        return $this->db->prefix . self::META_LOOKUP_SUFFIX;
    }

    public function attributeLookupTable(): string
    {
        return $this->db->prefix . self::ATTRIBUTE_LOOKUP_SUFFIX;
    }

    public function charsetCollate(): string
    {
        return $this->db->get_charset_collate();
    }
}
