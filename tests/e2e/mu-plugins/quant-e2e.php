<?php
/**
 * Plugin Name: Quant E2E fixtures
 * Description: Points the QuantCDN plugin's portal at the mock API and registers test content types. Test environment only.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$quantE2eMockApi = getenv('QUANT_E2E_MOCK_API') ?: 'http://mock-api:4000';

add_filter('quant_portal_url', static fn() => $quantE2eMockApi);
add_filter('quant_cloud_api_url', static fn() => $quantE2eMockApi . '/api/v2');

add_action('init', static function (): void {
    register_post_type('book', [
        'label' => 'Books',
        'labels' => ['name' => 'Books', 'singular_name' => 'Book'],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'rewrite' => ['slug' => 'books'],
    ]);

    register_taxonomy('genre', ['book'], [
        'label' => 'Genres',
        'labels' => ['name' => 'Genres', 'singular_name' => 'Genre'],
        'public' => true,
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'genre'],
    ]);
});
