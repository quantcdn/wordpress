<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ContentApiInterface;
use Quant\Redirects\RedirectProvider;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Redirect;
use Quant\Sync\Seeder;
use Quant\Sync\SitemapDiscovery;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Sync\ThemeAssetSync;
use Quant\Tests\Support\WordPressStubs;
use WP_Post_Type;
use WP_Taxonomy;

#[CoversClass(Seeder::class)]
final class SeederTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    /** @var array<SyncJob> */
    private array $pushed = [];
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private SitemapDiscovery&Mockery\MockInterface $sitemaps;
    private RedirectProvider&Mockery\MockInterface $redirects;
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return true; }
        };
        $GLOBALS['wpdb'] = Mockery::mock(\wpdb::class);
        $GLOBALS['wpdb']->posts = 'wp_posts';
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([
            ['year' => '2026', 'month' => '1', 'count' => '12'],
            ['year' => '2025', 'month' => '12', 'count' => '3'],
        ])->byDefault();

        Functions\when('get_option')->alias(static fn(string $k, $d = false) => match ($k) {
            'posts_per_page' => 10,
            'show_on_front' => 'posts',
            default => $d,
        });
        Functions\when('get_posts')->alias(static fn(array $args) => match ($args['post_type']) {
            'post' => [1, 2],
            'page' => [3],
            'book' => [4],
            'attachment' => [5, 6],
            default => is_array($args['post_type']) ? [1, 2, 3] : [],
        });
        Functions\when('post_type_exists')->alias(static fn(string $t): bool => in_array($t, ['post', 'page', 'book'], true));
        Functions\when('get_post_types')->alias(static function (array $args, string $output = 'names'): array {
            if (isset($args['_builtin']) && $args['_builtin'] === false) {
                return ['book' => new WP_Post_Type('book', true, true, 'books'), 'private' => new WP_Post_Type('private', true, false)];
            }
            if (!empty($args['has_archive'])) {
                return ['book' => 'book'];
            }
            return ['post' => 'post', 'page' => 'page', 'book' => 'book'];
        });
        Functions\when('get_taxonomies')->justReturn(['category' => new WP_Taxonomy('category'), 'nav_menu' => new WP_Taxonomy('nav_menu', true, false)]);
        Functions\when('get_terms')->justReturn([$this->makeTerm(5, 'category', 'News', 25), $this->makeTerm(6, 'category', 'Tech', 2)]);
        Functions\when('wp_count_posts')->alias(static fn(string $t = 'post'): object => (object) ['publish' => $t === 'post' ? 23 : 4]);
        Functions\when('get_post_type_archive_link')->alias(static fn(string $t) => "https://example.com/{$t}s/");
        Functions\when('get_month_link')->alias(static fn(int $y, int $m): string => sprintf('https://example.com/%d/%02d/', $y, $m));
        Functions\when('get_year_link')->alias(static fn(int $y): string => "https://example.com/{$y}/");
        Functions\when('get_users')->justReturn([(object) ['ID' => 1]]);
        Functions\when('get_author_posts_url')->alias(static fn(int $id): string => "https://example.com/author/u{$id}/");
        Functions\when('count_user_posts')->justReturn(15);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/');
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.com/wp-content/themes/t');
        Functions\when('get_template_directory_uri')->justReturn('https://example.com/wp-content/themes/t');

        $this->themeDir = sys_get_temp_dir() . '/quant-seed-theme-' . uniqid();
        mkdir($this->themeDir);
        file_put_contents($this->themeDir . '/style.css', 'x');
        Functions\when('get_stylesheet_directory')->justReturn($this->themeDir);
        Functions\when('get_template_directory')->justReturn($this->themeDir);

        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->queue->shouldReceive('pushBatch')->andReturnUsing(function (array $jobs): void {
            $this->pushed = array_merge($this->pushed, $jobs);
        })->byDefault();
        $this->sitemaps = Mockery::mock(SitemapDiscovery::class);
        $this->sitemaps->shouldReceive('routes')->andReturn(['/wp-sitemap.xml', '/wp-sitemap-posts-post-1.xml'])->byDefault();
        $this->redirects = Mockery::mock(RedirectProvider::class);
        $this->redirects->shouldReceive('all')->andReturn([new Redirect('/old', '/new', 301)])->byDefault();
    }

    protected function tearDown(): void
    {
        unlink($this->themeDir . '/style.css');
        rmdir($this->themeDir);
        unset($GLOBALS['wp_rewrite'], $GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seeder(array $static = [], bool $searchEnabled = false): Seeder
    {
        $search = Mockery::mock(SearchRecordBuilder::class);
        $search->shouldReceive('enabled')->andReturn($searchEnabled);
        $search->shouldReceive('indexedPostTypes')->andReturn(['post', 'page']);
        $api = Mockery::mock(ContentApiInterface::class);

        return new Seeder($this->queue, $this->mockSettings(static: $static), new ThemeAssetSync($api), $this->sitemaps, $this->redirects, $search);
    }

    /**
     * @return array<string>
     */
    private function describe(array $jobs): array
    {
        return array_map(static fn(SyncJob $j): string => $j->type->value . ' ' . $j->objectId . ($j->meta ? ' ' . json_encode($j->meta) : ''), $jobs);
    }

    #[Test]
    public function it_lists_every_type_for_a_full_push_according_to_settings(): void
    {
        $this->assertSame(
            ['post', 'page', 'book', 'media', 'taxonomy', 'home', 'archives', 'authors', 'theme_assets', 'custom_routes', 'sitemaps', 'redirects'],
            $this->seeder()->allTypes()
        );
        $this->assertSame(
            ['post', 'page', 'book', 'media', 'taxonomy', 'home', 'archives', 'authors', 'custom_routes', 'redirects', 'search'],
            $this->seeder(['theme_assets' => false, 'sitemap' => false], true)->allTypes()
        );
    }

    #[Test]
    public function it_builds_post_page_cpt_and_media_jobs(): void
    {
        $seeder = $this->seeder();

        $this->assertSame(['post 1', 'post 2'], $this->describe($seeder->jobsFor('post')));
        $this->assertSame(['page 3'], $this->describe($seeder->jobsFor('page')));
        $this->assertSame(['custom_post_type 4 {"post_type":"book"}'], $this->describe($seeder->jobsFor('book')));
        $this->assertSame(['media 5', 'media 6'], $this->describe($seeder->jobsFor('media')));
        $this->assertSame([], $seeder->jobsFor('nonexistent'));
    }

    #[Test]
    public function it_paginates_term_listings_and_skips_hidden_taxonomies(): void
    {
        $jobs = $this->describe($this->seeder()->jobsFor('taxonomy'));

        $this->assertSame([
            'taxonomy 5 {"taxonomy":"category","page":1}',
            'taxonomy 5 {"taxonomy":"category","page":2}',
            'taxonomy 5 {"taxonomy":"category","page":3}',
            'taxonomy 6 {"taxonomy":"category","page":1}',
        ], $jobs);
    }

    #[Test]
    public function it_builds_the_home_page_with_its_pagination(): void
    {
        $this->assertSame([
            'home /',
            'route /page/2/ {"listing":"posts"}',
            'route /page/3/ {"listing":"posts"}',
        ], $this->describe($this->seeder()->jobsFor('home')));
    }

    #[Test]
    public function it_uses_the_posts_page_for_pagination_when_the_front_page_is_static(): void
    {
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => match ($k) {
            'posts_per_page' => 10,
            'show_on_front' => 'page',
            'page_for_posts' => 3,
            default => $d,
        });

        $jobs = $this->describe($this->seeder()->jobsFor('home'));

        $this->assertSame('home /', $jobs[0]);
        $this->assertSame('route /blog/page/2/ {"listing":"posts"}', $jobs[1]);
    }

    #[Test]
    public function it_builds_post_type_date_and_author_archives(): void
    {
        $archives = $this->describe($this->seeder()->jobsFor('archives'));

        $this->assertContains('archive /books {"post_type":"book","page":1}', $archives);
        $this->assertContains('archive /2026/01 {"date":true,"page":1}', $archives);
        $this->assertContains('archive /2026/01/page/2 {"date":true,"page":2}', $archives);
        $this->assertContains('archive /2025/12 {"date":true,"page":1}', $archives);
        $this->assertContains('archive /2026 {"date":true,"page":1}', $archives);
        $this->assertContains('archive /2025 {"date":true,"page":1}', $archives);

        $this->assertSame([
            'author /author/u1 {"user":1,"page":1}',
            'author /author/u1/page/2 {"user":1,"page":2}',
        ], $this->describe($this->seeder()->jobsFor('authors')));
    }

    #[Test]
    public function it_builds_theme_asset_custom_route_sitemap_and_redirect_jobs(): void
    {
        $file = ABSPATH . 'robots-' . uniqid() . '.txt';
        file_put_contents($file, 'User-agent: *');
        $route = '/' . basename($file);

        try {
            $seeder = $this->seeder(['custom_routes' => [$route, 'custom-page'], 'route_404' => '/not-found']);

            $this->assertSame(['theme_asset /wp-content/themes/t/style.css {"file":"' . addcslashes($this->themeDir, '/') . '\/style.css","url":"\/wp-content\/themes\/t\/style.css"}'], $this->describe($seeder->jobsFor('theme_assets')));
            $this->assertSame([
                "file {$route} " . json_encode(['file' => $file, 'url' => $route]),
                'custom_route /custom-page',
                'not_found /not-found {"publish_as":"\/_quant404"}',
            ], $this->describe($seeder->jobsFor('custom_routes')));
            $this->assertSame(['sitemap /wp-sitemap.xml', 'sitemap /wp-sitemap-posts-post-1.xml'], $this->describe($seeder->jobsFor('sitemaps')));
            $this->assertSame(['redirect /old {"to":"\/new","code":301}'], $this->describe($seeder->jobsFor('redirects')));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_builds_search_jobs_only_when_search_is_enabled(): void
    {
        $this->assertSame([], $this->seeder()->jobsFor('search'));
        $this->assertSame(['search_record 1', 'search_record 2', 'search_record 3'], $this->describe($this->seeder(searchEnabled: true)->jobsFor('search')));
    }

    #[Test]
    public function it_seeds_the_requested_types_and_reports_counts(): void
    {
        Actions\expectDone('quant_seeded')->once();

        $result = $this->seeder()->seed(['post', 'media', 'media']);

        $this->assertSame(['queued' => 4, 'types' => ['post' => 2, 'media' => 2]], $result);
        $this->assertCount(4, $this->pushed);
        $this->assertSame(SyncAction::Publish, $this->pushed[0]->action);
        $this->assertSame(SyncType::Post, $this->pushed[0]->type);
    }

    #[Test]
    public function it_expands_all_to_every_type(): void
    {
        $result = $this->seeder()->seed(['all']);

        $this->assertSame($this->seeder()->allTypes(), array_keys($result['types']));
        $this->assertGreaterThan(20, $result['queued']);
    }
}
