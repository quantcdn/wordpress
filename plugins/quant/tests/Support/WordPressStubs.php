<?php

declare(strict_types=1);

namespace Quant\Tests\Support;

use Brain\Monkey\Functions;
use Mockery;
use Quant\Core\Settings;

/**
 * Common WordPress function stubs for unit tests.
 */
trait WordPressStubs
{
    protected function stubCommonWordPressFunctions(string $home = 'https://example.com'): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('home_url')->alias(static fn(string $path = '', ?string $scheme = null): string => rtrim($home, '/') . $path);
        Functions\when('site_url')->alias(static fn(string $path = '', ?string $scheme = null): string => rtrim($home, '/') . $path);
        Functions\when('trailingslashit')->alias(static fn(string $v): string => rtrim($v, '/\\') . '/');
        Functions\when('untrailingslashit')->alias(static fn(string $v): string => rtrim($v, '/\\'));
        Functions\when('user_trailingslashit')->alias(static fn(string $v, string $type = ''): string => rtrim($v, '/') . '/');
        Functions\when('add_query_arg')->alias(static function ($key, $value = null, $url = null): string {
            if (is_array($key)) {
                $args = $key;
                $url = (string) $value;
            } else {
                $args = [$key => $value];
                $url = (string) $url;
            }
            $separator = str_contains($url, '?') ? '&' : '?';
            return $url . $separator . http_build_query($args);
        });
        Functions\when('__')->returnArg();
        Functions\when('_e')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_key')->alias(static fn($v): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)) ?? '');
        Functions\when('sanitize_title')->alias(static fn($v): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)) ?? '', '-'));
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_strip_all_tags')->alias(static fn($v, $breaks = false): string => trim(strip_tags((string) $v)));
        Functions\when('is_wp_error')->alias(static fn($thing): bool => $thing instanceof \WP_Error);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_generate_password')->justReturn('abcdef1234567890');
        Functions\when('wp_check_filetype')->alias(static fn(string $f): array => ['type' => match (strtolower(pathinfo($f, PATHINFO_EXTENSION))) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => '',
        }, 'ext' => pathinfo($f, PATHINFO_EXTENSION)]);
    }

    /**
     * A Settings mock with sensible defaults; override per test with shouldReceive.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $static
     * @param array<string, mixed> $sync
     * @param array<string, mixed> $search
     */
    protected function mockSettings(
        array $credentials = [],
        array $static = [],
        array $sync = [],
        array $search = [],
        string $mode = Settings::MODE_STATIC
    ): Settings&Mockery\MockInterface {
        $settings = Mockery::mock(Settings::class);

        $credentials = array_merge(Settings::CREDENTIAL_DEFAULTS, [
            'organization' => 'org',
            'project' => 'proj',
            'token' => 'tok',
        ], $credentials);
        $credentials['endpoint'] = rtrim((string) $credentials['endpoint'], '/');

        $settings->shouldReceive('getCredentials')->andReturn($credentials)->byDefault();
        $settings->shouldReceive('hasValidCredentials')->andReturn(
            $credentials['organization'] !== '' && $credentials['project'] !== '' && $credentials['token'] !== ''
        )->byDefault();
        $settings->shouldReceive('getStaticConfig')->andReturn(array_merge(Settings::STATIC_DEFAULTS, $static))->byDefault();
        $settings->shouldReceive('getSyncSettings')->andReturn(array_merge(Settings::SYNC_DEFAULTS, $sync))->byDefault();
        $settings->shouldReceive('getSearchSettings')->andReturn(array_merge(Settings::SEARCH_DEFAULTS, $search))->byDefault();
        $settings->shouldReceive('getSchedule')->andReturn(Settings::SCHEDULE_DEFAULTS)->byDefault();
        $settings->shouldReceive('getMode')->andReturn($mode)->byDefault();
        $settings->shouldReceive('isStaticMode')->andReturn($mode === Settings::MODE_STATIC)->byDefault();
        $settings->shouldReceive('isCdnOnlyMode')->andReturn($mode === Settings::MODE_CDN_ONLY)->byDefault();
        $settings->shouldReceive('getOverriddenKeys')->andReturn([])->byDefault();

        return $settings;
    }

    protected function makePost(int $id, string $type = 'post', string $status = 'publish', string $name = ''): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = $type;
        $post->post_status = $status;
        $post->post_name = $name !== '' ? $name : "post-{$id}";
        $post->post_title = "Post {$id}";
        $post->post_author = 1;
        $post->post_modified_gmt = '2026-01-15 10:00:00';
        $post->post_content = "<p>Content of post {$id}</p>";

        return $post;
    }

    protected function makeTerm(int $id, string $taxonomy = 'category', string $name = 'News', int $count = 3): \WP_Term
    {
        $term = new \WP_Term();
        $term->term_id = $id;
        $term->taxonomy = $taxonomy;
        $term->name = $name;
        $term->slug = strtolower($name);
        $term->count = $count;

        return $term;
    }
}
