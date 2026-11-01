<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Hook\InternalRequestHandler;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(InternalRequestHandler::class)]
final class InternalRequestHandlerTest extends TestCase
{
    use WordPressStubs;

    private InternalRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'quant_internal_token' ? 'secret' : $d);
        $_SERVER['REQUEST_URI'] = '/blog/page/2/';
        unset($_SERVER['HTTP_QUANT_TOKEN']);
        $this->handler = new InternalRequestHandler();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_QUANT_TOKEN'], $_SERVER['REQUEST_URI']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_recognises_the_internal_token_only_when_it_matches(): void
    {
        $this->assertFalse($this->handler->isInternalRequest());

        $_SERVER['HTTP_QUANT_TOKEN'] = 'wrong';
        $this->assertFalse($this->handler->isInternalRequest());

        $_SERVER['HTTP_QUANT_TOKEN'] = 'secret';
        $this->assertTrue($this->handler->isInternalRequest());
    }

    #[Test]
    public function it_disables_canonical_redirects_for_internal_requests(): void
    {
        $_SERVER['HTTP_QUANT_TOKEN'] = 'secret';
        $removed = [];
        Functions\when('remove_action')->alias(static function (string $hook, string $callback) use (&$removed): bool {
            $removed[] = $callback;
            return true;
        });

        $this->handler->onInit();

        $this->assertSame(['redirect_canonical', 'wp_old_slug_redirect'], $removed);
        $this->assertTrue(defined('QUANT_INTERNAL_REQUEST'));
        $this->assertSame('/x', $this->handler->blockStatisticsRedirects('/x'));
    }

    #[Test]
    public function it_leaves_ordinary_requests_alone(): void
    {
        Functions\expect('remove_action')->never();

        $this->handler->onInit();

        $this->assertFalse($this->handler->isInternalRequest());
    }
}
