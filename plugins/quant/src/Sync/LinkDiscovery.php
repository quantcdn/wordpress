<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Finds pagination links in rendered markup.
 */
final class LinkDiscovery
{
    /**
     * Local paths of pagination links: rel="next" and `.page-numbers` anchors.
     *
     * @return array<string>
     */
    public static function paginationLinks(string $markup): array
    {
        $paths = [];

        foreach (self::hrefs($markup) as $href) {
            $path = self::localPaginatedPath($href);
            if ($path !== null) {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * @return array<string>
     */
    private static function hrefs(string $markup): array
    {
        $hrefs = [];

        if (preg_match_all('#<link[^>]+rel=["\']next["\'][^>]*href=["\']([^"\']+)["\']#i', $markup, $m) > 0) {
            $hrefs = array_merge($hrefs, $m[1]);
        }
        if (preg_match_all('#<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']next["\']#i', $markup, $m) > 0) {
            $hrefs = array_merge($hrefs, $m[1]);
        }
        if (preg_match_all('#<a[^>]+class=["\'][^"\']*page-numbers[^"\']*["\'][^>]*href=["\']([^"\']+)["\']#i', $markup, $m) > 0) {
            $hrefs = array_merge($hrefs, $m[1]);
        }
        if (preg_match_all('#<a[^>]+href=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*page-numbers[^"\']*["\']#i', $markup, $m) > 0) {
            $hrefs = array_merge($hrefs, $m[1]);
        }

        return array_map(static fn(string $href): string => html_entity_decode($href, ENT_QUOTES), $hrefs);
    }

    private static function localPaginatedPath(string $href): ?string
    {
        if (!Paths::isLocal($href)) {
            return null;
        }

        $path = Paths::fromUrl(str_starts_with($href, '/') ? home_url($href) : $href);

        if (!preg_match('#/page/\d+#', $path) && !preg_match('#[?&]paged=\d+#', $path)) {
            return null;
        }

        return Paths::normalise($path);
    }
}
