<?php

namespace Quant;
use Quant\Client;

class Settings
{
    /**
     * Setup required hooks for the Settings
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    /**
     * Register settings & fields
     *
     * @return void
     */
    public static function register()
    {
        $key = QUANT_SETTINGS_KEY;
        $seedKey = QUANT_SEED_KEY;

        register_setting($key, $key, [__CLASS__, 'sanitize']);
        register_setting($seedKey, $seedKey, ["\Quant\Seed", 'seedSubmit']);

        add_settings_section('general', 'General', '__return_empty_string', $key);
        add_settings_section('seed', 'Seed', '__return_empty_string', $key);
        add_settings_section('api', 'API', '__return_empty_string', $key);

        $options = get_option(QUANT_SETTINGS_KEY);

        /**
         * General settings form fields.
         */
        add_settings_field('quant_enabled', 'Quant Enabled', ['Quant\Field', 'checkbox'], $key, 'general', [
            'name' => "{$key}[enabled]",
            'description' => 'Enable QuantCDN integration',
            'value' => $options['enabled'] ?? 0,
        ]);

        add_settings_field('quant_webserver_url', 'Webserver URL', ['Quant\Field', 'url'], $key, 'general', [
            'name' => "{$key}[webserver_url]",
            'placeholder' => 'http://localhost',
            'description' => 'The local webserver URL for HTTP requests',
            'value' => $options['webserver_url'] ?? 'http://localhost',
        ]);

        add_settings_field('quant_webserver_host', 'Hostname', ['Quant\Field', 'text'], $key, 'general', [
            'name' => "{$key}[webserver_host]",
            'placeholder' => 'www.example.com',
            'description' => 'The hostname your webserver expects',
            'value' => $options['webserver_host'] ?? 'www.example.com',
        ]);

        /**
         * API settings form fields.
         */
        add_settings_field('quant_api_endpoint', 'API Endpoint', ['Quant\Field', 'url'], $key, 'api', [
            'name' => "{$key}[api_endpoint]",
            'placeholder' => 'https://api.quantcdn.io',
            'value' => $options['api_endpoint'] ?? 'https://api.quantcdn.io',
        ]);

        add_settings_field('quant_api_account', 'API Account', ['Quant\Field', 'text'], $key, 'api', [
            'name' => "{$key}[api_account]",
            'value' => $options['api_account'] ?? '',
        ]);

        add_settings_field('quant_api_project', 'API Project', ['Quant\Field', 'text'], $key, 'api', [
            'name' => "{$key}[api_project]",
            'value' => $options['api_project'] ?? '',
        ]);

        add_settings_field('quant_api_password', 'API Token', ['Quant\Field', 'text'], $key, 'api', [
            'name' => "{$key}[api_token]",
            'value' => $options['api_token'] ?? '',
        ]);

        /**
         * Seed fields
         */
        $seedOptions = get_option(QUANT_SEED_KEY);

        add_settings_section('seed', 'Seed content', '__return_empty_string', $seedKey);

        add_settings_field('seed_posts', 'Posts', ['Quant\Field', 'checkbox'], $seedKey, 'seed', [
            'name' => "{$seedKey}[posts]",
            'description' => 'Post content',
            'value' => $seedOptions['posts'] ?? 0,
        ]);

        add_settings_field('seed_pages', 'Pages', ['Quant\Field', 'checkbox'], $seedKey, 'seed', [
            'name' => "{$seedKey}[pages]",
            'description' => 'Page content',
            'value' => $seedOptions['pages'] ?? 0,
        ]);

        add_settings_field('seed_theme_assets', 'Pages', ['Quant\Field', 'checkbox'], $seedKey, 'seed', [
            'name' => "{$seedKey}[theme_assets]",
            'description' => 'Theme assets',
            'value' => $seedOptions['theme_assets'] ?? 0,
        ]);

    }

    /**
     * Sanitize user input
     *
     * @var array $input
     * @return array
     */
    public static function sanitize($input)
    {
        // @todo: Sanitization
        return $input;
    }

}
