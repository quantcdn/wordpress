<?php

declare(strict_types=1);

namespace Quant\Tests\Api;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\QuantContentApi;
use Quant\Tests\Support\FakeHttpClient;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(QuantContentApi::class)]
#[CoversClass(ApiResponse::class)]
final class QuantContentApiTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private FakeHttpClient $http;
    private QuantContentApi $api;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->http = new FakeHttpClient();
        $this->api = new QuantContentApi($this->mockSettings(credentials: [
            'organization' => 'acme',
            'project' => 'site',
            'token' => 'secret',
            'endpoint' => 'https://api.quantcdn.io/',
        ]), $this->http);

        $this->file = tempnam(sys_get_temp_dir(), 'quant');
        file_put_contents($this->file, 'hello');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_pings_with_the_project_headers(): void
    {
        $this->http->respondOk(['project' => ['name' => 'site'], 'config' => ['search_enabled' => true]]);

        $response = $this->api->ping();

        $this->assertTrue($response->success);
        $this->assertSame('GET', $this->http->last()['method']);
        $this->assertSame('https://api.quantcdn.io/v1/ping', $this->http->last()['url']);
        $this->assertSame(['Quant-Token' => 'secret', 'Quant-Project' => 'site', 'Quant-Customer' => 'acme'], $this->http->last()['headers']);
        $this->assertTrue($response->data['config']['search_enabled']);
    }

    #[Test]
    public function it_publishes_markup_to_the_v1_root_with_filtered_info(): void
    {
        $this->http->respondOk(['url' => '/about', 'attachments' => []]);

        $response = $this->api->publish('/about/', '<html/>', [
            'author_name' => str_repeat('a', 200),
            'source' => 'wordpress',
            'log' => 'changed',
            'post_type' => 'page',
            'title' => '',
        ], [
            'content_timestamp' => 1700000000,
            'search_record' => ['title' => 'About'],
            'proxy_override' => true,
            'headers' => ['content_type' => 'application/xml'],
        ]);

        $call = $this->http->last();
        $this->assertTrue($response->success);
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.quantcdn.io/v1', $call['url']);
        $this->assertSame('/about', $call['body']['url']);
        $this->assertSame('<html/>', $call['body']['content']);
        $this->assertTrue($call['body']['published']);
        $this->assertTrue($call['body']['find_attachments']);
        $this->assertSame(1700000000, $call['body']['content_timestamp']);
        $this->assertSame(['title' => 'About'], $call['body']['search_record']);
        $this->assertTrue($call['body']['proxy_override']);
        $this->assertSame(['content_type' => 'application/xml'], $call['body']['headers']);
        $this->assertSame(['author_name', 'log', 'source'], array_keys($call['body']['info']));
        $this->assertSame(128, strlen($call['body']['info']['author_name']));
    }

    #[Test]
    public function it_omits_info_when_nothing_valid_remains(): void
    {
        $this->http->respondOk();

        $this->api->publish('/x', 'y', ['post_type' => 'page']);

        $this->assertArrayNotHasKey('info', $this->http->last()['body']);
    }

    #[Test]
    public function it_unpublishes_with_a_patch_and_the_quant_url_header(): void
    {
        $this->http->respondOk(['published' => false]);

        $response = $this->api->unpublish('/blog/post/');

        $this->assertTrue($response->success);
        $this->assertSame('PATCH', $this->http->last()['method']);
        $this->assertSame('https://api.quantcdn.io/v1/unpublish', $this->http->last()['url']);
        $this->assertSame('/blog/post', $this->http->last()['headers']['Quant-Url']);
    }

    #[Test]
    public function it_treats_already_unpublished_content_as_success(): void
    {
        $this->http->respondWith(['code' => 404, 'body' => json_encode(['error' => true, 'errorMsg' => 'Resource is already unpublished'])]);

        $response = $this->api->unpublish('/gone');

        $this->assertTrue($response->success);
        $this->assertSame('Already unpublished', $response->message);
    }

    #[Test]
    public function it_uploads_files_as_multipart_with_the_file_url_header(): void
    {
        $this->http->respondOk(['md5' => 'abc']);

        $response = $this->api->uploadFile('/wp-content/uploads/a.txt/', $this->file);

        $call = $this->http->last();
        $this->assertTrue($response->success);
        $this->assertSame('UPLOAD', $call['method']);
        $this->assertSame('https://api.quantcdn.io/v1', $call['url']);
        $this->assertSame('/wp-content/uploads/a.txt', $call['headers']['Quant-File-Url']);
        $this->assertSame($this->file, $call['body']['file']);
        $this->assertSame('data', $call['body']['field']);
    }

    #[Test]
    public function it_treats_an_unchanged_file_as_success(): void
    {
        $this->http->respondWith(['code' => 400, 'body' => json_encode(['error' => true, 'errorMsg' => 'MD5 already matches existing file.'])]);

        $this->assertTrue($this->api->uploadFile('/a.txt', $this->file)->success);
    }

    #[Test]
    public function it_fails_an_upload_for_a_missing_file(): void
    {
        $response = $this->api->uploadFile('/a.txt', '/nope/missing.txt');

        $this->assertFalse($response->success);
        $this->assertSame([], $this->http->calls);
    }

    #[Test]
    public function it_creates_redirects(): void
    {
        $this->http->respondOk();

        $this->api->createRedirect('old-page/', 'https://other.com/landing', 302);

        $call = $this->http->last();
        $this->assertSame('https://api.quantcdn.io/v1/redirect', $call['url']);
        $this->assertSame([
            'url' => '/old-page',
            'redirect_url' => 'https://other.com/landing',
            'redirect_http_code' => 302,
            'published' => true,
        ], $call['body']);
    }

    #[Test]
    public function it_defaults_odd_redirect_codes_to_301_and_refuses_self_redirects(): void
    {
        $this->http->respondOk();
        $this->api->createRedirect('/a', '/b/', 418);
        $this->assertSame(301, $this->http->last()['body']['redirect_http_code']);
        $this->assertSame('/b', $this->http->last()['body']['redirect_url']);

        $this->assertFalse($this->api->createRedirect('/same/', '/same')->success);
        $this->assertCount(1, $this->http->calls);
    }

    #[Test]
    public function it_purges_both_slash_variants_but_not_for_wildcards_or_queries(): void
    {
        $this->http->alwaysRespond(['code' => 200, 'body' => '{}']);

        $response = $this->api->purge(['/blog/post/', '/*', '/?p=1', '/', 'docs/*']);

        $this->assertTrue($response->success);
        $urls = array_map(static fn(array $c): string => $c['headers']['Quant-Url'], $this->http->calls);
        $this->assertSame(['/blog/post', '/blog/post/', '/*', '/?p=1', '/', '/docs/*'], $urls);
        $this->assertSame('https://api.quantcdn.io/v1/purge', $this->http->last()['url']);
    }

    #[Test]
    public function it_reports_purge_failures(): void
    {
        $this->http->respondOk()->respondWith(['code' => 500, 'body' => json_encode(['message' => 'edge down'])]);

        $response = $this->api->purge('/x');

        $this->assertFalse($response->success);
        $this->assertSame('Failed to purge: /x/: edge down', $response->message);
    }

    #[Test]
    public function it_talks_to_the_search_endpoints(): void
    {
        $this->http->alwaysRespond(['code' => 200, 'body' => json_encode(['index' => ['entries' => 3]])]);

        $this->assertSame(3, $this->api->searchStatus()->data['index']['entries']);
        $this->assertSame('https://api.quantcdn.io/v1/search', $this->http->last()['url']);

        $this->api->sendSearchRecords(['a' => ['title' => 'A'], 'b' => ['title' => 'B']]);
        $this->assertSame('POST', $this->http->last()['method']);
        $this->assertSame([['title' => 'A'], ['title' => 'B']], $this->http->last()['body']);

        $this->assertSame('No records', $this->api->sendSearchRecords([])->message);

        $this->api->clearSearchIndex();
        $this->assertSame('DELETE', $this->http->last()['method']);
        $this->assertSame('https://api.quantcdn.io/v1/search/all', $this->http->last()['url']);

        $this->api->addSearchFacets(['lang_code', 'content_type', 'lang_code', 7]);
        $this->assertSame('https://api.quantcdn.io/v1/search/facet', $this->http->last()['url']);
        $this->assertSame(['lang_code', 'content_type'], $this->http->last()['body']);

        $this->api->urlMeta(['/a/', 'b']);
        $this->assertSame('https://api.quantcdn.io/v1/url-meta', $this->http->last()['url']);
        $this->assertSame(['Quant-Url' => ['/a', '/b']], $this->http->last()['body']);
    }

    #[Test]
    public function it_parses_error_bodies(): void
    {
        $this->http->respondWith(['code' => 403, 'body' => json_encode(['error' => true, 'errorMsg' => 'Invalid credentials'])]);
        $forbidden = $this->api->ping();
        $this->assertFalse($forbidden->success);
        $this->assertSame('Invalid credentials', $forbidden->message);
        $this->assertSame(403, $forbidden->statusCode);

        $this->http->respondWith(['code' => 500, 'body' => 'not json']);
        $this->assertSame('HTTP 500', $this->api->ping()->message);

        $this->http->respondWith(['code' => 200, 'body' => json_encode(['error' => 'Project deleted'])]);
        $this->assertSame('Project deleted', $this->api->ping()->message);

        $this->http->respondWith(['code' => 0, 'body' => '', 'error' => 'cURL error 7']);
        $transport = $this->api->ping();
        $this->assertFalse($transport->success);
        $this->assertSame('cURL error 7', $transport->message);
        $this->assertSame(0, $transport->statusCode);
    }
}
