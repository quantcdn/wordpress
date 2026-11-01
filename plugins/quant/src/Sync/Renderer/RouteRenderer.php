<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use Quant\Core\Settings;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\FetchResult;
use Quant\Sync\InternalFetcher;
use Quant\Sync\MarkupProcessor;
use Quant\Sync\Paths;
use RuntimeException;

/**
 * Renders any URL path of the site: home, archives, custom routes, sitemaps.
 */
class RouteRenderer
{
    public function __construct(
        private readonly InternalFetcher $fetcher,
        private readonly MarkupProcessor $markup,
        private readonly Settings $settings
    ) {
    }

    /**
     * @param string $path        The path to fetch (may include a query string)
     * @param string|null $publishAs  The path to publish under when it differs (for example /_quant404)
     * @param bool $allow404      Accept a 404 response body (used for the 404 page)
     *
     * @throws RuntimeException
     * @throws RedirectResponseException
     */
    public function render(string $path, ?string $publishAs = null, bool $allow404 = false): RenderedContent
    {
        $result = $this->fetcher->fetch($path);

        if ($result->isRedirect()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries paths to the job handler; the message is escaped in the factory.
            throw RedirectResponseException::between(Paths::normalise($path), Paths::normalise((string) $result->redirectLocation), $result->status);
        }

        $this->assertRenderable($result, $path, $allow404);

        $target = $publishAs ?? Paths::fromUrl(home_url($path));

        return new RenderedContent(
            url: Paths::normalise($target),
            markup: $this->markup->process($result->body, $result->contentType),
            info: ['source' => 'wordpress'],
            options: $this->baseOptions($result),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function baseOptions(FetchResult $result): array
    {
        $config = $this->settings->getStaticConfig();

        $options = [
            'published' => true,
            'content_timestamp' => time(),
            'find_attachments' => true,
        ];

        if (!empty($config['proxy_override'])) {
            $options['proxy_override'] = true;
        }

        $contentType = $this->contentTypeHeader($result->contentType);
        if ($contentType !== null) {
            $options['headers'] = ['content-type' => $contentType];
        }

        return $options;
    }

    /**
     * Pass non-HTML content types (XML sitemaps, robots.txt, JSON) through to Quant.
     */
    private function contentTypeHeader(string $contentType): ?string
    {
        $contentType = trim(strtolower(strtok($contentType, ';') ?: ''));

        if ($contentType === '' || str_starts_with($contentType, 'text/html')) {
            return null;
        }

        return $contentType;
    }

    private function assertRenderable(FetchResult $result, string $path, bool $allow404): void
    {
        $acceptable = $result->status === 200 || ($allow404 && $result->status === 404);
        if ($acceptable && $result->body !== '') {
            return;
        }

        if ($result->body === '' && $result->status === 200) {
            throw new RuntimeException(esc_html("Empty response body for {$path} ({$result->requestedUrl})"));
        }

        // The page is gone: remove it from the edge, as 1.x did.
        if (!$allow404 && in_array($result->status, [404, 410], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries paths to the job handler; the message is escaped in the factory.
            throw UnpublishedContentException::forPath(Paths::normalise($path), "HTTP {$result->status} response for {$path}");
        }

        throw new RuntimeException(esc_html("HTTP {$result->status} response for {$path} ({$result->requestedUrl})"));
    }
}
