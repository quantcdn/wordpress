<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
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
    public function regex_replacement_tokens_in_the_search_term_stay_literal(): void
    {
        // WordPress adds slashes to $_GET, so the term "$0 \1 ${1}" arrives as below.
        $_GET['keys'] = '$0 \\\\1 ${1}';

        $html = $this->frontend()->replaceSearchBlock(self::BLOCK);

        $this->assertStringContainsString('value="$0 \\1 ${1}"', $html);
        $this->assertSame(1, substr_count($html, 'value='), 'The input has one value attribute.');
        $this->assertStringContainsString('action="https://example.com/search"', $html);
    }

    #[Test]
    public function a_quote_in_the_search_term_is_escaped(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES));
        $_GET['keys'] = 'a" onfocus="x';

        $html = $this->frontend()->replaceSearchBlock(self::BLOCK);

        $this->assertStringContainsString('value="a&quot; onfocus=&quot;x"', $html);
    }

    #[Test]
    public function the_search_landmark_wrapper_is_covered(): void
    {
        // WordPress 7.1 with the html5 search-element theme support: <search> wraps a form with no role.
        $block = '<search class="wp-block-search__button-outside wp-block-search"><form method="get" action="https://example.com/">'
            . '<input class="wp-block-search__input" id="wp-block-search__input-2" placeholder="" value="" type="search" name="s" required />'
            . '<button class="wp-block-search__button" type="submit">Search</button></form></search>';

        $html = $this->frontend()->replaceSearchBlock($block);

        $this->assertStringStartsWith('<search class="wp-block-search__button-outside wp-block-search"><form method="get" action="https://example.com/search">', $html);
        $this->assertStringContainsString('name="keys"', $html);
    }

    #[Test]
    public function the_button_only_expandable_block_is_covered(): void
    {
        $block = '<form role="search" method="get" action="https://example.com/" class="wp-block-search__button-only wp-block-search__searchfield-hidden wp-block-search"'
            . ' data-wp-interactive="core/search" data-wp-context=\'{"isSearchInputVisible":false}\''
            . ' data-wp-class--wp-block-search__searchfield-hidden="!context.isSearchInputVisible">'
            . '<input data-wp-bind--aria-hidden="!context.isSearchInputVisible" aria-hidden="true" tabindex="-1" class="wp-block-search__input" id="wp-block-search__input-3" placeholder="" value="" type="search" name="s" required />'
            . '<button aria-expanded="false" class="wp-block-search__button wp-element-button" type="button">Search</button></form>';

        $html = $this->frontend()->replaceSearchBlock($block);

        $this->assertStringContainsString('action="https://example.com/search"', $html);
        $this->assertStringContainsString('name="keys"', $html);
        $this->assertStringContainsString('data-wp-context=\'{"isSearchInputVisible":false}\'', $html);
        $this->assertStringContainsString('aria-hidden="true" tabindex="-1"', $html);
    }

    #[Test]
    public function a_hidden_post_type_field_is_left_alone(): void
    {
        $block = str_replace('required />', 'required /><input type="hidden" name="post_type" value="post" />', self::BLOCK);

        $html = $this->frontend()->replaceSearchBlock($block);

        $this->assertStringContainsString('<input type="hidden" name="post_type" value="post" />', $html);
        $this->assertSame(1, substr_count($html, 'name="keys"'));
    }

    #[Test]
    public function the_form_goes_to_the_route_of_the_default_page(): void
    {
        $html = $this->frontend(['id' => 'site', 'route' => '/find', 'status' => true])->replaceSearchBlock(self::BLOCK);

        $this->assertStringContainsString('action="https://example.com/find"', $html);
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

        $pages = Mockery::mock(SearchPageRepository::class)->makePartial();
        $pages->allows('all')->andReturn([$page ?? ['id' => 'site', 'route' => '/search', 'status' => true]]);

        return new Frontend($settings, $pages, Mockery::mock(SearchRecordBuilder::class), Mockery::mock(ContentApiInterface::class));
    }
}
