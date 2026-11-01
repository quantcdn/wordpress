<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Core\Settings;
use Quant\Redirects\RedirectProvider;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Handler\RoutePublishHandler;

/**
 * Builds the queue for a full or partial site push.
 *
 * Content types: `post`, `page`, any public custom post type, `media`,
 * `taxonomy`, `home`, `archives`, `authors`, `theme_assets`, `custom_routes`,
 * `sitemaps`, `redirects`, `search`. `all` expands to every type that applies.
 */
class Seeder
{
    public const SPECIAL_TYPES = [
        'home', 'archives', 'authors', 'theme_assets', 'custom_routes', 'sitemaps', 'redirects', 'search',
    ];

    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings,
        private readonly ThemeAssetSync $themeAssets,
        private readonly SitemapDiscovery $sitemaps,
        private readonly RedirectProvider $redirects,
        private readonly SearchRecordBuilder $search
    ) {
    }

    /**
     * Queue jobs for the given types.
     *
     * @param array<string> $types
     * @return array{queued: int, types: array<string, int>}
     */
    public function seed(array $types): array
    {
        if (in_array('all', $types, true)) {
            $types = $this->allTypes();
        }

        $counts = [];
        foreach (array_unique($types) as $type) {
            $jobs = $this->jobsFor($type);
            $this->queue->pushBatch($jobs);
            $counts[$type] = count($jobs);
        }

        /** This action is documented in v2.0.0; it fires after content was queued for a seed. */
        do_action('quant_seeded', $counts);

        return ['queued' => array_sum($counts), 'types' => $counts];
    }

    /**
     * Every type a full push covers for this site.
     *
     * @return array<string>
     */
    public function allTypes(): array
    {
        $types = array_merge(['post', 'page'], $this->customPostTypes(), ['media', 'taxonomy', 'home', 'archives', 'authors']);

        $config = $this->settings->getStaticConfig();
        if (!empty($config['theme_assets'])) {
            $types[] = 'theme_assets';
        }
        $types[] = 'custom_routes';
        if (!empty($config['sitemap'])) {
            $types[] = 'sitemaps';
        }
        $types[] = 'redirects';
        if ($this->search->enabled()) {
            $types[] = 'search';
        }

        return $types;
    }

    /**
     * @return array<SyncJob>
     */
    public function jobsFor(string $type): array
    {
        return match ($type) {
            'post' => $this->postJobs('post', SyncType::Post),
            'page' => $this->postJobs('page', SyncType::Page),
            'media' => $this->mediaJobs(),
            'taxonomy' => $this->taxonomyJobs(),
            'home' => $this->homeJobs(),
            'archives' => $this->archiveJobs(),
            'authors' => $this->authorJobs(),
            'theme_assets' => $this->themeAssetJobs(),
            'custom_routes' => $this->customRouteJobs(),
            'sitemaps' => $this->sitemapJobs(),
            'redirects' => $this->redirectJobs(),
            'search' => $this->searchJobs(),
            default => post_type_exists($type) ? $this->postJobs($type, SyncType::CustomPostType) : [],
        };
    }

    /**
     * @return array<string>
     */
    public function customPostTypes(): array
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $names = [];
        foreach ($types as $type) {
            if ($type->publicly_queryable !== false) {
                $names[] = $type->name;
            }
        }

        return $names;
    }

    /**
     * @return array<SyncJob>
     */
    private function postJobs(string $postType, SyncType $syncType): array
    {
        $ids = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'has_password' => false,
        ]);

        $meta = $syncType === SyncType::CustomPostType ? ['post_type' => $postType] : [];

        return array_map(
            static fn(int $id): SyncJob => new SyncJob($syncType, (string) $id, SyncAction::Publish, $meta),
            array_map('intval', $ids)
        );
    }

    /**
     * @return array<SyncJob>
     */
    private function mediaJobs(): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return array_map(
            static fn(int $id): SyncJob => new SyncJob(SyncType::Media, (string) $id, SyncAction::Publish),
            array_map('intval', $ids)
        );
    }

    /**
     * Term pages with pagination.
     *
     * @return array<SyncJob>
     */
    private function taxonomyJobs(): array
    {
        $jobs = [];

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            if ($taxonomy->publicly_queryable === false) {
                continue;
            }

            $terms = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => true]);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $pages = Paths::pageCount((int) $term->count);
                for ($page = 1; $page <= $pages; $page++) {
                    $jobs[] = new SyncJob(SyncType::Taxonomy, (string) $term->term_id, SyncAction::Publish, [
                        'taxonomy' => $taxonomy->name,
                        'page' => $page,
                    ]);
                }
            }
        }

        return $jobs;
    }

    /**
     * The front page and, when it lists posts, its paginated pages.
     *
     * @return array<SyncJob>
     */
    private function homeJobs(): array
    {
        $jobs = [new SyncJob(SyncType::Home, '/', SyncAction::Publish)];

        $listing = $this->postsListingUrl();
        if ($listing === null) {
            return $jobs;
        }

        $total = (int) wp_count_posts('post')->publish;
        for ($page = 2, $pages = Paths::pageCount($total); $page <= $pages; $page++) {
            $path = Paths::fromUrl(Paths::paginated($listing, $page));
            $jobs[] = new SyncJob(SyncType::Route, $path, SyncAction::Publish, ['listing' => 'posts']);
        }

        return $jobs;
    }

    /**
     * URL of the blog listing (front page or the posts page), or null when there is none.
     */
    private function postsListingUrl(): ?string
    {
        if (get_option('show_on_front') !== 'page') {
            return home_url('/');
        }

        $postsPage = (int) get_option('page_for_posts');
        if ($postsPage <= 0) {
            return null;
        }

        $link = get_permalink($postsPage);

        return is_string($link) ? $link : null;
    }

    /**
     * Post type archives and date archives, with pagination.
     *
     * @return array<SyncJob>
     */
    private function archiveJobs(): array
    {
        $jobs = [];

        foreach (get_post_types(['public' => true, 'has_archive' => true], 'names') as $postType) {
            $link = get_post_type_archive_link($postType);
            if (!is_string($link)) {
                continue;
            }
            $total = (int) (wp_count_posts($postType)->publish ?? 0);
            $jobs = array_merge($jobs, $this->paginatedRouteJobs(SyncType::Archive, $link, $total, ['post_type' => $postType]));
        }

        foreach ($this->dateArchives() as $archive) {
            $link = $archive['month'] !== null
                ? get_month_link($archive['year'], $archive['month'])
                : get_year_link($archive['year']);
            $jobs = array_merge($jobs, $this->paginatedRouteJobs(SyncType::Archive, $link, $archive['count'], ['date' => true]));
        }

        return $jobs;
    }

    /**
     * @return array<int, array{year: int, month: ?int, count: int}>
     */
    private function dateArchives(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT YEAR(post_date) AS year, MONTH(post_date) AS month, COUNT(*) AS count
             FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
             GROUP BY YEAR(post_date), MONTH(post_date)
             ORDER BY year DESC, month DESC",
            ARRAY_A
        ) ?: [];

        $years = [];
        $archives = [];
        foreach ($rows as $row) {
            $year = (int) $row['year'];
            $years[$year] = ($years[$year] ?? 0) + (int) $row['count'];
            $archives[] = ['year' => $year, 'month' => (int) $row['month'], 'count' => (int) $row['count']];
        }
        foreach ($years as $year => $count) {
            $archives[] = ['year' => $year, 'month' => null, 'count' => $count];
        }

        return $archives;
    }

    /**
     * @return array<SyncJob>
     */
    private function authorJobs(): array
    {
        $jobs = [];

        foreach (get_users(['has_published_posts' => ['post'], 'fields' => ['ID']]) as $user) {
            $link = get_author_posts_url((int) $user->ID);
            $total = (int) count_user_posts((int) $user->ID, 'post', true);
            $jobs = array_merge($jobs, $this->paginatedRouteJobs(SyncType::Author, $link, $total, ['user' => (int) $user->ID]));
        }

        return $jobs;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<SyncJob>
     */
    private function paginatedRouteJobs(SyncType $type, string $link, int $total, array $meta): array
    {
        $jobs = [];
        for ($page = 1, $pages = Paths::pageCount($total); $page <= $pages; $page++) {
            $path = Paths::normalise(Paths::fromUrl(Paths::paginated($link, $page)));
            $jobs[] = new SyncJob($type, $path, SyncAction::Publish, $meta + ['page' => $page]);
        }

        return $jobs;
    }

    /**
     * @return array<SyncJob>
     */
    private function themeAssetJobs(): array
    {
        return array_map(
            static fn(array $asset): SyncJob => new SyncJob(SyncType::ThemeAsset, Paths::normalise($asset['url']), SyncAction::Publish, [
                'file' => $asset['file'],
                'url' => $asset['url'],
            ]),
            $this->themeAssets->scanActiveTheme()
        );
    }

    /**
     * Custom routes (content or files) and the 404 page.
     *
     * @return array<SyncJob>
     */
    private function customRouteJobs(): array
    {
        $config = $this->settings->getStaticConfig();
        $jobs = [];

        foreach ($config['custom_routes'] as $route) {
            $route = '/' . ltrim($route, '/');
            $file = Paths::localFile($route);
            $jobs[] = $file !== null
                ? new SyncJob(SyncType::File, Paths::normalise($route), SyncAction::Publish, ['file' => $file, 'url' => $route])
                : new SyncJob(SyncType::CustomRoute, $route, SyncAction::Publish);
        }

        $notFound = trim((string) $config['route_404']);
        if ($notFound !== '') {
            $jobs[] = new SyncJob(SyncType::NotFound, '/' . ltrim($notFound, '/'), SyncAction::Publish, [
                'publish_as' => RoutePublishHandler::NOT_FOUND_PATH,
            ]);
        }

        return $jobs;
    }

    /**
     * @return array<SyncJob>
     */
    private function sitemapJobs(): array
    {
        return array_map(
            static fn(string $route): SyncJob => new SyncJob(SyncType::Sitemap, $route, SyncAction::Publish),
            $this->sitemaps->routes()
        );
    }

    /**
     * @return array<SyncJob>
     */
    private function redirectJobs(): array
    {
        return array_map(
            static fn(Redirect $redirect): SyncJob => new SyncJob(SyncType::Redirect, $redirect->from, SyncAction::Publish, [
                'to' => $redirect->to,
                'code' => $redirect->statusCode,
            ]),
            $this->redirects->all()
        );
    }

    /**
     * @return array<SyncJob>
     */
    private function searchJobs(): array
    {
        if (!$this->search->enabled()) {
            return [];
        }

        $ids = get_posts([
            'post_type' => $this->search->indexedPostTypes(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return array_map(
            static fn(int $id): SyncJob => new SyncJob(SyncType::SearchRecord, (string) $id, SyncAction::Publish),
            array_map('intval', $ids)
        );
    }
}
