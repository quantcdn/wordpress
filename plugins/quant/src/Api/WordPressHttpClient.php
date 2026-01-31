<?php

declare(strict_types=1);

namespace Quant\Api;

use WP_Error;

class WordPressHttpClient implements HttpClientInterface
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    public function patch(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('PATCH', $url, $headers, $body);
    }

    private function request(string $method, string $url, array $headers, array $body = []): array
    {
        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            return [
                'code' => 0,
                'body' => '',
                'error' => $response->get_error_message(),
            ];
        }

        return [
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        ];
    }
}
