<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Sync\InternalFetcher;

/**
 * Recognises the plugin's own render requests and adjusts WordPress for them.
 *
 * A valid internal token disables the canonical redirect (so paginated and
 * query-string pages render instead of redirecting) and answers the
 * connectivity check at /__quant-validate.
 */
class InternalRequestHandler
{
    public const VALIDATE_PATH = '/__quant-validate';

    public function register(): void
    {
        add_action('init', [$this, 'onInit'], 1);
    }

    public function onInit(): void
    {
        if ($this->isValidatePath()) {
            $this->answerValidation();
        }

        if (!$this->isInternalRequest()) {
            return;
        }

        if (!defined('QUANT_INTERNAL_REQUEST')) {
            define('QUANT_INTERNAL_REQUEST', true);
        }

        remove_action('template_redirect', 'redirect_canonical');
        remove_action('template_redirect', 'wp_old_slug_redirect');
        add_filter('wp_redirect', [$this, 'blockStatisticsRedirects'], 10, 1);
        add_filter('show_admin_bar', '__return_false', 1000);
    }

    public function isInternalRequest(): bool
    {
        $header = isset($_SERVER['HTTP_QUANT_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_QUANT_TOKEN'])) : '';
        $token = get_option(InternalFetcher::TOKEN_OPTION);

        return is_string($header) && is_string($token) && $token !== '' && hash_equals($token, $header);
    }

    /**
     * Keep redirects added by plugins (for example to a login page) from
     * replacing the page in the static copy; return the URL unchanged.
     */
    public function blockStatisticsRedirects(string $location): string
    {
        return $location;
    }

    private function isValidatePath(): bool
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = wp_parse_url(is_string($uri) ? $uri : '', PHP_URL_PATH);

        return is_string($path) && rtrim($path, '/') !== '' && str_ends_with(rtrim($path, '/'), self::VALIDATE_PATH);
    }

    private function answerValidation(): void
    {
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        echo 'quant-ok';
        exit;
    }
}
