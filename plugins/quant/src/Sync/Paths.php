<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * URL and path helpers shared by the sync pipeline.
 */
final class Paths
{
    /**
     * Relative path (with query string) of an absolute URL.
     */
    public static function fromUrl(string $url): string
    {
        $parsed = wp_parse_url($url);
        if ($parsed === false) {
            return '/';
        }

        $path = $parsed['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        return $path;
    }

    /**
     * Relative path without the trailing slash (except `/`), the form Quant stores.
     */
    public static function normalise(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            $path = self::fromUrl($path);
        }

        $path = '/' . ltrim(trim($path), '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if ($path !== '/' && !str_contains($path, '?')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * The URL of page $page of a paginated listing.
     */
    public static function paginated(string $baseUrl, int $page): string
    {
        global $wp_rewrite;

        if ($page <= 1) {
            return $baseUrl;
        }

        $usingPermalinks = isset($wp_rewrite) && is_object($wp_rewrite) && $wp_rewrite->using_permalinks();
        $parsed = wp_parse_url($baseUrl);

        if (!$usingPermalinks || !empty($parsed['query'])) {
            return add_query_arg('paged', $page, $baseUrl);
        }

        $base = isset($wp_rewrite->pagination_base) ? $wp_rewrite->pagination_base : 'page';

        return user_trailingslashit(trailingslashit($baseUrl) . $base . '/' . $page, 'paged');
    }

    /**
     * Number of listing pages for $total items.
     */
    public static function pageCount(int $total, ?int $perPage = null): int
    {
        $perPage = $perPage ?? (int) get_option('posts_per_page', 10);
        if ($perPage <= 0 || $total <= 0) {
            return 1;
        }

        return max(1, (int) ceil($total / $perPage));
    }

    /**
     * True when the URL points at this site (home or site URL host).
     */
    public static function isLocal(string $url): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $local = array_filter([
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
        ]);

        /** This filter is documented in v2.0.0; it adds hosts that count as the local site. */
        $local = (array) apply_filters('quant_local_hosts', $local);

        return in_array(strtolower($host), array_map('strtolower', $local), true);
    }

    /**
     * Absolute filesystem path for a local URL path, or null when it is not a file under ABSPATH.
     */
    /**
     * Extensions a local file may have to be uploaded. PHP, configuration
     * and hidden files are never sent to the edge.
     */
    public const PUBLIC_FILE_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'xsl', 'txt', 'html', 'htm', 'webmanifest',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'm4v', 'mov', 'webm', 'ogv', 'ogg', 'mp3', 'm4a', 'wav', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv',
        'zip', 'gz', 'epub', 'vtt', 'srt',
    ];

    private const PRIVATE_FILE_NAMES = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'auth.json', 'yarn.lock',
    ];

    public static function localFile(string $urlPath): ?string
    {
        $path = self::cleanLocalPath($urlPath);
        if ($path === null || !self::isPublicFileName($path)) {
            return null;
        }

        foreach (self::candidateFiles($path) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * True when the file name may be published: a known static extension,
     * no hidden path segment, not a WordPress or package manager file.
     */
    public static function isPublicFileName(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return false;
            }
        }

        $name = strtolower(basename($path));
        if (str_starts_with($name, 'wp-config') || in_array($name, self::PRIVATE_FILE_NAMES, true)) {
            return false;
        }

        /** This filter is documented in v2.0.0; it sets the file extensions that may be uploaded. */
        $allowed = (array) apply_filters('quant_public_file_extensions', self::PUBLIC_FILE_EXTENSIONS);

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowed, true);
    }

    /**
     * The decoded path without query string and without the multisite subdirectory prefix.
     */
    private static function cleanLocalPath(string $urlPath): ?string
    {
        $path = strtok($urlPath, '?');
        if ($path === false) {
            return null;
        }

        $path = rawurldecode($path);
        if (str_contains($path, '..')) {
            return null;
        }

        if (!is_multisite() || is_subdomain_install()) {
            return $path;
        }

        // Multisite subdirectory installs serve from the network root.
        $site = get_site();
        if ($site && $site->path !== '/' && str_starts_with($path, $site->path)) {
            return '/' . substr($path, strlen($site->path));
        }

        return $path;
    }

    /**
     * Where a root-relative path may live on disk: ABSPATH, then a moved wp-content directory.
     *
     * @return array<string>
     */
    private static function candidateFiles(string $path): array
    {
        $candidates = [untrailingslashit(ABSPATH) . $path];

        if (!defined('WP_CONTENT_DIR') || !defined('WP_CONTENT_URL')) {
            return $candidates;
        }

        $contentPath = wp_parse_url(WP_CONTENT_URL, PHP_URL_PATH);
        if (is_string($contentPath) && $contentPath !== '' && str_starts_with($path, $contentPath)) {
            $candidates[] = untrailingslashit(WP_CONTENT_DIR) . substr($path, strlen($contentPath));
        }

        return $candidates;
    }
}
