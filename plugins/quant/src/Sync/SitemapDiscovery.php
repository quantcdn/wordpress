<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Finds the sitemap routes of the site: the index, every child sitemap and
 * the XSL stylesheets. Supports WordPress core sitemaps, Yoast SEO and
 * Rank Math; any other plugin that serves an index at /sitemap_index.xml or
 * /sitemap.xml is read by parsing the index.
 */
class SitemapDiscovery
{
    public function __construct(
        private readonly InternalFetcher $fetcher
    ) {
    }

    /**
     * @return array<string> Relative paths
     */
    public function routes(): array
    {
        $routes = $this->coreRoutes();

        foreach ($this->pluginIndexes() as $index) {
            $routes = array_merge($routes, $this->routesFromIndex($index));
        }

        /** This filter is documented in v2.0.0; it adjusts the sitemap routes that are synced. */
        $routes = (array) apply_filters('quant_sitemap_routes', $routes);

        return array_values(array_unique(array_filter(array_map(
            static fn($route): string => is_string($route) ? Paths::normalise($route) : '',
            $routes
        ))));
    }

    /**
     * @return array<string>
     */
    private function coreRoutes(): array
    {
        if (!function_exists('wp_sitemaps_get_server')) {
            return [];
        }

        $server = wp_sitemaps_get_server();
        if (!$server || !$server->sitemaps_enabled()) {
            return [];
        }

        $routes = [
            Paths::fromUrl($server->index->get_index_url()),
            Paths::fromUrl($server->renderer->get_sitemap_index_stylesheet_url()),
            Paths::fromUrl($server->renderer->get_sitemap_stylesheet_url()),
        ];

        foreach ($server->registry->get_providers() as $provider) {
            foreach ($provider->get_sitemap_entries() as $entry) {
                if (!empty($entry['loc'])) {
                    $routes[] = Paths::fromUrl((string) $entry['loc']);
                }
            }
        }

        return $routes;
    }

    /**
     * @return array<string>
     */
    private function pluginIndexes(): array
    {
        $indexes = [];

        if (defined('WPSEO_VERSION') || class_exists('RankMath') || class_exists('\RankMath\Sitemap\Sitemap')) {
            $indexes[] = '/sitemap_index.xml';
        }

        /** This filter is documented in v2.0.0; it adds sitemap index paths to read. */
        return array_values(array_unique((array) apply_filters('quant_sitemap_indexes', $indexes)));
    }

    /**
     * @return array<string>
     */
    private function routesFromIndex(string $indexPath): array
    {
        try {
            $result = $this->fetcher->fetch($indexPath, 30);
        } catch (\RuntimeException) {
            return [];
        }

        if (!$result->isOk()) {
            return [];
        }

        $routes = [$indexPath];

        if (preg_match('#<\?xml-stylesheet[^>]+href="([^"]+)"#i', $result->body, $xsl) === 1) {
            $routes[] = Paths::fromUrl($xsl[1]);
        }

        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $result->body, $matches) > 0) {
            foreach ($matches[1] as $loc) {
                $loc = html_entity_decode($loc);
                if (Paths::isLocal($loc) && str_ends_with(strtolower(strtok($loc, '?') ?: ''), '.xml')) {
                    $routes[] = Paths::fromUrl($loc);
                }
            }
        }

        return $routes;
    }
}
