<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\Paths;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(Paths::class)]
final class PathsTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions('https://example.com');
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'posts_per_page' ? 10 : $d);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_extracts_paths_with_query_strings(): void
    {
        $this->assertSame('/blog/post/', Paths::fromUrl('https://example.com/blog/post/'));
        $this->assertSame('/?p=12', Paths::fromUrl('https://example.com/?p=12'));
        $this->assertSame('/', Paths::fromUrl('https://example.com'));
        $this->assertSame('/a/b', Paths::fromUrl('https://example.com//a///b'));
    }

    #[Test]
    public function it_normalises_paths_the_way_quant_stores_them(): void
    {
        $this->assertSame('/blog/post', Paths::normalise('/blog/post/'));
        $this->assertSame('/blog/post', Paths::normalise('blog/post'));
        $this->assertSame('/', Paths::normalise('/'));
        $this->assertSame('/', Paths::normalise(''));
        $this->assertSame('/?p=12', Paths::normalise('/?p=12'));
        $this->assertSame('/x', Paths::normalise('https://example.com/x/'));
        $this->assertSame('/a/b', Paths::normalise('//a//b//'));
    }

    #[Test]
    public function it_builds_pretty_pagination_links(): void
    {
        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return true; }
        };

        $this->assertSame('https://example.com/blog/', Paths::paginated('https://example.com/blog/', 1));
        $this->assertSame('https://example.com/blog/page/2/', Paths::paginated('https://example.com/blog/', 2));
        $this->assertSame('https://example.com/?cat=3&paged=2', Paths::paginated('https://example.com/?cat=3', 2));
    }

    #[Test]
    public function it_builds_plain_pagination_links_without_permalinks(): void
    {
        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return false; }
        };

        $this->assertSame('https://example.com/?paged=2', Paths::paginated('https://example.com/', 2));
    }

    #[Test]
    public function it_counts_listing_pages(): void
    {
        $this->assertSame(1, Paths::pageCount(0));
        $this->assertSame(1, Paths::pageCount(10));
        $this->assertSame(2, Paths::pageCount(11));
        $this->assertSame(3, Paths::pageCount(25, 10));
        $this->assertSame(1, Paths::pageCount(25, 0));
    }

    #[Test]
    public function it_recognises_local_urls(): void
    {
        $this->assertTrue(Paths::isLocal('/wp-content/x.css'));
        $this->assertTrue(Paths::isLocal('https://example.com/x'));
        $this->assertTrue(Paths::isLocal('http://EXAMPLE.com/x'));
        $this->assertFalse(Paths::isLocal('//cdn.other.com/x.js'));
        $this->assertFalse(Paths::isLocal('https://other.com/x'));
        $this->assertFalse(Paths::isLocal('mailto:x@y.z'));
    }

    #[Test]
    public function it_resolves_local_files_under_abspath_only(): void
    {
        $dir = ABSPATH . 'wp-content/test-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/style.css', 'x');
        $relative = substr($dir, strlen(rtrim(ABSPATH, '/')));

        try {
            $this->assertSame($dir . '/style.css', Paths::localFile($relative . '/style.css?ver=1'));
            $this->assertSame($dir . '/style.css', Paths::localFile(rawurlencode($relative) . '/style.css'));
            $this->assertNull(Paths::localFile($relative . '/missing.css'));
            $this->assertNull(Paths::localFile('/../../etc/passwd'));
            $this->assertNull(Paths::localFile(''));
        } finally {
            unlink($dir . '/style.css');
            rmdir($dir);
        }
    }

    #[Test]
    public function it_allows_only_public_static_file_names(): void
    {
        foreach (['/wp-content/uploads/a.jpg', '/wp-content/themes/t/style.css', '/robots.txt', '/doc.PDF', '/sitemap.xml', '/fonts/x.woff2'] as $ok) {
            $this->assertTrue(Paths::isPublicFileName($ok), $ok);
        }

        foreach ([
            '/wp-config.php', '/index.php', '/x.phtml', '/wp-config-sample.php', '/wp-config.bak.txt',
            '/.env', '/.htaccess', '/wp-content/.git/config.json', '/wp-content/plugins/p/composer.json',
            '/package.json', '/wp-content/debug.log', '/backup.sql', '/noextension',
        ] as $blocked) {
            $this->assertFalse(Paths::isPublicFileName($blocked), $blocked);
        }
    }

    #[Test]
    public function it_lets_a_filter_change_the_allowed_extensions(): void
    {
        \Brain\Monkey\Filters\expectApplied('quant_public_file_extensions')->andReturnUsing(static fn(array $ext): array => array_merge($ext, ['log']));

        $this->assertTrue(Paths::isPublicFileName('/wp-content/app.log'));
    }

    #[Test]
    public function it_refuses_to_resolve_private_files_that_exist(): void
    {
        $dir = ABSPATH . 'wp-content/test-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/wp-config.php', '<?php');
        file_put_contents($dir . '/.env', 'SECRET=1');
        $relative = substr($dir, strlen(rtrim(ABSPATH, '/')));

        try {
            $this->assertNull(Paths::localFile($relative . '/wp-config.php'));
            $this->assertNull(Paths::localFile($relative . '/.env'));
        } finally {
            unlink($dir . '/wp-config.php');
            unlink($dir . '/.env');
            rmdir($dir);
        }
    }
}
