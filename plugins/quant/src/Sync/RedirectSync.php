<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Api\ContentApiInterface;

/**
 * Syncs redirects to QuantCDN.
 *
 * Supports getting redirects from:
 * - Redirection plugin (most popular WordPress redirect plugin)
 * - Custom filter 'quant_custom_redirects' for user-provided redirects
 */
final class RedirectSync
{
    /**
     * @param ContentApiInterface $api The API client for syncing redirects
     * @param callable|null $redirectionPluginChecker Optional callable to check if Redirection plugin is active.
     *                                                 Defaults to checking for red_get_url_query_type function.
     */
    public function __construct(
        private readonly ContentApiInterface $api,
        private $redirectionPluginChecker = null
    ) {
    }

    /**
     * Sync a single redirect to QuantCDN.
     */
    public function sync(Redirect $redirect): FileSyncResult
    {
        // Validate the from path
        $validationError = $this->validateRedirect($redirect);
        if ($validationError !== null) {
            return FileSyncResult::failure($redirect->from, $validationError);
        }

        // Create the redirect via API
        $response = $this->api->createRedirect(
            $redirect->from,
            $redirect->to,
            $redirect->statusCode
        );

        if (!$response->success) {
            return FileSyncResult::failure($redirect->from, $response->message);
        }

        return FileSyncResult::success($redirect->from, [
            'status_code' => $redirect->statusCode,
            'to' => $redirect->to,
        ]);
    }

    /**
     * Sync multiple redirects to QuantCDN.
     *
     * @param Redirect[] $redirects
     * @return FileSyncResult[]
     */
    public function syncBatch(array $redirects): array
    {
        $results = [];

        foreach ($redirects as $redirect) {
            $results[] = $this->sync($redirect);
        }

        return $results;
    }

    /**
     * Get redirects from a supported plugin.
     *
     * @return Redirect[]
     */
    public function getRedirectsFromPlugin(string $plugin): array
    {
        return match ($plugin) {
            'redirection' => $this->getRedirectsFromRedirectionPlugin(),
            default => [],
        };
    }

    /**
     * Get redirects from the custom filter.
     *
     * Users can provide redirects via the 'quant_custom_redirects' filter.
     *
     * @return Redirect[]
     */
    public function getRedirectsFromFilter(): array
    {
        /** @var mixed $rawRedirects */
        $rawRedirects = apply_filters('quant_custom_redirects', []);

        if (!is_array($rawRedirects)) {
            return [];
        }

        // Filter to only include valid Redirect objects
        $redirects = [];
        foreach ($rawRedirects as $redirect) {
            if ($redirect instanceof Redirect) {
                $redirects[] = $redirect;
            }
        }

        return $redirects;
    }

    /**
     * Validate a redirect before syncing.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateRedirect(Redirect $redirect): ?string
    {
        $from = $redirect->from;

        // Check if from is an external URL (must check before leading slash)
        if ($this->isExternalUrl($from)) {
            return 'Source path cannot be an external URL';
        }

        // Check if from path is empty or doesn't start with /
        if ($from === '' || $from[0] !== '/') {
            return 'Source path must start with /';
        }

        return null;
    }

    /**
     * Check if a path is an external URL.
     */
    private function isExternalUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Get redirects from the Redirection plugin.
     *
     * The Redirection plugin stores redirects in the {prefix}redirection_items table.
     *
     * @return Redirect[]
     */
    private function getRedirectsFromRedirectionPlugin(): array
    {
        // Check if Redirection plugin is active
        if (!$this->isRedirectionPluginActive()) {
            return [];
        }

        global $wpdb;

        $table = $wpdb->prefix . 'redirection_items';

        // Query for enabled URL redirects only (no regex, no other match types)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $query = $wpdb->prepare(
            "SELECT id, url, action_data, action_code, status
             FROM %i
             WHERE status = %s
             AND action_type = %s
             AND match_type = %s",
            $table,
            'enabled',
            'url',
            'url'
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($query);

        if (empty($results) || !is_array($results)) {
            return [];
        }

        $redirects = [];
        foreach ($results as $row) {
            $redirects[] = new Redirect(
                (string) $row->url,
                (string) $row->action_data,
                (int) $row->action_code
            );
        }

        return $redirects;
    }

    /**
     * Check if the Redirection plugin is active.
     */
    private function isRedirectionPluginActive(): bool
    {
        if ($this->redirectionPluginChecker !== null) {
            return (bool) ($this->redirectionPluginChecker)();
        }

        // Default check: look for the Redirection plugin's function
        return function_exists('red_get_url_query_type');
    }
}
