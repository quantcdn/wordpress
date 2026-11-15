<?php

declare(strict_types=1);

namespace Quant\Api;

use WP_Error;

/**
 * Short messages for failures between WordPress and the Quant API.
 *
 * The raw transport text ("cURL error 28: Operation timed out after ...")
 * helps support but means nothing to an editor. The admin shows the short
 * message; the raw text is kept as a detail for diagnostics.
 *
 * The reasons do not say what happens next. Only the caller knows that, so
 * the caller adds one of the NEXT_* sentences: the queue adds NEXT_AUTOMATIC
 * or NEXT_MANUAL (Quant\Sync\JobProcessor), the connection test adds
 * NEXT_TEST_AGAIN (QuantContentApi::ping).
 */
final class TransportError
{
    public const TIMEOUT = 'Quant did not answer in time.';
    public const DNS = 'The Quant API address could not be found (DNS lookup failed).';
    public const TLS = 'A secure connection to Quant could not be made (TLS error).';
    public const CONNECTION = 'The connection to Quant failed.';
    public const UNAVAILABLE = 'Quant is busy or unavailable (HTTP %d).';
    public const SERVER = 'Quant had a server error (HTTP %d).';
    /** Any other WP_Error (request not executed, invalid URL, too many redirects): not a transport failure. */
    public const FAILED = 'The request to Quant failed.';

    /** The job has attempts left and background processing runs. */
    public const NEXT_AUTOMATIC = 'The plugin will try again.';
    /** The job used all attempts, or nothing runs the queue in the background. */
    public const NEXT_MANUAL = 'Select Retry failed on the dashboard.';
    /** A failed connection test. */
    public const NEXT_TEST_AGAIN = 'Test the connection again in a minute.';

    /**
     * Patterns over the raw message, checked in order. WordPress reports all
     * transport failures as http_request_failed, so the message text is the
     * only signal. The cURL error numbers are stable across cURL versions.
     */
    private const PATTERNS = [
        self::DNS => '/cURL error 6:|could not resolve|getaddrinfo|name or service not known/i',
        self::TIMEOUT => '/cURL error 28:|timed out|timeout/i',
        self::TLS => '/cURL error (35|51|53|54|58|59|60|64|66|77|80|83|90|91):|\bssl\b|\btls\b|certificate/i',
        self::CONNECTION => '/cURL error (7|52|55|56):|connection (refused|reset)|failed to connect|empty reply/i',
    ];

    /**
     * 'transport' is true only for an http_request_failed error whose text
     * matches a known transport failure (timeout, DNS, TLS, connection).
     * Only those can work when the same request is sent again.
     *
     * @return array{message: string, detail: string, transport: bool}
     */
    public static function fromWpError(WP_Error $error): array
    {
        $code = (string) $error->get_error_code();
        $raw = (string) $error->get_error_message();
        $message = $code === 'http_request_failed' ? self::classify($raw) : null;

        return [
            'message' => $message ?? self::FAILED,
            'detail' => $code !== '' ? "{$code}: {$raw}" : $raw,
            'transport' => $message !== null,
        ];
    }

    /**
     * The short message for a server-side status, or null when the API's own
     * message is more useful (4xx answers explain what was wrong).
     */
    public static function forStatus(int $status): ?string
    {
        if (in_array($status, RetryPolicy::RETRY_STATUSES, true)) {
            return sprintf(self::UNAVAILABLE, $status);
        }

        return $status >= 500 && $status < 600 ? sprintf(self::SERVER, $status) : null;
    }

    private static function classify(string $raw): ?string
    {
        foreach (self::PATTERNS as $message => $pattern) {
            if (preg_match($pattern, $raw) === 1) {
                return $message;
            }
        }

        return null;
    }
}
