<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

/**
 * Renders date archives and post type archives by fetching their HTML via HTTP.
 */
final class ArchiveRenderer
{
    /**
     * Render a date archive page (year, month, or day).
     */
    public function renderDateArchive(int $year, ?int $month = null, ?int $day = null): ?RenderedContent
    {
        $url = $this->getDateArchiveUrl($year, $month, $day);

        $markup = $this->fetchRenderedHtml($url);

        if ($markup === null) {
            return null;
        }

        $path = $this->extractPath($url);

        return new RenderedContent(
            url: $path,
            markup: $markup,
            meta: $this->buildDateMeta($year, $month, $day),
        );
    }

    /**
     * Render a post type archive page.
     */
    public function renderPostTypeArchive(string $postType): ?RenderedContent
    {
        if (!$this->supportsPostTypeArchive($postType)) {
            return null;
        }

        $url = get_post_type_archive_link($postType);

        if ($url === false) {
            return null;
        }

        $markup = $this->fetchRenderedHtml($url);

        if ($markup === null) {
            return null;
        }

        $path = $this->extractPath($url);

        return new RenderedContent(
            url: $path,
            markup: $markup,
            meta: $this->buildPostTypeMeta($postType),
        );
    }

    /**
     * Check if a post type supports archive pages.
     */
    private function supportsPostTypeArchive(string $postType): bool
    {
        $postTypeObject = get_post_type_object($postType);

        if ($postTypeObject === null) {
            return false;
        }

        if ($postTypeObject->public !== true) {
            return false;
        }

        // has_archive can be bool or string (the archive slug)
        return (bool) $postTypeObject->has_archive;
    }

    /**
     * Get the URL for a date archive.
     */
    private function getDateArchiveUrl(int $year, ?int $month, ?int $day): string
    {
        if ($day !== null && $month !== null) {
            return get_day_link($year, $month, $day);
        }

        if ($month !== null) {
            return get_month_link($year, $month);
        }

        return get_year_link($year);
    }

    /**
     * Fetch the rendered HTML from the given URL.
     */
    private function fetchRenderedHtml(string $url): ?string
    {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            // SSL verification enabled by default. Use 'quant_renderer_ssl_verify' filter
            // to disable in development environments with self-signed certificates.
            'sslverify' => apply_filters('quant_renderer_ssl_verify', true),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return null;
        }

        return $body;
    }

    /**
     * Extract the path from a full URL.
     */
    private function extractPath(string $url): string
    {
        $parsed = wp_parse_url($url);

        $path = $parsed['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        return $path;
    }

    /**
     * Build metadata array for a date archive.
     *
     * @return array{archive_type: string, year: int, month: int|null, day: int|null}
     */
    private function buildDateMeta(int $year, ?int $month, ?int $day): array
    {
        return [
            'archive_type' => 'date',
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];
    }

    /**
     * Build metadata array for a post type archive.
     *
     * @return array{archive_type: string, post_type: string, label: string}
     */
    private function buildPostTypeMeta(string $postType): array
    {
        $postTypeObject = get_post_type_object($postType);

        return [
            'archive_type' => 'post_type',
            'post_type' => $postType,
            'label' => $postTypeObject->label ?? $postType,
        ];
    }
}
