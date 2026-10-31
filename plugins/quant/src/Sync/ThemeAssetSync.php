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
        'css', 'scss', 'sass', 'less',
        'js', 'mjs', 'map', 'json',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'mp3', 'pdf',
    ];

    private const SKIPPED_DIRECTORIES = ['node_modules', '.git', 'vendor'];

    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    /**
     * Sync all assets from the active theme (and its parent).
     *
     * @return FileSyncResult[]
     */
    public function syncActiveTheme(): array
    {
        $results = [];

        foreach ($this->scanActiveTheme() as $asset) {
            $results[] = $this->syncAsset($asset['file'], $asset['url']);
        }

        return $results;
    }

    /**
     * List the assets of the active theme and, for a child theme, its parent.
     *
     * @return array<int, array{file: string, url: string, type: string}>
     */
    public function scanActiveTheme(): array
    {
        $assets = $this->scanDirectory(get_stylesheet_directory(), get_stylesheet_directory_uri());

        if (get_stylesheet_directory() !== get_template_directory()) {
            $assets = array_merge($assets, $this->scanDirectory(get_template_directory(), get_template_directory_uri()));
        }

        return $assets;
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
     * @return array<int, array{file: string, url: string, type: string}>
     */
    private function scanDirectory(string $themeDir, string $themeUri): array
    {
        if (!is_dir($themeDir)) {
            return [];
        }

        $basePath = $this->uriToPath($themeUri);
        $themeDir = trailingslashit($themeDir);
        $assets = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$this->isAsset($file->getPathname(), $themeDir)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($themeDir)));
            $assets[] = [
                'file' => $file->getPathname(),
                'url' => $basePath . '/' . $relative,
                'type' => $this->assetType(strtolower($file->getExtension())),
            ];
        }

        return $assets;
    }

    private function isAsset(string $path, string $themeDir): bool
    {
        $relative = substr($path, strlen($themeDir));
        foreach (self::SKIPPED_DIRECTORIES as $dir) {
            if (str_contains('/' . str_replace('\\', '/', $relative), '/' . $dir . '/')) {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ASSET_EXTENSIONS, true);
    }

    private function assetType(string $extension): string
    {
        return match ($extension) {
            'css', 'scss', 'sass', 'less' => 'stylesheet',
            'js', 'mjs', 'map', 'json' => 'script',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico' => 'image',
            'woff', 'woff2', 'ttf', 'otf', 'eot' => 'font',
            default => 'other',
        };
    }

    private function uriToPath(string $uri): string
    {
        $parsed = wp_parse_url($uri);
        if ($parsed === false || !isset($parsed['path'])) {
            return '';
        }

        return rtrim($parsed['path'], '/');
    }
}
