<?php

declare(strict_types=1);

namespace Quant\Auth;

use Quant\Core\Settings;
use WP_Error;

/**
 * Handles OAuth 2.0 authentication flow with QuantCDN portal.
 */
class OAuthController
{
    private const CLIENT_ID = 'wordpress-quant';
    private const PORTAL_URL = 'https://dashboard.quantcdn.io';
    private const STATE_TRANSIENT_PREFIX = 'quant_oauth_state_';
    private const STATE_EXPIRY = 600; // 10 minutes

    public function __construct(
        private readonly Settings $settings
    ) {}

    /**
     * Register admin hooks. Call early (plugins_loaded or init).
     */
    public function registerAdmin(): void
    {
        // Use admin_post_ hooks for OAuth callback - works reliably for redirects
        add_action('admin_post_quant_oauth_callback', [$this, 'handleCallback']);
    }

    /**
     * Register the OAuth callback page and REST routes.
     * Note: registerRestRoutes should be called directly from the rest_api_init hook,
     * not wrapped in add_action, since Plugin::registerRestRoutes is already in that hook.
     */
    public function register(): void
    {
        // Register REST endpoints for OAuth (called directly from Plugin::registerRestRoutes)
        $this->registerRestRoutes();
    }

    /**
     * Register REST routes for OAuth operations.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('quant/v1', '/oauth/connect', [
            'methods' => 'GET',
            'callback' => [$this, 'initiateOAuth'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('quant/v1', '/oauth/disconnect', [
            'methods' => 'POST',
            'callback' => [$this, 'disconnect'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('quant/v1', '/oauth/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('quant/v1', '/oauth/projects', [
            'methods' => 'GET',
            'callback' => [$this, 'getProjects'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('quant/v1', '/oauth/select-project', [
            'methods' => 'POST',
            'callback' => [$this, 'selectProject'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'organization' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'project' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Initiate OAuth flow - redirects to portal.
     */
    public function initiateOAuth(): array
    {
        // Generate CSRF state token
        $state = bin2hex(random_bytes(16));
        $userId = get_current_user_id();

        // Store state in transient for validation
        set_transient(
            self::STATE_TRANSIENT_PREFIX . $state,
            ['user_id' => $userId, 'created' => time()],
            self::STATE_EXPIRY
        );

        // Build callback URL using admin-post.php for reliable callback handling
        $callbackUrl = admin_url('admin-post.php?action=quant_oauth_callback');

        // Build authorization URL
        $authUrl = $this->getPortalUrl() . '/oauth/authorize?' . http_build_query([
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'full_access',
        ]);

        return [
            'redirect_url' => $authUrl,
        ];
    }

    /**
     * Handle OAuth callback from portal.
     */
    public function handleCallback(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        $failure = $this->validateCallback($code, $state, $error);
        if ($failure !== null) {
            $this->renderError($failure);
            return;
        }

        $tokenResult = $this->exchangeCodeForToken($code);
        if (is_wp_error($tokenResult)) {
            $this->renderError($tokenResult->get_error_message());
            return;
        }

        $this->storeOAuthData($tokenResult);

        wp_safe_redirect(admin_url('admin.php?page=quant&oauth=success#/settings'));
        exit;
    }

    /**
     * Check the callback parameters and the CSRF state. Returns an error message or null.
     */
    private function validateCallback(string $code, string $state, string $error): ?string
    {
        if ($error !== '') {
            return __('Authorization was denied or cancelled.', 'quant');
        }

        $storedState = get_transient(self::STATE_TRANSIENT_PREFIX . $state);
        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

        if (!is_array($storedState)) {
            return __('Invalid or expired authorization request. Please try again.', 'quant');
        }

        if (($storedState['user_id'] ?? null) !== get_current_user_id()) {
            return __('Authorization session mismatch. Please try again.', 'quant');
        }

        if ($code === '') {
            return __('No authorization code received.', 'quant');
        }

        return null;
    }

    /**
     * Exchange authorization code for access token.
     */
    private function exchangeCodeForToken(string $code): array|WP_Error
    {
        $callbackUrl = admin_url('admin-post.php?action=quant_oauth_callback');

        $response = wp_remote_post($this->getPortalUrl() . '/oauth/token', [
            'body' => [
                'grant_type' => 'authorization_code',
                'client_id' => self::CLIENT_ID,
                'code' => $code,
                'redirect_uri' => $callbackUrl,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('token_exchange_failed', $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode !== 200 || !is_array($data)) {
            return new WP_Error(
                'token_exchange_failed',
                $data['error'] ?? __('Failed to exchange authorization code for token.', 'quant')
            );
        }

        if (empty($data['access_token'])) {
            return new WP_Error('token_exchange_failed', __('No access token received.', 'quant'));
        }

        // Fetch user/organization data
        $userData = $this->fetchUserData($data['access_token']);

        if (is_wp_error($userData)) {
            return $userData;
        }

        return [
            'access_token' => $data['access_token'],
            'token_type' => $data['token_type'] ?? 'Bearer',
            'expires_in' => $data['expires_in'] ?? 86400,
            'scope' => $data['scope'] ?? 'full_access',
            'user' => $userData,
        ];
    }

    /**
     * Fetch user and organization data from portal.
     */
    private function fetchUserData(string $accessToken): array|WP_Error
    {
        $response = wp_remote_get($this->getPortalUrl() . '/api/oauth/user', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('user_fetch_failed', $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode !== 200 || !is_array($data)) {
            return new WP_Error(
                'user_fetch_failed',
                __('Failed to fetch user information.', 'quant')
            );
        }

        return $data;
    }

    /**
     * Store OAuth data in WordPress options.
     */
    private function storeOAuthData(array $tokenData): void
    {
        // Store the access token securely
        update_option('quant_oauth_token', [
            'access_token' => $tokenData['access_token'],
            'token_type' => $tokenData['token_type'],
            'expires_at' => time() + ($tokenData['expires_in'] ?? 86400),
            'scope' => $tokenData['scope'],
        ], false); // Don't autoload

        // Store user and organization data
        if (isset($tokenData['user'])) {
            update_option('quant_oauth_user', [
                'id' => $tokenData['user']['id'] ?? null,
                'email' => $tokenData['user']['email'] ?? '',
                'name' => $tokenData['user']['name'] ?? '',
            ], false);

            // Store available organizations
            if (isset($tokenData['user']['organizations'])) {
                update_option('quant_oauth_organizations', $tokenData['user']['organizations'], false);
            }
        }
    }

    /**
     * Get OAuth status.
     */
    public function getStatus(): array
    {
        $token = get_option('quant_oauth_token');
        $user = get_option('quant_oauth_user');
        $organizations = get_option('quant_oauth_organizations', []);
        $credentials = $this->settings->getCredentials();

        // Ensure organizations is an array (defensive coding for false returns)
        if (!is_array($organizations)) {
            $organizations = [];
        }

        $isConnected = !empty($token['access_token']);
        $isExpired = $isConnected && isset($token['expires_at']) && $token['expires_at'] < time();
        $hasProject = !empty($credentials['organization']) && !empty($credentials['project']);

        return [
            'connected' => $isConnected && !$isExpired,
            'expired' => $isExpired,
            'has_project' => $hasProject,
            'user' => $isConnected ? [
                'email' => $user['email'] ?? '',
                'name' => $user['name'] ?? '',
            ] : null,
            'organization' => $hasProject ? $credentials['organization'] : null,
            'project' => $hasProject ? $credentials['project'] : null,
            'organizations_count' => count($organizations),
        ];
    }

    /**
     * Get available projects for selection.
     */
    public function getProjects(): array|WP_Error
    {
        $token = get_option('quant_oauth_token');

        if (empty($token['access_token'])) {
            return new WP_Error('not_connected', __('Not connected to Quant.', 'quant'), ['status' => 401]);
        }

        // Check if token is expired
        if (isset($token['expires_at']) && $token['expires_at'] < time()) {
            return new WP_Error('token_expired', __('OAuth token has expired. Please reconnect.', 'quant'), ['status' => 401]);
        }

        $organizations = get_option('quant_oauth_organizations', []);

        return $this->projectsForOrganizations((string) $token['access_token'], is_array($organizations) ? $organizations : []);
    }

    /**
     * Projects of each organization; an error when none could be loaded.
     *
     * @param array<int, mixed> $organizations
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function projectsForOrganizations(string $accessToken, array $organizations): array|WP_Error
    {
        $result = [];
        $errors = [];

        foreach ($organizations as $org) {
            $key = is_array($org) ? (string) ($org['machine_name'] ?? $org['id'] ?? '') : '';
            if ($key === '') {
                continue;
            }

            $projects = $this->fetchProjectsForOrganization($accessToken, $key);
            if (is_wp_error($projects)) {
                $errors[] = ($org['name'] ?? $key) . ': ' . $projects->get_error_message();
                continue;
            }

            $result[] = [
                'organization' => [
                    'id' => $org['id'] ?? null,
                    'name' => $org['name'] ?? '',
                    'machine_name' => $org['machine_name'] ?? '',
                ],
                'projects' => $projects,
            ];
        }

        if ($result !== []) {
            return $result;
        }

        return new WP_Error(
            'no_projects',
            $errors === []
                ? __('Your Quant account has no organizations this site can use. Reconnect, or enter the project credentials by hand.', 'quant')
                : __('Could not load projects from Quant: ', 'quant') . implode('; ', $errors),
            ['status' => 502]
        );
    }

    /**
     * Fetch projects for an organization from Cloud API.
     */
    private function fetchProjectsForOrganization(string $accessToken, string $orgMachineName): array|WP_Error
    {
        $response = wp_remote_get($this->getCloudApiUrl() . '/organizations/' . rawurlencode($orgMachineName) . '/projects', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode !== 200 || !is_array($data)) {
            return new WP_Error('fetch_projects_failed', __('Failed to fetch projects.', 'quant'));
        }

        return $data['data'] ?? $data;
    }

    /**
     * Fetch project details to get the write token for content API.
     */
    private function fetchProjectDetails(string $accessToken, string $orgMachineName, string $projectMachineName): array|WP_Error
    {
        $response = wp_remote_get($this->getCloudApiUrl() . '/organizations/' . rawurlencode($orgMachineName) . '/projects/' . rawurlencode($projectMachineName) . '?with_token=1', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode !== 200 || !is_array($data)) {
            return new WP_Error('fetch_project_failed', __('Failed to fetch project details.', 'quant'));
        }

        return $data['data'] ?? $data;
    }

    /**
     * Select a project to use.
     * Fetches project details to get the write token for the content API.
     */
    public function selectProject(\WP_REST_Request $request): array|WP_Error
    {
        $token = get_option('quant_oauth_token');

        if (empty($token['access_token'])) {
            return new WP_Error('not_connected', __('Not connected to Quant.', 'quant'), ['status' => 401]);
        }

        $organization = sanitize_text_field($request->get_param('organization'));
        $project = sanitize_text_field($request->get_param('project'));

        // Fetch project details to get the write token
        $projectDetails = $this->fetchProjectDetails($token['access_token'], $organization, $project);

        if (is_wp_error($projectDetails)) {
            return $projectDetails;
        }

        // Get the write token from project details
        $writeToken = $projectDetails['write_token'] ?? $projectDetails['token'] ?? null;

        if (empty($writeToken)) {
            return new WP_Error('no_token', __('Could not retrieve project write token.', 'quant'), ['status' => 400]);
        }

        // Store credentials with the project's write token for the content API
        $credentials = $this->settings->getCredentials();
        $credentials['organization'] = $organization;
        $credentials['project'] = $project;
        $credentials['token'] = $writeToken;

        $this->settings->setCredentials($credentials);

        return [
            'success' => true,
            'organization' => $organization,
            'project' => $project,
        ];
    }

    /**
     * Disconnect from Quant OAuth.
     */
    public function disconnect(): array
    {
        // Clear OAuth data
        delete_option('quant_oauth_token');
        delete_option('quant_oauth_user');
        delete_option('quant_oauth_organizations');

        // Clear credentials
        $credentials = $this->settings->getCredentials();
        $credentials['organization'] = '';
        $credentials['project'] = '';
        $credentials['token'] = '';
        $this->settings->setCredentials($credentials);

        return ['success' => true];
    }

    /**
     * Render error page.
     */
    private function renderError(string $message): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Quant OAuth Error', 'quant'); ?></h1>
            <div class="notice notice-error">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=quant')); ?>" class="button button-primary">
                    <?php esc_html_e('Return to Quant Settings', 'quant'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Get the portal URL from settings or default.
     */
    private function getPortalUrl(): string
    {
        $endpoint = $this->settings->getCredentials()['endpoint'] ?? '';
        $portal = str_contains($endpoint, 'quantgov.cloud') ? 'https://dash.quantgov.cloud' : self::PORTAL_URL;

        /** This filter is documented in v2.0.0; it sets the dashboard used for OAuth. */
        return rtrim((string) apply_filters('quant_portal_url', $portal), '/');
    }

    /**
     * Get the Cloud API URL (portal API for managing orgs/projects).
     */
    private function getCloudApiUrl(): string
    {
        /** This filter is documented in v2.0.0; it sets the platform API used to list projects. */
        return rtrim((string) apply_filters('quant_cloud_api_url', $this->getPortalUrl() . '/api/v2'), '/');
    }
}
