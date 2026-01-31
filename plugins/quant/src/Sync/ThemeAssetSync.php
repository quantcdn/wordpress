<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Api\ContentApiInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Syncs theme static assets (CSS, JS, images, fonts) to QuantCDN.
 */
final class ThemeAssetSync
{
    /**
     * File extensions to sync as static assets.
     */
    private const ASSET_EXTENSIONS = [
        // Stylesheets
        'css',
        'scss',
        'sass',
        'less',
        // JavaScript
        'js',
        'mjs',
        // Images
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'avif',
        'ico',
        'svg',
        // Fonts
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        // Other
        'json',
        'map',
    ];

    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    /**
     * Sync all assets from the active theme.
     *
     * @return FileSyncResult[]
     */
    public function syncActiveTheme(): array
    {
        $results = [];

        // Get child theme directory (or main theme if no child theme)
        $stylesheetDir = get_stylesheet_directory();
        $stylesheetUri = get_stylesheet_directory_uri();

        // Get parent theme directory
        $templateDir = get_template_directory();
        $templateUri = get_template_directory_uri();

        // Sync child theme assets first
        $results = array_merge(
            $results,
            $this->syncThemeDirectory($stylesheetDir, $stylesheetUri)
        );

        // If using a child theme, also sync parent theme assets
        if ($stylesheetDir !== $templateDir) {
            $results = array_merge(
                $results,
                $this->syncThemeDirectory($templateDir, $templateUri)
            );
        }

        return $results;
    }

    /**
     * Sync a single asset file to QuantCDN.
     */
    public function syncAsset(string $localPath, string $cdnPath): FileSyncResult
    {
        if (!file_exists($localPath)) {
            return FileSyncResult::failure($cdnPath, "File not found: {$localPath}");
        }

        $response = $this->api->uploadFile($cdnPath, $localPath);

        if (!$response->success) {
            return FileSyncResult::failure($cdnPath, $response->message);
        }

        return FileSyncResult::success($cdnPath);
    }

    /**
     * Sync all assets from a theme directory.
     *
     * @return FileSyncResult[]
     */
    private function syncThemeDirectory(string $themeDir, string $themeUri): array
    {
        if (!is_dir($themeDir)) {
            return [];
        }

        $results = [];
        $cdnBasePath = $this->uriToPath($themeUri);
        $themeDir = trailingslashit($themeDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $themeDir,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (!$this->isAssetExtension($extension)) {
                continue;
            }

            $localPath = $file->getPathname();
            $relativePath = substr($localPath, strlen($themeDir));
            $cdnPath = $cdnBasePath . '/' . $relativePath;

            $results[] = $this->syncAsset($localPath, $cdnPath);
        }

        return $results;
    }

    /**
     * Check if the extension is a supported asset type.
     */
    private function isAssetExtension(string $extension): bool
    {
        return in_array($extension, self::ASSET_EXTENSIONS, true);
    }

    /**
     * Convert a theme URI to a CDN path.
     */
    private function uriToPath(string $uri): string
    {
        $parsed = parse_url($uri);
        if ($parsed === false || !isset($parsed['path'])) {
            return '';
        }
        return $parsed['path'];
    }
}
