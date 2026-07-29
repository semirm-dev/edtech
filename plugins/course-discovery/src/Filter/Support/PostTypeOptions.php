<?php

declare(strict_types=1);

namespace CourseDiscovery\Filter\Support;

use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;

final readonly class PostTypeOptions
{
    public function __construct(private string $postType)
    {
    }

    public function build(): FilterOptions
    {
        $posts = get_posts([
            'post_type'      => $this->postType,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $options = [];

        // No 'fields' key is passed above, so get_posts() is statically
        // guaranteed (by its own conditional return type) to hand back
        // WP_Post instances here; an instanceof filter would be redundant
        // dead code PHPStan rejects as an always-true check, not a genuine
        // narrowing.
        foreach ($posts as $post) {
            $options[] = new FilterOption((string) $post->ID, $post->post_title);
        }

        return FilterOptions::fromArray($options);
    }
}
