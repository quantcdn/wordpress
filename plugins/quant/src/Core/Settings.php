<?php

declare(strict_types=1);

namespace Quant\Core;

/**
 * Plugin settings stored in wp_options.
 *
 * Every getter merges the stored value with the defaults and then with the
 * QUANT_* environment overrides, so callers always see a complete array.
 */
class Settings
{
    public const OPTION_MODE = 'quant_mode';
    public const OPTION_CREDENTIALS = 'quant_credentials';
    public const OPTION_STATIC_CONFIG = 'quant_static_config';
    public const OPTION_SYNC_SETTINGS = 'quant_sync_settings';
    public const OPTION_SCHEDULE = 'quant_schedule';
    public const OPTION_SEARCH = 'quant_search_settings';

    public const MODE_STATIC = 'static';
    public const MODE_CDN_ONLY = 'cdn_only';

    public const DEFAULT_ENDPOINT = 'https://api.quantcdn.io';

    public const CREDENTIAL_DEFAULTS = [
        'organization' => '',
        'project' => '',
        'token' => '',
        'endpoint' => self::DEFAULT_ENDPOINT,
        'disable_tls_verify' => false,
        'http_request_timeout' => 30,
    ];

    public const STATIC_DEFAULTS = [
        'webserver_url' => '',
        'webserver_host' => '',
        'route_404' => '/404',
        'custom_routes' => ['/robots.txt'],
        'domain_rewrites' => [],
        'strip_host' => true,
        'proxy_override' => false,
        'sitemap' => true,
        'theme_assets' => true,
        'follow_links' => true,
    ];

    public const SYNC_DEFAULTS = [
        'auto_sync' => true,
        'auto_purge' => true,
        'sync_drafts' => false,
        'sync_related' => true,
    ];

    public const SCHEDULE_DEFAULTS = [
        'enabled' => false,
        'interval' => 'daily',
        'types' => ['home', 'post', 'page'],
        'custom_routes' => [],
    ];

    public const SEARCH_DEFAULTS = [
        'enabled' => false,
        'post_types' => ['post', 'page'],
        'excluded_post_types' => [],
        'summary_source' => 'excerpt',
        'image_size' => 'thumbnail',
        'replace_wp_search' => false,
        'default_page' => '',
    ];

    public function getMode(): string
    {
        $override = Environment::overridesFor('mode');
        $mode = $override['mode'] ?? get_option(self::OPTION_MODE, self::MODE_STATIC);

        return $mode === self::MODE_CDN_ONLY ? self::MODE_CDN_ONLY : self::MODE_STATIC;
    }

    public function setMode(string $mode): void
    {
        update_option(self::OPTION_MODE, $mode === self::MODE_CDN_ONLY ? self::MODE_CDN_ONLY : self::MODE_STATIC);
    }

    public function isStaticMode(): bool
    {
        return $this->getMode() === self::MODE_STATIC;
    }

    public function isCdnOnlyMode(): bool
    {
        return $this->getMode() === self::MODE_CDN_ONLY;
    }

    /**
     * @return array{organization: string, project: string, token: string, endpoint: string, disable_tls_verify: bool, http_request_timeout: int}
     */
    public function getCredentials(): array
    {
        $credentials = $this->load(self::OPTION_CREDENTIALS, self::CREDENTIAL_DEFAULTS, 'credentials');
        $credentials['endpoint'] = rtrim((string) $credentials['endpoint'], '/') ?: self::DEFAULT_ENDPOINT;
        $credentials['disable_tls_verify'] = (bool) $credentials['disable_tls_verify'];
        $credentials['http_request_timeout'] = max(5, (int) $credentials['http_request_timeout']);

        return $credentials;
    }

    public function setCredentials(array $credentials): void
    {
        $this->store(self::OPTION_CREDENTIALS, $credentials, self::CREDENTIAL_DEFAULTS);
    }

    public function hasValidCredentials(): bool
    {
        $creds = $this->getCredentials();

        return $creds['organization'] !== '' && $creds['project'] !== '' && $creds['token'] !== '';
    }

    public function getStaticConfig(): array
    {
        $config = $this->load(self::OPTION_STATIC_CONFIG, self::STATIC_DEFAULTS, 'static');
        $config['custom_routes'] = $this->normaliseList($config['custom_routes']);
        $config['domain_rewrites'] = $this->normaliseList($config['domain_rewrites']);
        $config['route_404'] = (string) $config['route_404'];

        return $config;
    }

    public function setStaticConfig(array $config): void
    {
        $this->store(self::OPTION_STATIC_CONFIG, $config, self::STATIC_DEFAULTS);
    }

    public function getSyncSettings(): array
    {
        return $this->load(self::OPTION_SYNC_SETTINGS, self::SYNC_DEFAULTS, 'sync');
    }

    public function setSyncSettings(array $settings): void
    {
        $this->store(self::OPTION_SYNC_SETTINGS, $settings, self::SYNC_DEFAULTS);
    }

    public function getSchedule(): array
    {
        $schedule = $this->load(self::OPTION_SCHEDULE, self::SCHEDULE_DEFAULTS, 'schedule');
        $schedule['types'] = array_values(array_filter((array) $schedule['types'], 'is_string'));
        $schedule['custom_routes'] = $this->normaliseList($schedule['custom_routes']);

        return $schedule;
    }

    public function setSchedule(array $schedule): void
    {
        $this->store(self::OPTION_SCHEDULE, $schedule, self::SCHEDULE_DEFAULTS);
    }

    public function getSearchSettings(): array
    {
        $search = $this->load(self::OPTION_SEARCH, self::SEARCH_DEFAULTS, 'search');
        $search['post_types'] = array_values(array_filter((array) $search['post_types'], 'is_string'));
        $search['excluded_post_types'] = array_values(array_filter((array) $search['excluded_post_types'], 'is_string'));

        return $search;
    }

    public function setSearchSettings(array $settings): void
    {
        $this->store(self::OPTION_SEARCH, $settings, self::SEARCH_DEFAULTS);
    }

    /**
     * Keys that come from the environment and cannot be changed in the UI.
     *
     * @return array<string, array<string>>
     */
    public function getOverriddenKeys(): array
    {
        return Environment::overriddenKeys();
    }

    /**
     * Load an option, fill defaults, apply environment overrides.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function load(string $option, array $defaults, string $group): array
    {
        $stored = get_option($option, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored, Environment::overridesFor($group));
    }

    /**
     * Store an option, keeping unknown keys out.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $defaults
     */
    private function store(string $option, array $values, array $defaults): void
    {
        $existing = get_option($option, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $merged = array_merge($defaults, $existing, $values);

        update_option($option, array_intersect_key($merged, $defaults));
    }

    /**
     * Accept a newline separated string (v1 format) or an array.
     *
     * @return array<string>
     */
    private function normaliseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode("\n", $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map(static fn($item): string => trim((string) $item), $value);

        return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
    }
}
