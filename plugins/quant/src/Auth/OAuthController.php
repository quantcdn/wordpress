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
    /** Refresh an expiring token this long before it expires. */
    private const REFRESH_MARGIN = 86400;
    /** Below the stale age of the refresh lock, so a slow refresh never outlives its lock. */
    private const REFRESH_TIMEOUT = 15;

    /** The PKCE verifier of the authorization this callback answers (set by validateCallback). */
    private ?string $codeVerifier = null;

    /** Set when the portal answered 401 to a request made with the OAuth token. */
    private bool $unauthorized = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly RefreshLock $refreshLock = new RefreshLock()
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

        // PKCE (RFC 7636, S256): the verifier stays on this site; the portal sees only its hash. A stolen
        // authorization code is useless without it.
        $codeVerifier = self::base64Url(random_bytes(32));

        // Store state in transient for validation
        set_transient(
            self::STATE_TRANSIENT_PREFIX . $state,
            ['user_id' => $userId, 'created' => time(), 'code_verifier' => $codeVerifier],
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
            'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
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

        $this->codeVerifier = is_string($storedState['code_verifier'] ?? null) ? $storedState['code_verifier'] : null;

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
            ] + ($this->codeVerifier !== null ? ['code_verifier' => $this->codeVerifier] : []),
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

        return self::tokenFromResponse($data, 'full_access') + ['user' => $userData];
    }

    /**
     * The stored form of a token response. A response without expires_in (a legacy, non-expiring token)
     * stores expires_at = null: the token does not expire.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function tokenFromResponse(array $data, string $defaultScope): array
    {
        $expiresIn = $data['expires_in'] ?? null;

        return [
            'access_token' => (string) $data['access_token'],
            'token_type' => is_string($data['token_type'] ?? null) ? $data['token_type'] : 'Bearer',
            'expires_at' => is_numeric($expiresIn) ? time() + (int) $expiresIn : null,
            'scope' => is_string($data['scope'] ?? null) ? $data['scope'] : $defaultScope,
            'refresh_token' => is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
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
        $user = $tokenData['user'] ?? null;
        unset($tokenData['user']);
        update_option('quant_oauth_token', $tokenData, false); // Don't autoload
        $this->storeUserData($user);
    }

    /**
     * Store the user and organization data of the callback.
     */
    private function storeUserData(mixed $user): void
    {
        if (!is_array($user)) {
            return;
        }

        update_option('quant_oauth_user', [
            'id' => $user['id'] ?? null,
            'email' => $user['email'] ?? '',
            'name' => $user['name'] ?? '',
        ], false);

        if (isset($user['organizations'])) {
            update_option('quant_oauth_organizations', $user['organizations'], false);
        }
    }

    /**
     * Get OAuth status.
     */
    public function getStatus(): array
    {
        $token = self::storedToken();
        if (self::isDue($token)) {
            $token = $this->refreshToken();
        }
        $user = get_option('quant_oauth_user');
        $organizations = get_option('quant_oauth_organizations', []);
        $credentials = $this->settings->getCredentials();

        // Ensure organizations is an array (defensive coding for false returns)
        if (!is_array($organizations)) {
            $organizations = [];
        }

        $isConnected = !empty($token['access_token']);
        $isExpired = $isConnected && self::isExpired($token);
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
        $organizations = get_option('quant_oauth_organizations', []);
        $organizations = is_array($organizations) ? $organizations : [];

        return $this->withAccessToken(fn(string $accessToken) => $this->projectsForOrganizations($accessToken, $organizations));
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
        return $this->portalGet(
            $this->getCloudApiUrl() . '/organizations/' . rawurlencode($orgMachineName) . '/projects',
            $accessToken,
            new WP_Error('fetch_projects_failed', __('Failed to fetch projects.', 'quant'))
        );
    }

    /**
     * Fetch project details to get the write token for content API.
     */
    private function fetchProjectDetails(string $accessToken, string $orgMachineName, string $projectMachineName): array|WP_Error
    {
        return $this->portalGet(
            $this->getCloudApiUrl() . '/organizations/' . rawurlencode($orgMachineName) . '/projects/' . rawurlencode($projectMachineName) . '?with_token=1',
            $accessToken,
            new WP_Error('fetch_project_failed', __('Failed to fetch project details.', 'quant'))
        );
    }

    /**
     * GET a portal API URL with the OAuth token. Returns the response data, or $failure.
     * A 401 sets $this->unauthorized.
     */
    private function portalGet(string $url, string $accessToken, WP_Error $failure): array|WP_Error
    {
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $this->unauthorized = $this->unauthorized || $statusCode === 401;

        if ($statusCode !== 200 || !is_array($data)) {
            return $failure;
        }

        return $data['data'] ?? $data;
    }

    /**
     * Select a project to use.
     * Fetches project details to get the write token for the content API.
     */
    public function selectProject(\WP_REST_Request $request): array|WP_Error
    {
        $organization = sanitize_text_field($request->get_param('organization'));
        $project = sanitize_text_field($request->get_param('project'));

        // Fetch project details to get the write token
        $projectDetails = $this->withAccessToken(
            fn(string $accessToken) => $this->fetchProjectDetails($accessToken, $organization, $project)
        );

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

    /**
     * The stored OAuth token, or an empty array.
     *
     * @return array<string, mixed>
     */
    private static function storedToken(): array
    {
        $token = get_option('quant_oauth_token');

        return is_array($token) ? $token : [];
    }

    /**
     * The stored token, read past the object cache: another request may have just rotated it.
     *
     * @return array<string, mixed>
     */
    private static function freshToken(): array
    {
        wp_cache_delete('quant_oauth_token', 'options');

        return self::storedToken();
    }

    /**
     * Run $call with a valid OAuth access token. If the portal answers 401, refresh once and run it again.
     *
     * @param callable(string): (array|WP_Error) $call
     */
    private function withAccessToken(callable $call): array|WP_Error
    {
        $accessToken = $this->validAccessToken();
        if (is_wp_error($accessToken)) {
            return $accessToken;
        }

        $this->takeUnauthorized();
        $result = $call($accessToken);
        if (!is_wp_error($result) || !$this->takeUnauthorized()) {
            return $result;
        }

        $retryToken = $this->tokenAfterRejection($accessToken);

        return is_wp_error($retryToken) ? $retryToken : $call($retryToken);
    }

    /**
     * Whether the portal answered 401 since the last call; resets the flag.
     */
    private function takeUnauthorized(): bool
    {
        $unauthorized = $this->unauthorized;
        $this->unauthorized = false;

        return $unauthorized;
    }

    /**
     * The OAuth access token to send to the portal. Refreshes a token that expires soon.
     */
    private function validAccessToken(): string|WP_Error
    {
        $token = self::storedToken();
        if (empty($token['access_token'])) {
            return new WP_Error('not_connected', __('Not connected to Quant.', 'quant'), ['status' => 401]);
        }

        if (self::isDue($token)) {
            $token = $this->refreshToken();
        }

        return self::usableAccessToken($token) ?? self::expiredError();
    }

    /**
     * A replacement for an access token that the portal rejected with a 401, or an error that asks the
     * user to reconnect.
     */
    private function tokenAfterRejection(string $rejectedAccessToken): string|WP_Error
    {
        $accessToken = self::usableAccessToken($this->refreshToken($rejectedAccessToken));

        return $accessToken === null || $accessToken === $rejectedAccessToken ? self::expiredError() : $accessToken;
    }

    /**
     * Refresh the OAuth token, one request at a time. Returns the token as stored afterwards.
     *
     * With $rejectedAccessToken, refresh when that is still the stored access token; otherwise refresh
     * when the stored token is due. A request that cannot take the lock waits for the refresh of the
     * other request and returns its result.
     *
     * @return array<string, mixed>
     */
    private function refreshToken(?string $rejectedAccessToken = null): array
    {
        if (!$this->refreshLock->acquire()) {
            $this->refreshLock->waitForRelease();
            return self::freshToken();
        }

        try {
            // Re-read under the lock: another request may have refreshed since this one read the token.
            $token = self::freshToken();

            return self::needsRefresh($token, $rejectedAccessToken) ? $this->performRefresh($token) : $token;
        } finally {
            $this->refreshLock->release();
        }
    }

    /**
     * Exchange the refresh token and save the result before the lock is released.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private function performRefresh(array $token): array
    {
        $data = $this->requestRefresh((string) $token['refresh_token']);

        if (is_array($data)) {
            $token = self::tokenFromResponse($data, (string) ($token['scope'] ?? 'full_access'));
        } elseif ($data->get_error_code() === 'invalid_grant') {
            // The portal no longer accepts this refresh token: show Login again. Publishing is not
            // affected, because it uses the project token.
            $token['refresh_token'] = null;
            $token['expired'] = true;
        } else {
            // A network or server failure: keep the tokens and try again on a later request.
            return $token;
        }

        update_option('quant_oauth_token', $token, false);

        return $token;
    }

    /**
     * POST the refresh grant. Returns the response data, or an error whose code is the OAuth error
     * of an HTTP 400 answer (invalid_grant, invalid_client) or refresh_failed.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function requestRefresh(string $refreshToken): array|WP_Error
    {
        $response = wp_remote_post($this->getPortalUrl() . '/oauth/token', [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => self::CLIENT_ID,
            ],
            'timeout' => self::REFRESH_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($statusCode === 200 && is_array($data) && !empty($data['access_token'])) {
            return $data;
        }

        $error = $statusCode === 400 && is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'refresh_failed';

        return new WP_Error($error, __('Could not refresh the Quant login.', 'quant'));
    }

    /**
     * Whether a refresh should run on this token.
     *
     * @param array<string, mixed> $token
     */
    private static function needsRefresh(array $token, ?string $rejectedAccessToken): bool
    {
        if ($rejectedAccessToken === null) {
            return self::isDue($token);
        }

        return !empty($token['refresh_token'])
            && empty($token['expired'])
            && ($token['access_token'] ?? null) === $rejectedAccessToken;
    }

    /**
     * Whether a token has a refresh token and expires within the refresh margin.
     *
     * @param array<string, mixed> $token
     */
    private static function isDue(array $token): bool
    {
        return !empty($token['refresh_token'])
            && empty($token['expired'])
            && is_numeric($token['expires_at'] ?? null)
            && (int) $token['expires_at'] - self::REFRESH_MARGIN <= time();
    }

    /**
     * The access token of a stored token, or null when there is none or it has expired.
     *
     * @param array<string, mixed> $token
     */
    private static function usableAccessToken(array $token): ?string
    {
        $accessToken = $token['access_token'] ?? null;

        return is_string($accessToken) && $accessToken !== '' && !self::isExpired($token) ? $accessToken : null;
    }

    private static function expiredError(): WP_Error
    {
        return new WP_Error('token_expired', __('OAuth token has expired. Please reconnect.', 'quant'), ['status' => 401]);
    }

    /**
     * Whether the plugin treats a stored token as expired.
     *
     * A token without a refresh token is a legacy token: the plugin never expires it, whatever its
     * expires_at says (2.0.1 stored connect time + 24 h for tokens that do not expire). The portal is the
     * authority for those; a 401 from it asks the user to reconnect.
     *
     * @param array<string, mixed> $token
     */
    private static function isExpired(array $token): bool
    {
        if (!empty($token['expired'])) {
            return true;
        }

        return !empty($token['refresh_token'])
            && is_numeric($token['expires_at'] ?? null)
            && (int) $token['expires_at'] <= time();
    }

    /** Base64url without padding (RFC 7636 appendix A). */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
