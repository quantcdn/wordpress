<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\LinkDiscovery;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(LinkDiscovery::class)]
final class LinkDiscoveryTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions('https://example.com');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_finds_rel_next_and_page_number_links_on_the_local_site(): void
    {
        $html = <<<HTML
<link rel="next" href="https://example.com/blog/page/2/">
<link href="https://example.com/blog/page/3/" rel="next">
<a class="page-numbers" href="/blog/page/4/">4</a>
<a href="https://example.com/blog/page/5/" class="next page-numbers">Next</a>
<a class="page-numbers" href="https://other.com/page/9/">external</a>
<a class="page-numbers" href="/blog/">1</a>
<a href="https://example.com/?paged=6" class="page-numbers">6</a>
HTML;

        $this->assertSame(
            ['/blog/page/2', '/blog/page/3', '/blog/page/4', '/blog/page/5', '/?paged=6'],
            LinkDiscovery::paginationLinks($html)
        );
    }

    #[Test]
    public function it_decodes_entities_and_dedupes(): void
    {
        $html = '<a class="page-numbers" href="/blog/?cat=1&amp;paged=2">2</a><a class="page-numbers" href="/blog/?cat=1&amp;paged=2">2</a>';

        $this->assertSame(['/blog/?cat=1&paged=2'], LinkDiscovery::paginationLinks($html));
    }

    #[Test]
    public function it_returns_nothing_for_markup_without_pagination(): void
    {
        $this->assertSame([], LinkDiscovery::paginationLinks('<a href="/about/">About</a>'));
    }
}
