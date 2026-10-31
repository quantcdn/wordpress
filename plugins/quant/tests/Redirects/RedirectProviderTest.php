<?php

declare(strict_types=1);

namespace Quant\Tests\Redirects;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\Redirect;
use Quant\Tests\Support\WordPressStubs;
use Red_Item;

#[CoversClass(RedirectProvider::class)]
final class RedirectProviderTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private RedirectProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Red_Item::$items = [];
        $GLOBALS['wpdb'] = Mockery::mock(\wpdb::class);
        $GLOBALS['wpdb']->postmeta = 'wp_postmeta';
        $GLOBALS['wpdb']->posts = 'wp_posts';
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([])->byDefault();

        $this->provider = new RedirectProvider();
    }

    protected function tearDown(): void
    {
        Red_Item::$items = [];
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_converts_servable_redirection_items_only(): void
    {
        Red_Item::$items = [
            1 => new Red_Item(1, '/old-page', '/about', '301'),
            2 => new Red_Item(2, 'temp/', 'https://other.com/x', '302'),
            3 => new Red_Item(3, '/disabled', '/x', '301', enabled: false),
            4 => new Red_Item(4, '/regex.*', '/x', '301', regex: true),
            5 => new Red_Item(5, '/agent', '/x', '301', matchType: 'agent'),
            6 => new Red_Item(6, '/pass', '/x', '301', actionType: 'pass'),
            7 => new Red_Item(7, '/weird-code', '/x', '999'),
        ];

        $redirects = $this->provider->fromRedirectionPlugin();

        $this->assertSame(
            [['/old-page', '/about', 301], ['/temp', 'https://other.com/x', 302], ['/weird-code', '/x', 301]],
            array_map(static fn(Redirect $r): array => [$r->from, $r->to, $r->statusCode], $redirects)
        );
        $this->assertTrue($this->provider->redirectionPluginActive());
    }

    #[Test]
    public function it_builds_redirects_from_old_slugs(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_results')->once()->andReturn([
            ['post_id' => '1', 'old_slug' => 'hello-world'],
            ['post_id' => '1', 'old_slug' => 'hello-quant'],
            ['post_id' => '2', 'old_slug' => 'about-us'],
            ['post_id' => '3', 'old_slug' => ''],
        ]);
        Functions\when('get_post')->alias(fn(int $id) => match ($id) {
            1 => $this->makePost(1, 'post', 'publish', 'hello-quant'),
            2 => $this->makePost(2, 'page', 'publish', 'about'),
            default => null,
        });
        Functions\when('get_permalink')->alias(static fn(\WP_Post $p): string => $p->ID === 1 ? 'https://example.com/blog/hello-quant/' : 'https://example.com/about/');

        $redirects = $this->provider->fromOldSlugs();

        $this->assertSame(
            [['/blog/hello-world', '/blog/hello-quant', 301], ['/about-us', '/about', 301]],
            array_map(static fn(Redirect $r): array => [$r->from, $r->to, $r->statusCode], $redirects)
        );
    }

    #[Test]
    public function it_merges_all_sources_and_drops_invalid_or_duplicate_entries(): void
    {
        Red_Item::$items = [1 => new Red_Item(1, '/dup', '/from-plugin', '301')];
        Filters\expectApplied('quant_custom_redirects')->once()->andReturn([
            new Redirect('/dup', '/from-filter', 301),
            new Redirect('no-leading-slash', '/x'),
            new Redirect('/self', '/self/'),
            new Redirect('/empty', ''),
            new Redirect('/good', 'https://example.org/'),
            'not a redirect',
        ]);

        $all = $this->provider->all();

        $this->assertSame(
            [['/dup', '/from-filter'], ['/good', 'https://example.org/']],
            array_map(static fn(Redirect $r): array => [$r->from, $r->to], $all)
        );
    }

    #[Test]
    public function it_returns_nothing_from_the_filter_when_it_is_not_an_array(): void
    {
        Filters\expectApplied('quant_custom_redirects')->once()->andReturn('nope');

        $this->assertSame([], $this->provider->fromFilter());
    }
}
