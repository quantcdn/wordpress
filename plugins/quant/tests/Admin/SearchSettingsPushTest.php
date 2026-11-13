<?php

declare(strict_types=1);

namespace Quant\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Admin\SearchController;
use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Search\SearchPageRepository;
use Quant\Search\SearchPageSync;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Seeder;
use Quant\Tests\Support\WordPressStubs;
use WP_REST_Request;

/**
 * The search form is part of every page. Saving a change to where it goes does not change the pages already on
 * the edge, so the response says that a push is needed (Static mode).
 */
#[CoversClass(SearchController::class)]
final class SearchSettingsPushTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('sanitize_key')->alias(static fn($v): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function turning_on_the_search_form_option_needs_a_push(): void
    {
        $this->save(['enabled' => true, 'replace_wp_search' => false]);

        $this->assertTrue($this->save(['enabled' => true, 'replace_wp_search' => true])['push_needed']);
    }

    #[Test]
    public function changing_the_default_search_page_needs_a_push(): void
    {
        $this->save(['enabled' => true, 'replace_wp_search' => true, 'default_page' => 'site']);

        $this->assertTrue($this->save(['enabled' => true, 'replace_wp_search' => true, 'default_page' => 'books'])['push_needed']);
    }

    #[Test]
    public function other_search_settings_do_not_need_a_push(): void
    {
        $this->save(['enabled' => true, 'replace_wp_search' => true]);

        $this->assertFalse($this->save(['enabled' => true, 'replace_wp_search' => true, 'summary_source' => 'content'])['push_needed']);
    }

    #[Test]
    public function cdn_only_mode_never_asks_for_a_push(): void
    {
        $this->save(['enabled' => true, 'replace_wp_search' => false]);

        $this->assertFalse($this->save(['enabled' => true, 'replace_wp_search' => true], 'cdn_only')['push_needed']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function save(array $input, string $mode = 'static'): array
    {
        $settings = Mockery::mock(Settings::class)->makePartial();
        $settings->allows('isStaticMode')->andReturn($mode === 'static');

        $controller = new SearchController(
            Mockery::mock(ContentApiInterface::class),
            $settings,
            Mockery::mock(SearchPageRepository::class),
            Mockery::mock(SearchPageSync::class),
            Mockery::mock(SearchRecordBuilder::class),
            Mockery::mock(Seeder::class)
        );

        $request = Mockery::mock(WP_REST_Request::class);
        $request->allows('get_json_params')->andReturn($input);

        return $controller->saveSettings($request);
    }
}
