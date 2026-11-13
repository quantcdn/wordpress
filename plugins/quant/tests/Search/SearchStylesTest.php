<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use PHPUnit\Framework\TestCase;

/**
 * The search page stylesheet imports the instantsearch.css "algolia" theme, which draws every hit as a card a
 * quarter of the row wide (`.ais-Hits-item { width: calc(25% - 1rem) }`). The plugin renders hits as full-width
 * rows (.quant-search__hit), so in a theme content column of about 650 px each result became a column some 80 px
 * wide. The plugin styles must undo the theme's card layout inside .quant-search.
 */
final class SearchStylesTest extends TestCase
{
    private const STYLESHEET = __DIR__ . '/../../assets/src/frontend/search.scss';

    public function testTheSearchPageImportsTheInstantsearchTheme(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../assets/src/frontend/search.js');

        // If this import goes, the overrides below are no longer needed.
        $this->assertStringContainsString("import 'instantsearch.css/themes/algolia-min.css';", $script);
    }

    public function testHitsAreFullWidthRowsNotQuarterWidthCards(): void
    {
        $item = $this->block('.ais-Hits-item');

        $this->assertMatchesRegularExpression('/\bwidth:\s*100%/', $item, 'A hit fills the results column.');
        $this->assertMatchesRegularExpression('/\bmargin:\s*0\b/', $item, 'No card gutter.');
        $this->assertMatchesRegularExpression('/\bborder:\s*0\b/', $item, 'No card border.');
        $this->assertMatchesRegularExpression('/\bbox-shadow:\s*none\b/', $item, 'No card shadow.');
        $this->assertMatchesRegularExpression('/\bpadding:\s*0\b/', $item, 'The row padding comes from .quant-search__hit.');
    }

    public function testTheHitsListIsAPlainColumn(): void
    {
        $list = $this->block('.ais-Hits-list');

        $this->assertMatchesRegularExpression('/\bdisplay:\s*block\b/', $list, 'The list does not wrap hits into a row.');
        $this->assertMatchesRegularExpression('/\bmargin:\s*0\b/', $list, 'No negative gutter.');
    }

    public function testRowsAreSeparatedOnTheItemsNotOnTheOnlyChildHit(): void
    {
        // The hit template renders one <article class="quant-search__hit"> per <li class="ais-Hits-item">, so a
        // :last-child rule on the hit matches every hit and removes every separator.
        $this->assertMatchesRegularExpression('/\bborder-top:\s*1px solid\b/', $this->block('.ais-Hits-item + .ais-Hits-item'));
        $this->assertStringNotContainsString('&:last-child', (string) file_get_contents(self::STYLESHEET));
    }

    public function testAHitHoldsItsFloatedImage(): void
    {
        $this->assertMatchesRegularExpression('/\bdisplay:\s*flow-root\b/', $this->block('&__hit'));
    }

    /**
     * The declarations of a rule nested directly in the .quant-search block, so it outranks the theme's
     * single-class rule wherever the theme loads.
     */
    private function block(string $selector): string
    {
        $scss = (string) file_get_contents(self::STYLESHEET);
        $start = strpos($scss, ".quant-search {\n");
        $this->assertNotFalse($start, 'search.scss has a .quant-search block.');

        $pattern = '/^\t' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        $this->assertSame(1, preg_match($pattern, substr($scss, (int) $start), $m), "search.scss styles {$selector} inside .quant-search.");

        return $m[1];
    }
}
