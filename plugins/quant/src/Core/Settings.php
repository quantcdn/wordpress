<?php

declare(strict_types=1);

namespace Quant\Core;

class Settings
{
    private const OPTION_SETTINGS = 'quant_settings';
    private const OPTION_MODE = 'quant_mode';
    private const OPTION_CREDENTIALS = 'quant_credentials';
    private const OPTION_STATIC_CONFIG = 'quant_static_config';
    private const OPTION_SYNC_SETTINGS = 'quant_sync_settings';
    private const OPTION_SCHEDULE = 'quant_schedule';

    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $settings = $this->all();
        $settings[$key] = $value;
        update_option(self::OPTION_SETTINGS, $settings);
        $this->cache = $settings;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = get_option(self::OPTION_SETTINGS, []);
        }
        return $this->cache;
    }

    public function getMode(): string
    {
        return get_option(self::OPTION_MODE, 'static');
    }

    public function setMode(string $mode): void
    {
        update_option(self::OPTION_MODE, $mode);
    }

    public function isStaticMode(): bool
    {
        return $this->getMode() === 'static';
    }

    public function isCdnOnlyMode(): bool
    {
        return $this->getMode() === 'cdn_only';
    }

    public function getCredentials(): array
    {
        return get_option(self::OPTION_CREDENTIALS, [
            'organization' => '',
            'project' => '',
            'token' => '',
            'endpoint' => 'https://api.quantcdn.io',
        ]);
    }

    public function setCredentials(array $credentials): void
    {
        update_option(self::OPTION_CREDENTIALS, $credentials);
    }

    public function hasValidCredentials(): bool
    {
        $creds = $this->getCredentials();
        return !empty($creds['organization'])
            && !empty($creds['project'])
            && !empty($creds['token']);
    }

    public function getStaticConfig(): array
    {
        return get_option(self::OPTION_STATIC_CONFIG, [
            'webserver_url' => '',
            'custom_routes' => [],
            'route_404' => '/404',
            'domain_rewrites' => [],
        ]);
    }

    public function setStaticConfig(array $config): void
    {
        update_option(self::OPTION_STATIC_CONFIG, $config);
    }

    public function getSyncSettings(): array
    {
        return get_option(self::OPTION_SYNC_SETTINGS, [
            'auto_sync' => true,
            'auto_purge' => true,
            'sync_drafts' => false,
        ]);
    }

    public function setSyncSettings(array $settings): void
    {
        update_option(self::OPTION_SYNC_SETTINGS, $settings);
    }

    public function getSchedule(): array
    {
        return get_option(self::OPTION_SCHEDULE, [
            'enabled' => false,
            'interval' => 'hourly',
            'types' => ['post', 'page'],
        ]);
    }

    public function setSchedule(array $schedule): void
    {
        update_option(self::OPTION_SCHEDULE, $schedule);
    }
}
