<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Result of fetching a URL from the local web server.
 */
final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = '',
        public readonly ?string $redirectLocation = null,
        public readonly string $requestedUrl = '',
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === 200 && $this->body !== '';
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 307, 308], true) && $this->redirectLocation !== null;
    }
}
