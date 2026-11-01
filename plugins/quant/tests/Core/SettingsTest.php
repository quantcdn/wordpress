<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Settings;

#[CoversClass(Settings::class)]
final class SettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });

        $this->settings = new Settings();
    }

    protected function tearDown(): void
    {
        putenv('QUANT_TOKEN');
        putenv('QUANT_WEBSERVER_HOST');
        putenv('QUANT_MODE');
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_complete_defaults_when_nothing_is_stored(): void
    {
        $credentials = $this->settings->getCredentials();

        $this->assertSame('', $credentials['organization']);
        $this->assertSame(Settings::DEFAULT_ENDPOINT, $credentials['endpoint']);
        $this->assertFalse($credentials['disable_tls_verify']);
        $this->assertSame(30, $credentials['http_request_timeout']);
        $this->assertFalse($this->settings->hasValidCredentials());
        $this->assertSame('static', $this->settings->getMode());
        $this->assertTrue($this->settings->isStaticMode());
        $this->assertSame(['/robots.txt'], $this->settings->getStaticConfig()['custom_routes']);
        $this->assertTrue($this->settings->getStaticConfig()['strip_host']);
        $this->assertTrue($this->settings->getSyncSettings()['auto_sync']);
        $this->assertSame('daily', $this->settings->getSchedule()['interval']);
        $this->assertFalse($this->settings->getSearchSettings()['enabled']);
    }

    #[Test]
    public function it_merges_stored_values_with_defaults_and_drops_unknown_keys(): void
    {
        $this->settings->setCredentials(['organization' => 'acme', 'project' => 'site', 'token' => 'tok', 'bogus' => 1]);
        $this->settings->setCredentials(['endpoint' => 'https://api.quantgov.cloud/']);

        $stored = $this->options[Settings::OPTION_CREDENTIALS];
        $this->assertArrayNotHasKey('bogus', $stored);
        $this->assertSame('acme', $stored['organization']);
        $this->assertSame('tok', $stored['token'], 'A partial update keeps the other values');

        $credentials = $this->settings->getCredentials();
        $this->assertSame('https://api.quantgov.cloud', $credentials['endpoint'], 'Trailing slash removed');
        $this->assertTrue($this->settings->hasValidCredentials());
    }

    #[Test]
    public function it_clamps_the_timeout_and_casts_flags(): void
    {
        $this->settings->setCredentials(['http_request_timeout' => '2', 'disable_tls_verify' => '1']);

        $credentials = $this->settings->getCredentials();
        $this->assertSame(5, $credentials['http_request_timeout']);
        $this->assertTrue($credentials['disable_tls_verify']);
    }

    #[Test]
    public function it_accepts_newline_separated_lists_from_v1(): void
    {
        $this->options[Settings::OPTION_STATIC_CONFIG] = ['custom_routes' => "/robots.txt\n\n /sitemap.xml \n", 'domain_rewrites' => ''];

        $config = $this->settings->getStaticConfig();

        $this->assertSame(['/robots.txt', '/sitemap.xml'], $config['custom_routes']);
        $this->assertSame([], $config['domain_rewrites']);
    }

    #[Test]
    public function it_only_knows_two_modes(): void
    {
        $this->settings->setMode('cdn_only');
        $this->assertTrue($this->settings->isCdnOnlyMode());

        $this->settings->setMode('weird');
        $this->assertSame('static', $this->settings->getMode());
    }

    #[Test]
    public function environment_variables_override_stored_values(): void
    {
        $this->settings->setCredentials(['token' => 'stored']);
        $this->settings->setMode('static');
        putenv('QUANT_TOKEN=from-env');
        putenv('QUANT_WEBSERVER_HOST=www.example.com');
        putenv('QUANT_MODE=cdn_only');

        $this->assertSame('from-env', $this->settings->getCredentials()['token']);
        $this->assertSame('www.example.com', $this->settings->getStaticConfig()['webserver_host']);
        $this->assertSame('cdn_only', $this->settings->getMode());
        $this->assertSame(['token'], $this->settings->getOverriddenKeys()['credentials']);
    }

    #[Test]
    public function it_normalises_schedule_and_search_lists(): void
    {
        $this->settings->setSchedule(['enabled' => true, 'types' => ['post', 3, 'page'], 'custom_routes' => "/a\n/b"]);
        $this->settings->setSearchSettings(['enabled' => true, 'post_types' => ['post', null], 'excluded_post_types' => 'x']);

        $this->assertSame(['post', 'page'], $this->settings->getSchedule()['types']);
        $this->assertSame(['/a', '/b'], $this->settings->getSchedule()['custom_routes']);
        $this->assertSame(['post'], $this->settings->getSearchSettings()['post_types']);
        $this->assertSame(['x'], $this->settings->getSearchSettings()['excluded_post_types']);
    }
}
