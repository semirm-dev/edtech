<?php

declare(strict_types=1);

namespace CourseDiscovery\Index;

use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\ContentModel\Taxonomies;

/**
 * Decides when the index has gone stale.
 *
 * The subtle case is the provider: when its location term changes, every
 * course attached to it holds a stale location row despite never being
 * edited itself — without the set_object_terms hook below the site
 * silently returns wrong results. Every trigger here reindexes only through
 * CourseIndexer's public API: this class decides WHEN, never HOW.
 */
final class IndexInvalidator
{
    /**
     * Above this many affected courses, a provider-driven reindex is
     * deferred to WP-Cron instead of running synchronously (see
     * reindexCoursesForProvider()).
     */
    private const SYNC_REINDEX_LIMIT = 200;

    private const REINDEX_PROVIDER_CRON_HOOK = 'course_discovery/reindex_provider_courses';

    /**
     * Provider post ids captured in onBeforePostDeleted(), consumed in
     * onPostDeleted(): by the time `deleted_post` fires the row is gone
     * from wp_posts, so get_post_type() can no longer identify it, hence
     * the earlier `before_delete_post` check.
     *
     * @var array<int, true>
     */
    private array $pendingProviderDeletions = [];

    /**
     * Course post ids captured in onBeforePostDeleted(), consumed in
     * onPostDeleted(); mirrors $pendingProviderDeletions above for the same
     * reason. Without it, onPostDeleted() would issue two DELETEs for every
     * deleted post site-wide instead of only actual courses.
     *
     * @var array<int, true>
     */
    private array $pendingCourseDeletions = [];

    public function __construct(private readonly CourseIndexer $indexer)
    {
    }

    public function register(): void
    {
        // wp_after_insert_post fires after `save_post`, where ACF and
        // StartDatesMetaBox write their data. `save_post_cd_course` fires
        // BEFORE that, so listening there would read stale values.
        add_action('wp_after_insert_post', [$this, 'onCourseSaved'], 10, 1);

        add_action('before_delete_post', [$this, 'onBeforePostDeleted'], 10, 2);
        add_action('deleted_post', [$this, 'onPostDeleted'], 10, 1);
        add_action('trashed_post', [$this, 'onPostTrashed'], 10, 1);

        add_action('set_object_terms', [$this, 'onTermsSet'], 10, 6);

        add_action('edited_' . Taxonomies::CATEGORY, [$this, 'onCourseCategoryEdited'], 10, 1);

        add_action('delete_' . Taxonomies::CATEGORY, [$this, 'onCourseCategoryDeleted'], 10, 4);
        add_action('delete_' . Taxonomies::LOCATION, [$this, 'onLocationDeleted'], 10, 4);

        add_action(self::REINDEX_PROVIDER_CRON_HOOK, [$this, 'onReindexProviderCron'], 10, 1);
    }

    public function onCourseSaved(int $postId): void
    {
        if (get_post_type($postId) !== PostTypes::COURSE) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $this->indexer->indexCourse($postId);
    }

    public function onBeforePostDeleted(int $postId, \WP_Post $post): void
    {
        if ($post->post_type === PostTypes::COURSE) {
            $this->pendingCourseDeletions[$postId] = true;

            return;
        }

        if ($post->post_type === PostTypes::PROVIDER) {
            $this->pendingProviderDeletions[$postId] = true;
        }
    }

    /**
     * Guarded on $pendingCourseDeletions (set in onBeforePostDeleted()
     * while the post's type could still be read) so the index tables are
     * only touched for a post that actually was a course, not every
     * deleted post site-wide.
     */
    public function onPostDeleted(int $postId): void
    {
        if (isset($this->pendingCourseDeletions[$postId])) {
            unset($this->pendingCourseDeletions[$postId]);
            $this->indexer->removeCourse($postId);
        }

        if (isset($this->pendingProviderDeletions[$postId])) {
            unset($this->pendingProviderDeletions[$postId]);
            $this->reindexCoursesForProvider($postId);
        }
    }

    public function onPostTrashed(int $postId): void
    {
        if (get_post_type($postId) === PostTypes::PROVIDER) {
            $this->reindexCoursesForProvider($postId);
        }
    }

    /**
     * Fires for every taxonomy assignment on any post type.
     *
     * @param array<int, int|string> $terms
     * @param array<int, int>        $ttIds
     * @param array<int, int>        $oldTtIds
     */
    public function onTermsSet(
        int $objectId,
        array $terms,
        array $ttIds,
        string $taxonomy,
        bool $append,
        array $oldTtIds
    ): void {
        // Cheap string compare first: this fires on every taxonomy write
        // site-wide, so irrelevant taxonomies must be rejected before any
        // DB/cache hit (get_post_type()) is spent on them.
        if ($taxonomy === Taxonomies::CATEGORY) {
            if (get_post_type($objectId) === PostTypes::COURSE) {
                $this->indexer->indexCourse($objectId);
            }

            return;
        }

        if ($taxonomy !== Taxonomies::LOCATION) {
            return;
        }

        // set_object_terms fires on every term write, including trash,
        // untrash and quick edit, with no dirty check of its own. Skip the
        // work entirely when the term set did not actually change.
        if ($this->sameTermTaxonomyIds($ttIds, $oldTtIds)) {
            return;
        }

        if (get_post_type($objectId) !== PostTypes::PROVIDER) {
            return;
        }

        $this->reindexCoursesForProvider($objectId);
    }

    /**
     * A category's ancestor chain changed for it and every descendant, so a
     * course filed under the term itself OR any descendant must be
     * reindexed. wp_update_term() has no post-level hook, only this
     * term-level one.
     */
    public function onCourseCategoryEdited(int $termId): void
    {
        $termIds = [$termId];

        $descendants = get_term_children($termId, Taxonomies::CATEGORY);

        if (! is_wp_error($descendants)) {
            foreach ($descendants as $descendantId) {
                $termIds[] = (int) $descendantId;
            }
        }

        $objectIds = get_objects_in_term($termIds, Taxonomies::CATEGORY);

        if (is_wp_error($objectIds)) {
            return;
        }

        foreach (array_unique(array_map('intval', $objectIds)) as $courseId) {
            $this->indexer->indexCourse($courseId);
        }
    }

    /**
     * $objectIds are the courses that carried the now-deleted category term,
     * supplied by core itself -- by this point wp_delete_term() has already
     * stripped the term relationships, so reindexing here picks up the
     * correct (now term-free) ancestor set.
     *
     * @param array<int, int|string> $objectIds
     */
    public function onCourseCategoryDeleted(int $term, int $ttId, \WP_Term $deletedTerm, array $objectIds): void
    {
        foreach (array_unique(array_map('intval', $objectIds)) as $courseId) {
            $this->indexer->indexCourse($courseId);
        }
    }

    /**
     * $objectIds here are PROVIDERS (the location taxonomy only attaches to
     * cd_provider) -- reindex the courses of each so the deleted location
     * stops appearing on them.
     *
     * @param array<int, int|string> $objectIds
     */
    public function onLocationDeleted(int $term, int $ttId, \WP_Term $deletedTerm, array $objectIds): void
    {
        foreach (array_unique(array_map('intval', $objectIds)) as $providerId) {
            $this->reindexCoursesForProvider($providerId);
        }
    }

    public function onReindexProviderCron(int $providerId): void
    {
        foreach ($this->indexer->coursesForProvider($providerId) as $courseId) {
            $this->indexer->indexCourse($courseId);
        }
    }

    /**
     * Reindexes every course attached to a provider, bounded against an
     * unbounded fan-out: coursesForProvider() is unpaginated and each
     * indexCourse() costs several queries, so a large provider could mean
     * tens of thousands of queries in one request — a guaranteed timeout.
     * Above SYNC_REINDEX_LIMIT the work is deferred to a single WP-Cron
     * event instead; 200 sits comfortably below that timeout risk while
     * staying above the size of an ordinary provider.
     */
    private function reindexCoursesForProvider(int $providerId): void
    {
        $courseIds = $this->indexer->coursesForProvider($providerId);

        if (count($courseIds) > self::SYNC_REINDEX_LIMIT) {
            if (! wp_next_scheduled(self::REINDEX_PROVIDER_CRON_HOOK, [$providerId])) {
                wp_schedule_single_event(time(), self::REINDEX_PROVIDER_CRON_HOOK, [$providerId]);
            }

            return;
        }

        foreach ($courseIds as $courseId) {
            $this->indexer->indexCourse($courseId);
        }
    }

    /**
     * Term-taxonomy id sets are equivalent regardless of order.
     *
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private function sameTermTaxonomyIds(array $a, array $b): bool
    {
        $normalize = static function (array $ids): array {
            $ints = array_map('intval', $ids);
            sort($ints);

            return $ints;
        };

        return $normalize($a) === $normalize($b);
    }
}
