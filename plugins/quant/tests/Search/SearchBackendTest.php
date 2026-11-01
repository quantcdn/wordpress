<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Search\SearchBackend;

#[CoversClass(SearchBackend::class)]
final class SearchBackendTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('untrailingslashit')->alias(static fn(string $value): string => rtrim($value, '/\\'));
        putenv('QUANT_SEARCH_ENDPOINT');
    }

    protected function tearDown(): void
    {
        putenv('QUANT_SEARCH_ENDPOINT');
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_resolves_algolia_from_the_project_record(): void
    {
        $api = Mockery::mock(ContentApiInterface::class);
        $api->shouldReceive('ping')->andReturn(ApiResponse::success(['config' => [
            'search_enabled' => true,
            'search_index' => ['algolia_application_id' => 'APP', 'algolia_read_key' => 'KEY', 'algolia_index' => 'org_proj'],
            'search_config' => ['algolia' => ['custom_ranking' => ['desc(date)']]],
        ]]));
        $api->shouldNotReceive('searchStatus');

        $backend = SearchBackend::resolve($api);

        $this->assertSame('algolia', $backend['backend']);
        $this->assertSame(['APP', 'KEY', 'org_proj', '', ''], [$backend['application_id'], $backend['read_key'], $backend['index'], $backend['endpoint'], $backend['sort_by']]);
        $this->assertSame([], $backend['facets_declared']);
        $this->assertTrue(SearchBackend::isComplete($backend));
        $this->assertSame('', SearchBackend::withEndpoint($backend)['endpoint']);
    }

    #[Test]
    public function it_resolves_typesense_on_quantgov_from_the_index_stats(): void
    {
        $api = Mockery::mock(ContentApiInterface::class);
        $api->shouldReceive('ping')->andReturn(ApiResponse::success(['config' => [
            'search_enabled' => true,
            'platform_mode' => 'aws',
            'search_index' => ['algolia_application_id' => 'STALE', 'algolia_read_key' => 'STALE', 'algolia_index' => 'org_proj'],
            'search_config' => ['algolia' => ['custom_ranking' => ['asc(title)', 'desc(date)', 'asc(weight)']]],
        ]]));
        $api->shouldReceive('searchStatus')->andReturn(ApiResponse::success([
            'readOnlyKey' => ['value' => 'TSKEY', 'collections' => ['org_proj']],
            'index' => ['name' => 'org_proj', 'entries' => 21],
            'settings' => ['attributesForFaceting' => ['url', 'content_type', 'category_en', 'category_en']],
        ]));

        $backend = SearchBackend::resolve($api);

        $this->assertSame('typesense', $backend['backend']);
        $this->assertSame(['', 'TSKEY', 'org_proj'], [$backend['application_id'], $backend['read_key'], $backend['index']]);
        $this->assertSame('_text_match:desc,title:asc,date:desc', $backend['sort_by']);
        $this->assertSame(['url', 'content_type', 'category_en'], $backend['facets_declared']);
        $this->assertSame('https://search.quantgov.cloud', SearchBackend::withEndpoint($backend)['endpoint']);
    }

    #[Test]
    public function it_falls_back_to_the_index_name_when_the_key_lists_no_collection(): void
    {
        $api = Mockery::mock(ContentApiInterface::class);
        $api->shouldReceive('ping')->andReturn(ApiResponse::success(['config' => ['search_enabled' => true, 'platform_mode' => 'aws']]));
        $api->shouldReceive('searchStatus')->andReturn(ApiResponse::success(['readOnlyKey' => ['value' => 'K'], 'index' => ['name' => 'from_index']]));

        $this->assertSame('from_index', SearchBackend::resolve($api)['index']);
    }

    #[Test]
    public function it_reports_an_incomplete_backend_when_the_stats_call_fails(): void
    {
        $api = Mockery::mock(ContentApiInterface::class);
        $api->shouldReceive('ping')->andReturn(ApiResponse::success(['config' => ['search_enabled' => true, 'platform_mode' => 'aws']]));
        $api->shouldReceive('searchStatus')->andReturn(ApiResponse::error('down', 503));

        $backend = SearchBackend::resolve($api);

        $this->assertNotNull($backend);
        $this->assertFalse(SearchBackend::isComplete($backend));
    }

    #[Test]
    public function search_is_unavailable_when_disabled_or_unreachable(): void
    {
        $disabled = Mockery::mock(ContentApiInterface::class);
        $disabled->shouldReceive('ping')->andReturn(ApiResponse::success(['config' => ['search_enabled' => false]]));
        $down = Mockery::mock(ContentApiInterface::class);
        $down->shouldReceive('ping')->andReturn(ApiResponse::error('timeout', 0));

        $this->assertNull(SearchBackend::resolve($disabled));
        $this->assertNull(SearchBackend::resolve($down));
        $this->assertFalse(SearchBackend::isComplete(null));
    }

    #[Test]
    public function the_typesense_endpoint_comes_from_the_environment_or_a_filter(): void
    {
        putenv('QUANT_SEARCH_ENDPOINT=https://search.stage.quantgov.cloud/');
        $this->assertSame('https://search.stage.quantgov.cloud', SearchBackend::typesenseEndpoint());

        Functions\expect('apply_filters')->once()->with('quant_search_typesense_endpoint', 'https://search.stage.quantgov.cloud/')->andReturn('http://localhost:8108');
        $this->assertSame('http://localhost:8108', SearchBackend::typesenseEndpoint());
    }

    #[Test]
    public function sort_by_keeps_relevance_first_and_ignores_invalid_rules(): void
    {
        $this->assertSame('', SearchBackend::sortBy([]));
        $this->assertSame('', SearchBackend::sortBy(['title', 'asc(bad field)', 7]));
        $this->assertSame('_text_match:desc,weight:desc', SearchBackend::sortBy(['nonsense', ' desc(weight) ']));
    }

    #[Test]
    public function undeclared_facets_are_dropped_only_when_the_list_is_known(): void
    {
        $facets = [['attribute' => 'category_en'], ['attribute' => 'post_tag_en'], ['attribute' => 'content_type']];

        $this->assertSame($facets, SearchBackend::declaredFacets($facets, null));
        $this->assertSame($facets, SearchBackend::declaredFacets($facets, ['facets_declared' => []]));
        $this->assertSame(
            [['attribute' => 'category_en'], ['attribute' => 'content_type']],
            SearchBackend::declaredFacets($facets, ['facets_declared' => ['content_type', 'category_en']])
        );
    }
}
