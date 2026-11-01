<?php

declare(strict_types=1);

namespace Quant\Tests\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Quant\Auth\OAuthController;
use Quant\Core\Settings;

#[CoversClass(OAuthController::class)]
class OAuthControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OAuthController $controller;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Setup common function stubs
        Functions\when('admin_url')->alias(function (string $path = '') {
            return 'http://localhost/wp-admin/' . $path;
        });

        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);

        $this->settings = Mockery::mock(Settings::class);
        $this->settings->shouldReceive('getCredentials')->andReturn([
            'organization' => '',
            'project' => '',
            'token' => '',
            'endpoint' => 'https://api.quantcdn.io',
        ])->byDefault();

        $this->controller = new OAuthController($this->settings);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(OAuthController::class, $this->controller);
    }

    #[Test]
    public function check_permission_returns_bool(): void
    {
        $result = $this->controller->checkPermission();
        $this->assertIsBool($result);
    }

    #[Test]
    public function initiate_oauth_returns_redirect_url(): void
    {
        $result = $this->controller->initiateOAuth();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('redirect_url', $result);
        $this->assertStringContainsString('oauth/authorize', $result['redirect_url']);
        $this->assertStringContainsString('client_id=wordpress-quant', $result['redirect_url']);
        $this->assertStringContainsString('response_type=code', $result['redirect_url']);
        $this->assertStringContainsString('state=', $result['redirect_url']);
        $this->assertStringContainsString('scope=full_access', $result['redirect_url']);
    }

    #[Test]
    public function initiate_oauth_includes_callback_url(): void
    {
        $result = $this->controller->initiateOAuth();

        $this->assertStringContainsString('redirect_uri=', $result['redirect_url']);
        $this->assertStringContainsString('quant_oauth_callback', $result['redirect_url']);
    }

    #[Test]
    public function get_status_returns_not_connected_when_no_token(): void
    {
        Functions\when('get_option')->justReturn(false);

        $result = $this->controller->getStatus();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('connected', $result);
        $this->assertFalse($result['connected']);
        $this->assertArrayHasKey('has_project', $result);
        $this->assertFalse($result['has_project']);
    }

    #[Test]
    public function get_status_returns_connected_when_valid_token_exists(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            return match ($option) {
                'quant_oauth_token' => [
                    'access_token' => 'test-token',
                    'expires_at' => time() + 3600,
                ],
                'quant_oauth_user' => [
                    'email' => 'test@example.com',
                    'name' => 'Test User',
                ],
                'quant_oauth_organizations' => [],
                default => $default,
            };
        });

        $this->settings->shouldReceive('getCredentials')->andReturn([
            'organization' => 'test-org',
            'project' => 'test-project',
            'token' => 'test-token',
            'endpoint' => 'https://api.quantcdn.io',
        ]);

        $result = $this->controller->getStatus();

        $this->assertIsArray($result);
        $this->assertTrue($result['connected']);
        $this->assertTrue($result['has_project']);
    }

    #[Test]
    public function disconnect_clears_oauth_data(): void
    {
        Functions\when('delete_option')->justReturn(true);

        $this->settings->shouldReceive('getCredentials')->andReturn([
            'organization' => '',
            'project' => '',
            'token' => '',
            'endpoint' => 'https://api.quantcdn.io',
        ]);
        $this->settings->shouldReceive('setCredentials')->once();

        $result = $this->controller->disconnect();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function get_projects_returns_error_when_not_connected(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('__')->alias(function ($text) {
            return $text;
        });

        $result = $this->controller->getProjects();

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    #[Test]
    public function get_projects_returns_error_when_token_expired(): void
    {
        Functions\when('get_option')->alias(function ($option, $default = false) {
            return match ($option) {
                'quant_oauth_token' => [
                    'access_token' => 'test-token',
                    'expires_at' => time() - 3600, // Expired
                ],
                default => $default,
            };
        });
        Functions\when('__')->alias(function ($text) {
            return $text;
        });

        $result = $this->controller->getProjects();

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    #[Test]
    public function it_lists_projects_and_skips_organizations_without_a_key(): void
    {
        $requested = &$this->stubProjectsRef([
            ['id' => 1, 'name' => 'Acme & Co', 'machine_name' => 'acme co'],
            ['name' => 'No key'],
            'not-an-array',
        ], static fn(): array => ['code' => 200, 'body' => '{"data":[{"name":"Site","machine_name":"site"}]}']);

        $result = $this->controller->getProjects();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('acme co', $result[0]['organization']['machine_name']);
        $this->assertSame([['name' => 'Site', 'machine_name' => 'site']], $result[0]['projects']);
        $this->assertCount(1, $requested);
        $this->assertStringContainsString('/organizations/acme%20co/projects', $requested[0]);
    }

    #[Test]
    public function it_returns_an_error_listing_each_failed_organization(): void
    {
        $this->stubProjectsRef([
            ['id' => 1, 'name' => 'Acme', 'machine_name' => 'acme'],
            ['id' => 2, 'name' => 'Beta', 'machine_name' => 'beta'],
        ], static fn(): array => ['code' => 500, 'body' => 'oops']);

        $result = $this->controller->getProjects();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_projects', $result->get_error_code());
        $this->assertStringContainsString('Acme:', $result->get_error_message());
        $this->assertStringContainsString('Beta:', $result->get_error_message());
    }

    #[Test]
    public function it_explains_when_the_account_has_no_organizations(): void
    {
        $this->stubProjectsRef(false, static fn(): array => ['code' => 200, 'body' => '[]']);

        $result = $this->controller->getProjects();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_projects', $result->get_error_code());
        $this->assertStringContainsString('no organizations', $result->get_error_message());
    }

    #[Test]
    public function it_keeps_partial_results_when_one_organization_fails(): void
    {
        $this->stubProjectsRef([
            ['id' => 1, 'name' => 'Acme', 'machine_name' => 'acme'],
            ['id' => 2, 'name' => 'Beta', 'machine_name' => 'beta'],
        ], static fn(string $url): array => str_contains($url, '/beta/')
            ? ['code' => 403, 'body' => '{}']
            : ['code' => 200, 'body' => '{"data":[]}']);

        $result = $this->controller->getProjects();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('acme', $result[0]['organization']['machine_name']);
    }

    /**
     * stubProjects() with the requested-URL list returned by reference.
     *
     * @param mixed $organizations
     * @return array<string>
     */
    private function &stubProjectsRef(mixed $organizations, callable $respond): array
    {
        $this->requestedUrls = [];
        $urls = &$this->requestedUrls;
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'quant_oauth_token' => ['access_token' => 'tok', 'expires_at' => time() + 3600],
            'quant_oauth_organizations' => $organizations,
            default => $default,
        });
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg();
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof \WP_Error);
        Functions\when('wp_remote_get')->alias(static function (string $url) use (&$urls, $respond) {
            $urls[] = $url;
            return $respond($url);
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);

        return $this->requestedUrls;
    }

    /** @var array<string> */
    private array $requestedUrls = [];
}
