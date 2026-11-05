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
use Quant\Auth\RefreshLock;
use Quant\Auth\TokenStore;
use Quant\Core\Settings;
use Quant\Tests\Support\FakeOptionsDb;

#[CoversClass(OAuthController::class)]
#[CoversClass(RefreshLock::class)]
#[CoversClass(TokenStore::class)]
class OAuthControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORGANIZATIONS = [['id' => 1, 'name' => 'Acme', 'machine_name' => 'acme']];

    private OAuthController $controller;
    private Settings $settings;
    private FakeOptionsDb $db;

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

        $this->db = new FakeOptionsDb();
        $GLOBALS['wpdb'] = $this->db;
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('home_url')->justReturn('https://www.example.com');

        // No pause while waiting for another request's refresh, and none before the retry of a refresh.
        $this->controller = new OAuthController($this->settings, new RefreshLock(150, 0, 2), new TokenStore(), 0);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
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
    public function initiate_oauth_sends_an_s256_challenge_of_the_stored_verifier(): void
    {
        $stored = null;
        Functions\when('set_transient')->alias(function ($key, $value) use (&$stored) {
            $stored = $value;
            return true;
        });

        $result = $this->controller->initiateOAuth();
        parse_str((string) parse_url($result['redirect_url'], PHP_URL_QUERY), $query);

        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $stored['code_verifier']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $stored['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
    }

    #[Test]
    public function the_token_exchange_sends_the_verifier_of_the_answered_state(): void
    {
        Functions\when('get_transient')->justReturn(['user_id' => 1, 'created' => time(), 'code_verifier' => 'verifier-123']);
        Functions\when('__')->returnArg();
        $sent = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$sent) {
            $sent = $args['body'];
            return new \WP_Error('stop', 'stop here');
        });
        Functions\when('is_wp_error')->alias(fn ($v) => $v instanceof \WP_Error);

        $validate = new \ReflectionMethod($this->controller, 'validateCallback');
        $this->assertNull($validate->invoke($this->controller, 'the-code', 'the-state', ''));
        (new \ReflectionMethod($this->controller, 'exchangeCodeForToken'))->invoke($this->controller, 'the-code');

        $this->assertSame('verifier-123', $sent['code_verifier']);
        $this->assertSame('the-code', $sent['code']);
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
                    'expires_at' => time() - 3600,
                    'expired' => true,
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

    #[Test]
    public function a_legacy_token_response_stores_no_expiry_and_stays_connected(): void
    {
        $this->useOptionStore([]);
        $this->stubConnectResponses(['access_token' => 'legacy', 'token_type' => 'Bearer', 'scope' => 'full_access']);

        $this->connect();

        $this->assertNull($this->option('quant_oauth_token')['expires_at']);
        $this->assertNull($this->option('quant_oauth_token')['refresh_token'] ?? null);
        $this->assertSame('full_access', $this->option('quant_oauth_token')['scope']);
        $this->assertSame('me@example.com', $this->option('quant_oauth_user')['email']);

        $status = $this->controller->getStatus();
        $this->assertTrue($status['connected']);
        $this->assertFalse($status['expired']);
    }

    #[Test]
    public function a_token_stored_by_2_0_1_is_still_connected_after_its_24_hours(): void
    {
        // 2.0.1 stored connect time + 86400 for a legacy token, and no refresh token.
        $this->useOptionStore(['quant_oauth_token' => [
            'access_token' => 'legacy',
            'token_type' => 'Bearer',
            'expires_at' => time() - 30 * 86400,
            'scope' => 'full_access',
        ]]);

        $status = $this->controller->getStatus();

        $this->assertTrue($status['connected']);
        $this->assertFalse($status['expired']);
    }

    #[Test]
    public function get_projects_accepts_a_token_stored_by_2_0_1(): void
    {
        $this->stubProjectsRef([['id' => 1, 'name' => 'Acme', 'machine_name' => 'acme']],
            static fn(): array => ['code' => 200, 'body' => '{"data":[]}'], ['expires_at' => time() - 86400]);

        $result = $this->controller->getProjects();

        $this->assertIsArray($result);
        $this->assertSame('acme', $result[0]['organization']['machine_name']);
    }

    #[Test]
    public function a_scoped_token_response_stores_the_refresh_token_and_expiry(): void
    {
        $this->useOptionStore([]);
        $this->stubConnectResponses([
            'access_token' => 'scoped', 'token_type' => 'Bearer', 'scope' => 'projects:read',
            'refresh_token' => 'qcr_one', 'expires_in' => 7776000,
        ]);

        $this->connect();

        $token = $this->option('quant_oauth_token');
        $this->assertSame('qcr_one', $token['refresh_token']);
        $this->assertEqualsWithDelta(time() + 7776000, $token['expires_at'], 5);
        $this->assertSame('projects:read', $token['scope']);
        $this->assertSame(hash('sha256', 'https://www.example.com'), $token['site']);
        $this->assertTrue($this->controller->getStatus()['connected']);
    }

    #[Test]
    public function a_due_token_refreshes_and_the_rotated_tokens_are_saved(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600), 'quant_oauth_organizations' => self::ORGANIZATIONS]);
        $requests = $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000, 'scope' => 'projects:read'])],
            static fn(): array => ['code' => 200, 'body' => '{"data":[]}']
        );

        $result = $this->controller->getProjects();

        $this->assertIsArray($result);
        $this->assertSame(
            ['grant_type' => 'refresh_token', 'refresh_token' => 'qcr_old', 'client_id' => 'wordpress-quant'],
            $requests->posts[0]['body']
        );
        $this->assertSame('https://dashboard.quantcdn.io/oauth/token', $requests->postUrls[0]);
        $token = $this->option('quant_oauth_token');
        $this->assertSame('new', $token['access_token']);
        $this->assertSame('qcr_new', $token['refresh_token']);
        $this->assertEqualsWithDelta(time() + 7776000, $token['expires_at'], 5);
        $this->assertSame('Bearer new', $requests->gets[0]['headers']['Authorization']);
        $this->assertArrayNotHasKey(RefreshLock::OPTION, $this->db->rows, 'the lock is released');
    }

    #[Test]
    public function a_token_far_from_expiry_is_not_refreshed(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 30 * 86400)]);
        $requests = $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);

        $this->assertTrue($this->controller->getStatus()['connected']);
        $this->assertSame([], $requests->posts);
    }

    #[Test]
    public function a_held_lock_prevents_a_second_refresh(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->db->rows[RefreshLock::OPTION] = (string) time();
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);

        $status = $this->controller->getStatus();

        $this->assertSame([], $requests->posts);
        $this->assertTrue($status['connected'], 'the unexpired token stays in use');
        $this->assertSame('qcr_old', $this->option('quant_oauth_token')['refresh_token']);
        $this->assertArrayHasKey(RefreshLock::OPTION, $this->db->rows, 'the other request keeps its lock');
    }

    #[Test]
    public function a_waiting_request_uses_the_tokens_the_lock_holder_saved(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600), 'quant_oauth_organizations' => self::ORGANIZATIONS]);
        $this->db->rows[RefreshLock::OPTION] = (string) time();
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);
        // The other request saves its rotation and releases the lock while this one waits.
        // The first SELECT of the lock sees it held; by the next one the holder has saved and let go.
        $lockReads = 0;
        $this->db->beforeSelect = function (string $name) use (&$lockReads): void {
            if ($name === RefreshLock::OPTION && ++$lockReads === 2) {
                unset($this->db->rows[RefreshLock::OPTION]);
                $this->setOption('quant_oauth_token', $this->scopedToken(time() + 7776000, 'rotated', 'qcr_rotated'));
            }
        };

        $this->controller->getProjects();

        $this->assertSame([], $requests->posts);
        $this->assertSame('Bearer rotated', $requests->gets[0]['headers']['Authorization']);
    }

    #[Test]
    public function a_stale_lock_is_taken_over(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->db->rows[RefreshLock::OPTION] = (time() - 200) . ':abcd1234';
        $requests = $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );

        $this->controller->getStatus();

        $this->assertCount(1, $requests->posts);
        $this->assertSame('qcr_new', $this->option('quant_oauth_token')['refresh_token']);
        $this->assertArrayNotHasKey(RefreshLock::OPTION, $this->db->rows);
    }

    #[Test]
    public function invalid_grant_clears_the_refresh_token_and_asks_for_login(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(
            ['code' => 400, 'body' => '{"error":"invalid_grant","error_description":"revoked"}'],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );

        $status = $this->controller->getStatus();

        $this->assertFalse($status['connected']);
        $this->assertTrue($status['expired']);
        $this->assertNull($this->option('quant_oauth_token')['refresh_token']);
        $this->assertArrayNotHasKey(RefreshLock::OPTION, $this->db->rows, 'the lock is released');

        $projects = $this->controller->getProjects();
        $this->assertInstanceOf(\WP_Error::class, $projects);
        $this->assertSame('token_expired', $projects->get_error_code());
    }

    #[Test]
    public function a_failed_refresh_keeps_the_tokens_while_they_are_valid(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600), 'quant_oauth_organizations' => self::ORGANIZATIONS]);
        $requests = $this->stubPortal(['code' => 503, 'body' => 'busy'], static fn(): array => ['code' => 200, 'body' => '{"data":[]}']);

        $result = $this->controller->getProjects();

        $this->assertIsArray($result);
        $this->assertSame('qcr_old', $this->option('quant_oauth_token')['refresh_token']);
        $this->assertSame('Bearer old', $requests->gets[0]['headers']['Authorization']);
    }

    #[Test]
    public function a_401_forces_one_refresh_and_one_retry(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 30 * 86400)]);
        $this->stubSelectProject();
        $requests = $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(string $url, array $args): array => $args['headers']['Authorization'] === 'Bearer new'
                ? ['code' => 200, 'body' => '{"data":{"write_token":"wt"}}']
                : ['code' => 401, 'body' => '{}']
        );

        $result = $this->controller->selectProject($this->selectRequest());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $requests->posts);
        $this->assertCount(2, $requests->gets);
        $this->assertSame('qcr_new', $this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function a_401_on_a_legacy_token_asks_for_login_without_a_refresh(): void
    {
        $this->useOptionStore(['quant_oauth_token' => ['access_token' => 'legacy', 'expires_at' => null]]);
        Functions\when('sanitize_text_field')->returnArg();
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 401, 'body' => '{}']);

        $result = $this->controller->selectProject($this->selectRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_expired', $result->get_error_code());
        $this->assertSame([], $requests->posts);
    }

    #[Test]
    public function invalid_grant_keeps_a_rotation_that_another_request_saved(): void
    {
        // M1: this request read a stale row; meanwhile another request saved a rotation.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $rotated = $this->scopedToken(time() + 7776000, 'rotated', 'qcr_rotated');
        $this->stubPortal(['code' => 400, 'body' => '{"error":"invalid_grant"}'], static fn(): array => ['code' => 200, 'body' => '[]']);
        $this->onRefreshPost(fn() => $this->setOption('quant_oauth_token', $rotated));

        $status = $this->controller->getStatus();

        $this->assertTrue($status['connected']);
        $this->assertSame($rotated, $this->option('quant_oauth_token'));
    }

    #[Test]
    public function a_successful_refresh_does_not_overwrite_a_row_that_changed(): void
    {
        // M1: the compare-and-swap also guards the save of a successful refresh.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $rotated = $this->scopedToken(time() + 7776000, 'rotated', 'qcr_rotated');
        $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );
        $this->onRefreshPost(fn() => $this->setOption('quant_oauth_token', $rotated));

        $this->controller->getStatus();

        $this->assertSame($rotated, $this->option('quant_oauth_token'));
    }

    #[Test]
    public function a_token_from_another_copy_of_the_site_is_not_refreshed_and_asks_for_reconnect(): void
    {
        // M2: a database copied from production to staging carries production's refresh token.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600) + ['site' => hash('sha256', 'https://prod.example.com')]]);
        Functions\when('sanitize_text_field')->returnArg();
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);

        $status = $this->controller->getStatus();
        $result = $this->controller->selectProject($this->selectRequest());

        $this->assertFalse($status['connected']);
        $this->assertTrue($status['expired']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('site_changed', $result->get_error_code());
        $this->assertSame([], $requests->posts);
        $this->assertSame([], $requests->gets);
        $this->assertSame('qcr_old', $this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function a_token_of_this_site_is_refreshed(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600) + ['site' => hash('sha256', 'https://www.example.com')]]);
        $requests = $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );

        $this->assertTrue($this->controller->getStatus()['connected']);
        $this->assertCount(1, $requests->posts);
        $this->assertSame(hash('sha256', 'https://www.example.com'), $this->option('quant_oauth_token')['site']);
    }

    #[Test]
    public function a_failed_refresh_waits_before_the_next_try(): void
    {
        // L1: a passing failure records the time and backs off.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $requests = $this->stubPortal(['code' => 429, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);

        $this->controller->getStatus();
        $status = $this->controller->getStatus();

        $this->assertCount(1, $requests->posts);
        $this->assertTrue($status['connected']);
        $this->assertEqualsWithDelta(time() + 300, $this->option('quant_oauth_token')['refresh_retry_at'], 5);
        $this->assertSame('qcr_old', $this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function a_refresh_runs_again_once_the_backoff_ends(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600) + ['refresh_retry_at' => time() - 1]]);
        $requests = $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );

        $this->controller->getStatus();

        $this->assertCount(1, $requests->posts);
        $this->assertArrayNotHasKey('refresh_retry_at', $this->option('quant_oauth_token'));
    }

    #[Test]
    public function a_refresh_with_an_unknown_outcome_keeps_the_tokens(): void
    {
        // A timeout after the request was sent: the portal may have rotated, or not. Keep what is stored.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);
        $timeouts = [];
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use ($requests, &$timeouts) {
            $requests->posts[] = $args;
            $timeouts[] = $args['timeout'];
            return new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 60000 milliseconds');
        });

        $status = $this->controller->getStatus();

        $this->assertSame([60, 60], $timeouts, 'one retry after the unknown outcome');
        $this->assertTrue($status['connected']);
        $token = $this->option('quant_oauth_token');
        $this->assertSame('qcr_old', $token['refresh_token']);
        $this->assertSame('old', $token['access_token']);
        $this->assertArrayNotHasKey('expired', $token);
        $this->assertEqualsWithDelta(time() + 60, $token['refresh_retry_at'], 5);
    }

    #[Test]
    public function a_lock_younger_than_150_seconds_is_not_taken_over(): void
    {
        // The holder may still run its refresh: two 60 s requests and the 15 s pause between them.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->db->rows[RefreshLock::OPTION] = (time() - 140) . ':abcd1234';
        $requests = $this->stubPortal(['code' => 200, 'body' => '{}'], static fn(): array => ['code' => 200, 'body' => '[]']);

        $this->assertTrue($this->controller->getStatus()['connected']);
        $this->assertSame([], $requests->posts);
    }

    #[Test]
    public function a_timeout_then_a_200_recovers_the_rotated_pair(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $posts = $this->stubRefreshSequence([
            new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
            ['code' => 200, 'body' => json_encode(['access_token' => 'recovered', 'refresh_token' => 'qcr_recovered', 'expires_in' => 7776000])],
        ]);

        $this->assertTrue($this->controller->getStatus()['connected']);

        $this->assertSame(['qcr_old', 'qcr_old'], array_column(array_column($posts->list, 'body'), 'refresh_token'));
        $token = $this->option('quant_oauth_token');
        $this->assertSame('recovered', $token['access_token']);
        $this->assertSame('qcr_recovered', $token['refresh_token']);
        $this->assertArrayNotHasKey('refresh_retry_at', $token);
        $this->assertArrayNotHasKey(RefreshLock::OPTION, $this->db->rows, 'the lock is released after the retry');
    }

    #[Test]
    public function an_unreadable_200_is_retried_once(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $posts = $this->stubRefreshSequence([
            ['code' => 200, 'body' => '<html>proxy</html>'],
            ['code' => 200, 'body' => json_encode(['access_token' => 'recovered', 'refresh_token' => 'qcr_recovered', 'expires_in' => 7776000])],
        ]);

        $this->controller->getStatus();

        $this->assertCount(2, $posts->list);
        $this->assertSame('qcr_recovered', $this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function a_5xx_then_invalid_grant_is_final(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $this->stubRefreshSequence([
            ['code' => 502, 'body' => 'Bad Gateway'],
            ['code' => 400, 'body' => '{"error":"invalid_grant"}'],
        ]);

        $this->assertFalse($this->controller->getStatus()['connected']);
        $this->assertNull($this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function two_unknown_outcomes_keep_the_tokens_with_a_60_second_backoff(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $posts = $this->stubRefreshSequence([['code' => 503, 'body' => ''], ['code' => 504, 'body' => '']]);

        $this->controller->getStatus();
        $this->controller->getStatus();

        $this->assertCount(2, $posts->list, 'one retry, then the backoff stops the second status call');
        $token = $this->option('quant_oauth_token');
        $this->assertSame('qcr_old', $token['refresh_token']);
        $this->assertEqualsWithDelta(time() + 60, $token['refresh_retry_at'], 5);
    }

    #[Test]
    public function a_definite_failure_is_not_retried_and_backs_off_five_minutes(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $posts = $this->stubRefreshSequence([['code' => 429, 'body' => '{}'], ['code' => 200, 'body' => '{}']]);

        $this->controller->getStatus();

        $this->assertCount(1, $posts->list);
        $this->assertEqualsWithDelta(time() + 300, $this->option('quant_oauth_token')['refresh_retry_at'], 5);
    }

    #[Test]
    public function invalid_grant_is_not_retried(): void
    {
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 500, 'body' => ''], static fn(): array => ['code' => 200, 'body' => '[]']);
        $posts = $this->stubRefreshSequence([['code' => 400, 'body' => '{"error":"invalid_grant"}'], ['code' => 200, 'body' => '{}']]);

        $this->controller->getStatus();

        $this->assertCount(1, $posts->list);
    }

    /**
     * Answer the refresh grants in turn with $responses (an array response or a WP_Error).
     *
     * @param array<int, array{code: int, body: string}|\WP_Error> $responses
     */
    private function stubRefreshSequence(array $responses): \stdClass
    {
        $posts = (object) ['list' => []];
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use ($posts, &$responses) {
            $posts->list[] = $args;
            return array_shift($responses) ?? ['code' => 500, 'body' => ''];
        });

        return $posts;
    }

    #[Test]
    public function invalid_client_is_final(): void
    {
        // L1: invalid_client cannot pass by itself; treat it as invalid_grant.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 400, 'body' => '{"error":"invalid_client"}'], static fn(): array => ['code' => 200, 'body' => '[]']);

        $status = $this->controller->getStatus();

        $this->assertFalse($status['connected']);
        $this->assertNull($this->option('quant_oauth_token')['refresh_token']);
    }

    #[Test]
    public function a_refresh_does_not_bring_back_a_token_after_a_disconnect(): void
    {
        // L2: the user disconnects while the refresh is at the portal.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );
        $this->onRefreshPost(fn() => $this->controller->disconnect());
        $this->settings->shouldReceive('setCredentials');

        $status = $this->controller->getStatus();

        $this->assertFalse($status['connected']);
        $this->assertArrayNotHasKey('quant_oauth_token', $this->db->rows);
    }

    #[Test]
    public function a_refresh_does_not_overwrite_a_new_connect(): void
    {
        // L2: the user connects again while the refresh is at the portal.
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(['code' => 400, 'body' => '{"error":"invalid_grant"}'], static fn(): array => ['code' => 200, 'body' => '[]']);
        $connected = $this->scopedToken(time() + 7776000, 'reconnected', 'qcr_reconnected');
        $this->onRefreshPost(fn() => (new \ReflectionMethod($this->controller, 'storeOAuthData'))->invoke($this->controller, $connected));

        $this->assertTrue($this->controller->getStatus()['connected']);
        $this->assertSame($connected, $this->option('quant_oauth_token'));
    }

    #[Test]
    public function a_refresh_response_without_a_refresh_token_keeps_the_old_one(): void
    {
        // L4
        $this->useOptionStore(['quant_oauth_token' => $this->scopedToken(time() + 3600)]);
        $this->stubPortal(
            ['code' => 200, 'body' => json_encode(['access_token' => 'new', 'expires_in' => 7776000])],
            static fn(): array => ['code' => 200, 'body' => '[]']
        );

        $this->controller->getStatus();

        $this->assertSame('new', $this->option('quant_oauth_token')['access_token']);
        $this->assertSame('qcr_old', $this->option('quant_oauth_token')['refresh_token']);
    }

    /**
     * Run $action when the refresh grant is sent, as another request (or the user) would meanwhile.
     */
    private function onRefreshPost(callable $action): void
    {
        $previous = $this->refreshResponse;
        Functions\when('wp_remote_post')->alias(static function () use ($action, $previous): array {
            $action();
            return $previous;
        });
    }

    /** @var array{code: int, body: string} */
    private array $refreshResponse = ['code' => 500, 'body' => ''];

    /**
     * A stored scoped token.
     *
     * @return array<string, mixed>
     */
    private function scopedToken(int $expiresAt, string $access = 'old', string $refresh = 'qcr_old'): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'scope' => 'projects:read',
            'refresh_token' => $refresh,
        ];
    }

    /**
     * Stub the portal: every POST (the refresh grant) gets $refreshResponse, every GET gets $respond().
     * Returns the record of the requests.
     *
     * @param array{code: int, body: string} $refreshResponse
     */
    private function stubPortal(array $refreshResponse, callable $respond): \stdClass
    {
        $requests = (object) ['posts' => [], 'postUrls' => [], 'gets' => []];
        $this->refreshResponse = $refreshResponse;
        Functions\when('__')->returnArg();
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof \WP_Error);
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use ($requests, $refreshResponse): array {
            $requests->posts[] = $args;
            $requests->postUrls[] = $url;
            return $refreshResponse;
        });
        Functions\when('wp_remote_get')->alias(static function (string $url, array $args) use ($requests, $respond): array {
            $requests->gets[] = $args;
            return $respond($url, $args);
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);

        return $requests;
    }

    private function stubSelectProject(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        $this->settings->shouldReceive('setCredentials')->once();
    }

    private function selectRequest(): \WP_REST_Request
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_param')->andReturnUsing(static fn(string $key): string => $key === 'organization' ? 'acme' : 'site');

        return $request;
    }

    /**
     * Back get_option/update_option/delete_option with the rows of the fake options table, so that the
     * options API and the direct SQL of TokenStore and RefreshLock see the same data.
     *
     * @param array<string, mixed> $options
     */
    private function useOptionStore(array $options): void
    {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
        Functions\when('get_option')->alias(fn(string $key, $default = false) => array_key_exists($key, $this->db->rows) ? $this->option($key) : $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->setOption($key, $value);
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            $existed = array_key_exists($key, $this->db->rows);
            unset($this->db->rows[$key]);
            return $existed;
        });
    }

    private function option(string $key): mixed
    {
        return array_key_exists($key, $this->db->rows) ? maybe_unserialize($this->db->rows[$key]) : null;
    }

    private function setOption(string $key, mixed $value): void
    {
        $this->db->rows[$key] = (string) maybe_serialize($value);
    }

    /**
     * Stub the portal answers of the callback: the token exchange, then the user lookup.
     *
     * @param array<string, mixed> $tokenBody
     */
    private function stubConnectResponses(array $tokenBody): void
    {
        Functions\when('__')->returnArg();
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof \WP_Error);
        Functions\when('wp_remote_post')->justReturn(['code' => 200, 'body' => json_encode($tokenBody)]);
        Functions\when('wp_remote_get')->justReturn(['code' => 200, 'body' => json_encode([
            'id' => 7, 'email' => 'me@example.com', 'name' => 'Me', 'organizations' => [['id' => 1, 'machine_name' => 'acme']],
        ])]);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body']);
    }

    /** Run the token exchange and store its result, as the callback does. */
    private function connect(): void
    {
        $tokens = (new \ReflectionMethod($this->controller, 'exchangeCodeForToken'))->invoke($this->controller, 'the-code');
        $this->assertIsArray($tokens);
        (new \ReflectionMethod($this->controller, 'storeOAuthData'))->invoke($this->controller, $tokens);
    }

    /**
     * stubProjects() with the requested-URL list returned by reference.
     *
     * @param mixed $organizations
     * @param array<string, mixed> $token Overrides of the stored token.
     * @return array<string>
     */
    private function &stubProjectsRef(mixed $organizations, callable $respond, array $token = []): array
    {
        $this->requestedUrls = [];
        $urls = &$this->requestedUrls;
        $token += ['access_token' => 'tok', 'expires_at' => time() + 3600];
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'quant_oauth_token' => $token,
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
