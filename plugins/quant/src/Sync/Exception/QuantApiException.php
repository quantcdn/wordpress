<?php

declare(strict_types=1);

namespace Quant\Sync\Exception;

use Quant\Api\ApiResponse;
use RuntimeException;

/**
 * The Quant API refused or did not answer a sync call.
 *
 * The message is the short text for the admin; the detail is the raw
 * transport or server text, stored apart for the diagnostics.
 */
final class QuantApiException extends RuntimeException
{
    /**
     * @param bool $transient Quant did not answer or answered 5xx; the job can work on a later attempt.
     */
    public function __construct(
        string $message,
        public readonly string $detail = '',
        public readonly bool $transient = false
    ) {
        parent::__construct($message);
    }

    /**
     * @param string $context What the plugin was doing, for example "API error publishing /about".
     */
    public static function fromResponse(string $context, ApiResponse $response): self
    {
        $message = $context === '' ? $response->message : "{$context}: {$response->message}";

        return new self(esc_html($message), $response->detail, $response->transient);
    }
}
