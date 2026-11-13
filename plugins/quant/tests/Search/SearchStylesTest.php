<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use PHPUnit\Framework\TestCase;

/**
 * The search page stylesheet bundles the instantsearch.css "algolia" theme, which draws every hit as a card a
 * quarter of the row wide (`.ais-Hits-item { width: calc(25% - 1rem) }`). The plugin renders hits as full-width
 * rows (.quant-search__hit), so in a theme content column of about 650 px each result became a column some 80 px
 * wide. The built stylesheet (what the site loads) must undo the theme's card layout inside .quant-search.
 *
 * CI checks that assets/build matches the sources, so these checks also hold for search.scss.
 */
final class SearchStylesTest extends TestCase
{
    private const STYLESHEET = __DIR__ . '/../../assets/build/search.css';

    public function testTheBuiltStylesheetHasTheThemeCardLayout(): void
    {
        // If the theme rule goes, the overrides below are no longer needed.
        $this->assertStringContainsString('width:calc(25% - 1rem)', $this->rule('.ais-Hits-item'));
    }

    public function testHitsAreFullWidthRowsNotQuarterWidthCards(): void
    {
        $item = $this->rule('.quant-search .ais-Hits-item');

        $this->assertMatchesRegularExpression('/(^|;)width:100%(;|$)/', $item, 'A hit fills the results column.');
        $this->assertMatchesRegularExpression('/(^|;)margin:0(;|$)/', $item, 'No card gutter.');
        $this->assertMatchesRegularExpression('/(^|;)border:0(;|$)/', $item, 'No card border.');
        $this->assertMatchesRegularExpression('/(^|;)box-shadow:none(;|$)/', $item, 'No card shadow.');
        $this->assertMatchesRegularExpression('/(^|;)padding:0(;|$)/', $item, 'The row padding comes from .quant-search__hit.');
    }

    public function testTheHitsListIsAPlainColumn(): void
    {
        $list = $this->rule('.quant-search .ais-Hits-list');

        $this->assertMatchesRegularExpression('/(^|;)display:block(;|$)/', $list, 'The list does not wrap hits into a row.');
        $this->assertMatchesRegularExpression('/(^|;)margin:0(;|$)/', $list, 'No negative gutter.');
    }

    public function testRowsAreSeparatedOnTheItemsNotOnTheOnlyChildHit(): void
    {
        // The hit template renders one <article class="quant-search__hit"> per <li class="ais-Hits-item">, so a
        // :last-child rule on the hit matches every hit and removes every separator.
        $this->assertMatchesRegularExpression('/(^|;)border-top:1px solid/', $this->rule('.quant-search .ais-Hits-item+.ais-Hits-item'));
        $this->assertStringNotContainsString('.quant-search__hit:last-child', $this->css());
    }

    public function testAHitHoldsItsFloatedImage(): void
    {
        $this->assertMatchesRegularExpression('/(^|;)display:flow-root(;|$)/', $this->rule('.quant-search__hit'));
    }

    private function css(): string
    {
        $css = (string) file_get_contents(self::STYLESHEET);
        $this->assertNotSame('', $css, 'Run npm run build: assets/build/search.css is missing.');

        return $css;
    }

    /**
     * The declarations of the first rule whose selector list contains $selector exactly (minified CSS).
     */
    private function rule(string $selector): string
    {
        $found = preg_match_all('/(?:(?<=})|^)([^{}]+)\{([^{}]*)\}/', $this->css(), $rules, PREG_SET_ORDER);
        $this->assertNotFalse($found);

        foreach ($rules as $rule) {
            if (in_array($selector, array_map('trim', explode(',', $rule[1])), true)) {
                return $rule[2];
            }
        }

        $this->fail("assets/build/search.css has no rule for {$selector}.");
    }
}
