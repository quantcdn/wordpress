<?php

declare(strict_types=1);

namespace Quant\Api;

/**
 * Operations on one Quant project through the Content API.
 */
interface ContentApiInterface
{
    /**
     * GET /ping. On success `data` holds the project record, including
     * `config.search_enabled` and `config.search_index`.
     */
    public function ping(): ApiResponse;

    /**
     * Publish markup at a path.
     *
     * @param array<string, mixed> $info   `info` fields: author_name, author_email, log, source, custom_1, custom_2
     * @param array<string, mixed> $options  published, content_timestamp, search_record, find_attachments,
     *                                      proxy_override, headers, transitions
     */
    public function publish(string $url, string $markup, array $info = [], array $options = []): ApiResponse;

    public function unpublish(string $url): ApiResponse;

    /**
     * Upload a file from disk to be served at $path.
     */
    public function uploadFile(string $path, string $localPath): ApiResponse;

    /**
     * Upload generated contents (for example an XML sitemap) as a file served at $path.
     * The edge sets the content type from the file extension.
     */
    public function uploadContents(string $path, string $contents): ApiResponse;

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse;

    /**
     * Purge one or more paths. `/*` purges the whole project.
     *
     * @param string|array<string> $paths
     */
    public function purge(string|array $paths): ApiResponse;

    /**
     * GET /search: index statistics and settings.
     */
    public function searchStatus(): ApiResponse;

    /**
     * POST /search: add or replace search records.
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function sendSearchRecords(array $records): ApiResponse;

    /**
     * DELETE /search/all.
     */
    public function clearSearchIndex(): ApiResponse;

    /**
     * POST /search/facet: declare the attributes that can be used as facets.
     *
     * @param array<string> $keys
     */
    public function addSearchFacets(array $keys): ApiResponse;

    /**
     * POST /url-meta: metadata for the given paths.
     *
     * @param array<string> $urls
     */
    public function urlMeta(array $urls): ApiResponse;
}
