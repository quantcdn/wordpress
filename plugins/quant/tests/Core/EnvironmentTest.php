<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Environment;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (array_keys(Environment::MAP) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_reads_nothing_when_no_variables_are_set(): void
    {
        $this->assertNull(Environment::raw('QUANT_TOKEN'));
        $this->assertSame([], Environment::overridesFor('credentials'));
        $this->assertSame([], Environment::overriddenKeys());
    }

    #[Test]
    public function it_maps_environment_variables_to_settings_with_types(): void
    {
        putenv('QUANT_CUSTOMER=acme');
        putenv('QUANT_PROJECT=site');
        putenv('QUANT_TOKEN=tok');
        putenv('QUANT_DISABLE_TLS_VERIFY=1');
        putenv('QUANT_HTTP_REQUEST_TIMEOUT=45');
        putenv('QUANT_WEBSERVER_URL=http://localhost');
        putenv('QUANT_ENABLED=false');
        putenv('QUANT_MODE=cdn_only');

        $this->assertSame([
            'organization' => 'acme',
            'project' => 'site',
            'token' => 'tok',
            'disable_tls_verify' => true,
            'http_request_timeout' => 45,
        ], Environment::overridesFor('credentials'));
        $this->assertSame(['webserver_url' => 'http://localhost'], Environment::overridesFor('static'));
        $this->assertSame(['auto_sync' => false], Environment::overridesFor('sync'));
        $this->assertSame(['mode' => 'cdn_only'], Environment::overridesFor('mode'));

        $keys = Environment::overriddenKeys();
        $this->assertSame(['organization', 'project', 'token', 'disable_tls_verify', 'http_request_timeout'], $keys['credentials']);
        $this->assertSame(['auto_sync'], $keys['sync']);
    }

    #[Test]
    public function it_ignores_empty_values_and_reads_env_superglobals(): void
    {
        putenv('QUANT_PROJECT=');
        $_ENV['QUANT_API_ENDPOINT'] = 'https://api.quantgov.cloud';
        $_SERVER['QUANT_PURGE_ON_SAVE'] = '0';

        $this->assertNull(Environment::raw('QUANT_PROJECT'));
        $this->assertSame('https://api.quantgov.cloud', Environment::raw('QUANT_API_ENDPOINT'));
        $this->assertSame(['auto_purge' => false], Environment::overridesFor('sync'));
    }
}
