<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Quant\Api\ContentApiInterface;
use Quant\Api\ApiResponse;
use Quant\Sync\Redirect;
use Quant\Sync\RedirectSync;
use Quant\Sync\FileSyncResult;

#[CoversClass(RedirectSync::class)]
#[CoversClass(Redirect::class)]
final class RedirectSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ContentApiInterface $api;
    private RedirectSync $redirectSync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->redirectSync = new RedirectSync($this->api);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================
    // Redirect Value Object Tests
    // ============================

    #[Test]
    public function redirect_value_object_stores_properties(): void
    {
        $redirect = new Redirect('/old-page', '/new-page', 302);

        $this->assertSame('/old-page', $redirect->from);
        $this->assertSame('/new-page', $redirect->to);
        $this->assertSame(302, $redirect->statusCode);
    }

    #[Test]
    public function redirect_value_object_defaults_to_301(): void
    {
        $redirect = new Redirect('/old', '/new');

        $this->assertSame(301, $redirect->statusCode);
    }

    // ============================
    // Single Redirect Sync Tests
    // ============================

    #[Test]
    public function it_syncs_a_redirect_successfully(): void
    {
        $redirect = new Redirect('/old-page', '/new-page', 301);

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->with('/old-page', '/new-page', 301)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('/old-page', $result->path);
    }

    #[Test]
    public function it_syncs_a_302_redirect(): void
    {
        $redirect = new Redirect('/temp-redirect', '/destination', 302);

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->with('/temp-redirect', '/destination', 302)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_returns_failure_when_api_fails(): void
    {
        $redirect = new Redirect('/old-page', '/new-page');

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->andReturn(ApiResponse::error('Server error', 500));

        $result = $this->redirectSync->sync($redirect);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Server error', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_from_path_does_not_start_with_slash(): void
    {
        $redirect = new Redirect('old-page', '/new-page');

        $result = $this->redirectSync->sync($redirect);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('must start with /', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_from_path_is_external_url(): void
    {
        $redirect = new Redirect('https://example.com/old', '/new-page');

        $result = $this->redirectSync->sync($redirect);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('cannot be an external URL', $result->message);
    }

    #[Test]
    public function it_allows_external_url_in_to_path(): void
    {
        $redirect = new Redirect('/old-page', 'https://external.com/destination');

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->with('/old-page', 'https://external.com/destination', 301)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_returns_failure_when_from_path_is_empty(): void
    {
        $redirect = new Redirect('', '/new-page');

        $result = $this->redirectSync->sync($redirect);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('must start with /', $result->message);
    }

    // ============================
    // Batch Sync Tests
    // ============================

    #[Test]
    public function it_syncs_multiple_redirects_in_batch(): void
    {
        $redirects = [
            new Redirect('/page-1', '/new-page-1'),
            new Redirect('/page-2', '/new-page-2'),
            new Redirect('/page-3', '/new-page-3'),
        ];

        $this->api->shouldReceive('createRedirect')
            ->times(3)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->redirectSync->syncBatch($redirects);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->success);
        $this->assertTrue($results[1]->success);
        $this->assertTrue($results[2]->success);
    }

    #[Test]
    public function it_continues_batch_when_some_fail(): void
    {
        $redirects = [
            new Redirect('/page-1', '/new-page-1'),
            new Redirect('invalid-path', '/new-page-2'), // Invalid - no leading slash
            new Redirect('/page-3', '/new-page-3'),
        ];

        $this->api->shouldReceive('createRedirect')
            ->times(2) // Only 2 valid redirects should call API
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->redirectSync->syncBatch($redirects);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->success);
        $this->assertFalse($results[1]->success); // Invalid path
        $this->assertTrue($results[2]->success);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_batch(): void
    {
        $results = $this->redirectSync->syncBatch([]);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ============================
    // Redirection Plugin Tests
    // ============================

    #[Test]
    public function it_gets_redirects_from_redirection_plugin(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Create RedirectSync with plugin checker that returns true
        $redirectSync = new RedirectSync($this->api, fn() => true);

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('SELECT * FROM wp_redirection_items WHERE status = "enabled" AND action_type = "url" AND match_type = "url"');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'url' => '/old-page',
                    'action_data' => '/new-page',
                    'action_code' => 301,
                    'status' => 'enabled',
                ],
                (object) [
                    'id' => 2,
                    'url' => '/another-old',
                    'action_data' => '/another-new',
                    'action_code' => 302,
                    'status' => 'enabled',
                ],
            ]);

        $redirects = $redirectSync->getRedirectsFromPlugin('redirection');

        $this->assertCount(2, $redirects);
        $this->assertInstanceOf(Redirect::class, $redirects[0]);
        $this->assertSame('/old-page', $redirects[0]->from);
        $this->assertSame('/new-page', $redirects[0]->to);
        $this->assertSame(301, $redirects[0]->statusCode);
        $this->assertSame(302, $redirects[1]->statusCode);
    }

    #[Test]
    public function it_returns_empty_when_redirection_plugin_not_active(): void
    {
        // Create RedirectSync with plugin checker that returns false
        $redirectSync = new RedirectSync($this->api, fn() => false);

        $redirects = $redirectSync->getRedirectsFromPlugin('redirection');

        $this->assertIsArray($redirects);
        $this->assertEmpty($redirects);
    }

    #[Test]
    public function it_returns_empty_for_unsupported_plugin(): void
    {
        $redirects = $this->redirectSync->getRedirectsFromPlugin('unsupported-plugin');

        $this->assertIsArray($redirects);
        $this->assertEmpty($redirects);
    }

    #[Test]
    public function it_skips_regex_redirects_from_redirection_plugin(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        // Create RedirectSync with plugin checker that returns true
        $redirectSync = new RedirectSync($this->api, fn() => true);

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('SELECT * FROM wp_redirection_items WHERE status = "enabled" AND action_type = "url" AND match_type = "url"');

        // The query already filters out non-url match types
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'url' => '/valid-redirect',
                    'action_data' => '/destination',
                    'action_code' => 301,
                    'status' => 'enabled',
                ],
            ]);

        $redirects = $redirectSync->getRedirectsFromPlugin('redirection');

        $this->assertCount(1, $redirects);
    }

    // ============================
    // Custom Filter Tests
    // ============================

    #[Test]
    public function it_gets_redirects_from_custom_filter(): void
    {
        Functions\when('apply_filters')
            ->alias(function (string $hook, $value) {
                if ($hook === 'quant_custom_redirects') {
                    return [
                        new Redirect('/custom-old', '/custom-new', 301),
                        new Redirect('/another-custom', '/another-destination', 302),
                    ];
                }
                return $value;
            });

        $redirects = $this->redirectSync->getRedirectsFromFilter();

        $this->assertCount(2, $redirects);
        $this->assertInstanceOf(Redirect::class, $redirects[0]);
        $this->assertSame('/custom-old', $redirects[0]->from);
        $this->assertSame('/custom-new', $redirects[0]->to);
    }

    #[Test]
    public function it_returns_empty_when_filter_returns_empty(): void
    {
        Functions\when('apply_filters')
            ->justReturn([]);

        $redirects = $this->redirectSync->getRedirectsFromFilter();

        $this->assertIsArray($redirects);
        $this->assertEmpty($redirects);
    }

    #[Test]
    public function it_filters_non_redirect_objects_from_filter(): void
    {
        Functions\when('apply_filters')
            ->alias(function (string $hook, $value) {
                if ($hook === 'quant_custom_redirects') {
                    return [
                        new Redirect('/valid', '/destination'),
                        'not a redirect object',
                        ['from' => '/array', 'to' => '/not-valid'],
                        null,
                        new Redirect('/another-valid', '/another-destination'),
                    ];
                }
                return $value;
            });

        $redirects = $this->redirectSync->getRedirectsFromFilter();

        $this->assertCount(2, $redirects);
        $this->assertSame('/valid', $redirects[0]->from);
        $this->assertSame('/another-valid', $redirects[1]->from);
    }

    // ============================
    // Edge Cases
    // ============================

    #[Test]
    public function it_handles_redirect_with_query_string(): void
    {
        $redirect = new Redirect('/page?foo=bar', '/new-page');

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->with('/page?foo=bar', '/new-page', 301)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_handles_redirect_with_hash(): void
    {
        $redirect = new Redirect('/page#section', '/new-page');

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->with('/page#section', '/new-page', 301)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_includes_meta_data_in_successful_result(): void
    {
        $redirect = new Redirect('/old-page', '/new-page');

        $this->api->shouldReceive('createRedirect')
            ->once()
            ->andReturn(ApiResponse::success(['quant_revision' => 42]));

        $result = $this->redirectSync->sync($redirect);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('status_code', $result->meta);
        $this->assertSame(301, $result->meta['status_code']);
        $this->assertArrayHasKey('to', $result->meta);
        $this->assertSame('/new-page', $result->meta['to']);
    }
}
