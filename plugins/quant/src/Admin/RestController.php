<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class RestController
{
    private const NAMESPACE = 'quant/v1';

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/purge', [
            'methods' => 'POST',
            'callback' => [$this, 'purge'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'paths' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getStatus(WP_REST_Request $request): array
    {
        $connected = false;

        if ($this->settings->hasValidCredentials()) {
            $response = $this->api->ping();
            $connected = $response->success;
        }

        return [
            'connected' => $connected,
            'mode' => $this->settings->getMode(),
            'version' => QUANT_VERSION,
        ];
    }

    public function getSettings(WP_REST_Request $request): array
    {
        $credentials = $this->settings->getCredentials();

        return [
            'organization' => $credentials['organization'] ?? '',
            'project' => $credentials['project'] ?? '',
            'token' => '', // Never expose token
            'endpoint' => $credentials['endpoint'] ?? 'https://api.quantcdn.io',
            'mode' => $this->settings->getMode(),
        ];
    }

    public function saveSettings(WP_REST_Request $request): array|WP_Error
    {
        $organization = sanitize_text_field($request->get_param('organization') ?? '');
        $project = sanitize_text_field($request->get_param('project') ?? '');
        $token = $request->get_param('token');
        $endpoint = esc_url_raw($request->get_param('endpoint') ?? 'https://api.quantcdn.io');
        $mode = sanitize_text_field($request->get_param('mode') ?? 'static');

        // Get existing credentials
        $existing = $this->settings->getCredentials();

        // Only update token if provided (non-empty)
        $credentials = [
            'organization' => $organization,
            'project' => $project,
            'token' => !empty($token) ? $token : ($existing['token'] ?? ''),
            'endpoint' => $endpoint,
        ];

        $this->settings->setCredentials($credentials);

        if (in_array($mode, ['static', 'cdn_only'], true)) {
            $this->settings->setMode($mode);
        }

        return ['success' => true];
    }

    public function purge(WP_REST_Request $request): array|WP_Error
    {
        $paths = $request->get_param('paths');

        if (empty($paths)) {
            return new WP_Error('invalid_paths', 'No paths provided', ['status' => 400]);
        }

        $response = $this->api->purge($paths);

        if (!$response->success) {
            return new WP_Error('purge_failed', $response->message, ['status' => 500]);
        }

        return [
            'success' => true,
            'message' => $response->message,
        ];
    }
}
