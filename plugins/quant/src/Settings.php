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
        $cronKey = QUANT_CRON_SETTINGS_KEY;

        register_setting($key, $key, [__CLASS__, 'sanitize']);
        register_setting($seedKey, $seedKey, [__CLASS__, 'sanitize']);
        register_setting($cronKey, $cronKey, [__CLASS__, 'sanitize']);

        add_settings_section('general', 'General', '__return_empty_string', $key);
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

        add_settings_field('quant_disable_tls_verify', 'Disable SSL verify', ['Quant\Field', 'checkbox'], $key, 'general', [
            'name' => "{$key}[disable_tls_verify]",
            'description' => 'Optionally disable TLS verification.',
            'value' => $options['disable_tls_verify'] ?? 0,
        ]);

        add_settings_field('quant_http_request_timeout', 'HTTP request timeout', ['Quant\Field', 'text'], $key, 'general', [
            'name' => "{$key}[http_request_timeout]",
            'description' => 'Optionally increase the HTTP request timeout on slower servers.',
            'value' => $options['http_request_timeout'] ?? 15,
            'placeholder' => '30'
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
            'value' => !empty($options['webserver_host']) ? $options['webserver_host'] : 'localhost',
        ]);

        /**
         * API settings form fields.
         */
        add_settings_field('quant_api_endpoint', 'API Endpoint', ['Quant\Field', 'url'], $key, 'api', [
            'name' => "{$key}[api_endpoint]",
            'placeholder' => 'https://api.quantcdn.io',
            'description' => 'e.g https://api.quantcdn.io',
            'value' => $options['api_endpoint'] ?? 'https://api.quantcdn.io',
        ]);

        add_settings_field('quant_api_account', 'API Customer', ['Quant\Field', 'text'], $key, 'api', [
            'name' => "{$key}[api_account]",
            'value' => $options['api_account'] ?? '',
            'placeholder' => 'Retrieve your customer identifier from the Quant Dashboard (integrations page)'
        ]);

        add_settings_field('quant_api_project', 'API Project', ['Quant\Field', 'text'], $key, 'api', [
            'name' => "{$key}[api_project]",
            'value' => $options['api_project'] ?? '',
            'placeholder' => 'Retrieve your project name from the Quant Dashboard (integrations page)'
        ]);

        add_settings_field('quant_api_password', 'API Token', ['Quant\Field', 'password'], $key, 'api', [
            'name' => "{$key}[api_token]",
            'value' => $options['api_token'] ?? '',
            'placeholder' => 'Retrieve your token from the Quant Dashboard (integrations page)'
        ]);

        /**
         * Seed fields
         */
        $seedOptions = get_option($seedKey);

        add_settings_section('seed', 'Seed content', '__return_empty_string', $seedKey);

        add_settings_field('seed_404_route', '404 path', ['Quant\Field', 'text'], $seedKey, 'seed', [
            'name' => "{$seedKey}[404_route]",
            'description' => 'Route to use for 404 error pages',
            'value' => $seedOptions['404_route'] ?? '/404',
            'placeholder' => '',
        ]);

        add_settings_field('seed_custom_routes', 'Custom routes', ['Quant\Field', 'textarea'], $seedKey, 'seed', [
            'name' => "{$seedKey}[custom_routes]",
            'description' => 'Enter custom content or file routes (e.g /path/to/content or /path/to/file.css)',
            'value' => $seedOptions['custom_routes'] ?? '/robots.txt',
            'placeholder' => '',
        ]);

        add_settings_field('seed_domains_strip', 'Relative rewrite', ['Quant\Field', 'textarea'], $seedKey, 'seed', [
            'name' => "{$seedKey}[domains_strip]",
            'description' => 'Optional domains (e.g www.example.com) to rewrite as relative',
            'value' => $seedOptions['domains_strip'] ?? '',
            'placeholder' => '',
        ]);


        /**
         * Cron settings fields
         */
        $cronOptions = get_option($cronKey);

        add_settings_section('cron', 'Cron jobs', '__return_empty_string', $cronKey);

        add_settings_field('cron_enabled', 'Cron enabled', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_enabled]",
            'description' => 'Enable cron jobs',
            'value' => $cronOptions['cron_enabled'] ?? 0,
        ]);

        add_settings_field('cron_schedule', 'Schedule', ['Quant\Field', 'text'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_schedule]",
            'description' => 'Enter a cron schedule (e.g daily, hourly or twicedaily)',
            'value' => $cronOptions['cron_schedule'] ?? 'daily',
            'placeholder' => 'daily'
        ]);

        add_settings_field('cron_home', 'Homepage', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_home]",
            'description' => 'Homepage',
            'value' => $cronOptions['cron_home'] ?? 0,
        ]);

        add_settings_field('cron_posts', 'Posts', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_posts]",
            'description' => 'All posts',
            'value' => $cronOptions['cron_posts'] ?? 0,
        ]);

        add_settings_field('cron_pages', 'Pages', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_pages]",
            'description' => 'All pages',
            'value' => $cronOptions['cron_pages'] ?? 0,
        ]);

        add_settings_field('cron_categories', 'Categories', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_categories]",
            'description' => 'Categories',
            'value' => $cronOptions['cron_categories'] ?? 0,
        ]);

        add_settings_field('cron_tags', 'Tags', ['Quant\Field', 'checkbox'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_tags]",
            'description' => 'Tags',
            'value' => $cronOptions['cron_tags'] ?? 0,
        ]);

        add_settings_field('cron_custom_routes', 'Custom routes', ['Quant\Field', 'textarea'], $cronKey, 'cron', [
            'name' => "{$cronKey}[cron_custom_routes]",
            'description' => 'Enter custom routes (e.g /path/to/route)',
            'value' => $cronOptions['cron_custom_routes'] ?? '/robots.txt',
            'placeholder' => ''
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
