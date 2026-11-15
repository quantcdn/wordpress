<?php

declare(strict_types=1);

namespace Quant\Api;

class ApiResponse
{
    /**
     * @param string $message Short text that the admin can show to an editor.
     * @param string $detail Raw transport or server text for diagnostics; never shown in the progress card.
     * @param bool $transient True when Quant did not answer or answered 5xx: the same call can work later.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly int $statusCode = 200,
        public readonly string $detail = '',
        public readonly bool $transient = false
    ) {}

    public static function success(array $data = [], string $message = ''): self
    {
        return new self(true, $message, $data);
    }

    public static function error(string $message, int $statusCode = 400, array $data = [], string $detail = '', bool $transient = false): self
    {
        return new self(false, $message, $data, $statusCode, $detail, $transient);
    }

    /**
     * The message with the raw detail in brackets, for administrators (WP-CLI, logs).
     */
    public function messageWithDetail(): string
    {
        return $this->detail === '' ? $this->message : "{$this->message} ({$this->detail})";
    }
}
