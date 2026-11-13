<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Search\Frontend;
use Quant\Search\SearchPageRepository;
use Quant\Search\SearchRecordBuilder;
use Quant\Tests\Support\WordPressStubs;

/**
 * "Send the WordPress search form to a Quant Search page" must also cover the core Search block, which block
 * themes put in the header. The block builds its own form (not get_search_form()), and on the static copy a
 * `/?s=` search only shows the home page.
 */
#[CoversClass(Frontend::class)]
final class SearchBlockFormTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    // The markup of the core Search block in WordPress 7.1 (wp-includes/blocks/search.php), label and button inside.
    private const BLOCK = '<form role="search" method="get" action="https://example.com/" class="wp-block-search__button-outside wp-block-search__text-button wp-block-search">'
        . '<label class="wp-block-search__label" for="wp-block-search__input-1">Search</label>'
        . '<div class="wp-block-search__inside-wrapper"><input class="wp-block-search__input" id="wp-block-search__input-1" placeholder="" value="old term" type="search" name="s" required />'
        . '<button aria-label="Search" class="wp-block-search__button wp-element-button" type="submit">Search</button></div></form>';

    /** @var array<string, mixed> */
    private array $search = ['enabled' => true, 'replace_wp_search' => true, 'default_page' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions('https://example.com');
        unset($_GET['keys']);
    }

    protected function tearDown(): void
    {
        unset($_GET['keys']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function the_search_block_sends_searches_to_the_search_page(): void
    {
        $html = $this->frontend()->replaceSearchBlock(self::BLOCK);

        $this->assertStringContainsString('action="https://example.com/search"', $html);
        $this->assertStringContainsString('name="keys"', $html);
        $this->assertStringNotContainsString('name="s"', $html);
    }

    #[Test]
    public function the_block_keeps_its_markup_and_shows_the_current_search_term(): void
    {
        $_GET['keys'] = 'library hours';

        $html = $this->frontend()->replaceSearchBlock(self::BLOCK);

        $this->assertStringContainsString('class="wp-block-search__button-outside wp-block-search__text-button wp-block-search"', $html);
        $this->assertStringContainsString('class="wp-block-search__input" id="wp-block-search__input-1"', $html);
        $this->assertStringContainsString('value="library hours"', $html);
        $this->assertStringContainsString('<button aria-label="Search"', $html);
    }

    #[Test]
    public function the_block_is_unchanged_when_the_option_is_off(): void
    {
        $this->search['replace_wp_search'] = false;

        $this->assertSame(self::BLOCK, $this->frontend()->replaceSearchBlock(self::BLOCK));
    }

    #[Test]
    public function the_block_is_unchanged_without_an_enabled_search_page(): void
    {
        $this->assertSame(self::BLOCK, $this->frontend(['id' => 'site', 'route' => '/search', 'status' => false])->replaceSearchBlock(self::BLOCK));
    }

    /**
     * @param array<string, mixed>|null $page
     */
    private function frontend(?array $page = null): Frontend
    {
        $settings = Mockery::mock(Settings::class);
        $settings->allows('getSearchSettings')->andReturnUsing(fn(): array => $this->search);

        $pages = Mockery::mock(SearchPageRepository::class);
        $pages->allows('all')->andReturn([$page ?? ['id' => 'site', 'route' => '/search', 'status' => true]]);

        return new Frontend($settings, $pages, Mockery::mock(SearchRecordBuilder::class), Mockery::mock(ContentApiInterface::class));
    }
}
