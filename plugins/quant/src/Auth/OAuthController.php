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
    /**
     * Long enough to outlast a stall of the portal database: the portal commits a rotation even when the
     * client has gone, so a refresh that gives up early loses the rotated pair. Below the stale age of the
     * refresh lock (90 s), so the lock always outlives the request.
     */
    private const REFRESH_TIMEOUT = 60;
    /** After a definite passing failure (429, another 4xx), wait this long before the next refresh. */
    private const REFRESH_BACKOFF = 300;
    /**
     * Lost-rotation recovery. The portal answers the previous refresh token with "just rotated" for 30 s
     * after a rotation, recovers the rotation (a new pair, once) from 30 s to 5 min while its access token
     * is unused, and revokes the chain after that. After an unknown outcome the plugin therefore waits
     * RECOVERY_START seconds, then retries with the same refresh token until RECOVERY_END, at most
     * RECOVERY_RETRIES times.
     */
    private const RECOVERY_START = 45;
    private const RECOVERY_END = 240;
    private const RECOVERY_RETRIES = 2;
    /** The token fields of a pending recovery. */
    private const RECOVERY_FIELDS = ['refresh_pending_at', 'refresh_pending_hash', 'refresh_pending_tries'];
    /**
     * The error code of a refresh whose outcome is unknown: a timeout, a network error, a 5xx, an
     * unreadable 200, or "just rotated".
     */
    private const UNKNOWN_REFRESH = 'refresh_unknown';
    /** Refresh errors that a later try cannot fix: the user must log in again. */
    private const FINAL_REFRESH_ERRORS = ['invalid_grant', 'invalid_client'];

    /** The PKCE verifier of the authorization this callback answers (set by validateCallback). */
    private ?string $codeVerifier = null;

    /** Set when the portal answered 401 to a request made with the OAuth token. */
    private bool $unauthorized = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly RefreshLock $refreshLock = new RefreshLock(),
        private readonly TokenStore $tokenStore = new TokenStore()
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
            'site' => self::siteFingerprint(),
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

        return self::usableAccessToken($token) ?? self::expiredError($token);
    }

    /**
     * A replacement for an access token that the portal rejected with a 401, or an error that asks the
     * user to reconnect.
     */
    private function tokenAfterRejection(string $rejectedAccessToken): string|WP_Error
    {
        $token = $this->refreshToken($rejectedAccessToken);
        $accessToken = self::usableAccessToken($token);

        return $accessToken === null || $accessToken === $rejectedAccessToken ? self::expiredError($token) : $accessToken;
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
            return $this->tokenStore->read()['token'];
        }

        try {
            // Re-read under the lock: another request may have refreshed since this one read the token.
            $stored = $this->tokenStore->read();
            if (!self::needsRefresh($stored['token'], $rejectedAccessToken)) {
                return $stored['token'];
            }

            $abandoned = self::abandonedRecovery($stored['token']);
            if ($abandoned !== null) {
                return $this->save((string) $stored['raw'], $abandoned);
            }

            return $this->performRefresh($stored['token'], (string) $stored['raw']);
        } finally {
            $this->refreshLock->release();
        }
    }

    /**
     * Exchange the refresh token and save the result before the lock is released.
     *
     * The save is a compare-and-swap against $raw, the row this request read. When the row has changed
     * since (another request saved a rotation, the user disconnected or connected again), nothing is
     * written and the stored token wins.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private function performRefresh(array $token, string $raw): array
    {
        return $this->save($raw, self::refreshOutcome($token, $this->requestRefresh((string) $token['refresh_token'])));
    }

    /**
     * Compare-and-swap $token over the row that read as $raw. Returns what is stored afterwards.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private function save(string $raw, array $token): array
    {
        return $this->tokenStore->replace($raw, $token) ? $token : $this->tokenStore->read()['token'];
    }

    /**
     * When the recovery of a lost rotation has run out (its window has passed, or it has used its
     * retries), the token to store instead of a refresh: the marker cleared and the normal backoff.
     * Null when there is no such recovery.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>|null
     */
    private static function abandonedRecovery(array $token): ?array
    {
        if (!self::recoveryPendingFor($token)) {
            return null;
        }

        $age = time() - (int) $token['refresh_pending_at'];
        if ($age <= self::RECOVERY_END && (int) ($token['refresh_pending_tries'] ?? 0) < self::RECOVERY_RETRIES) {
            return null;
        }

        return ['refresh_retry_at' => time() + self::REFRESH_BACKOFF] + self::withoutRecovery($token);
    }

    /**
     * Whether the token has a recovery marker for the refresh token it stores now.
     *
     * @param array<string, mixed> $token
     */
    private static function recoveryPendingFor(array $token): bool
    {
        return is_numeric($token['refresh_pending_at'] ?? null)
            && ($token['refresh_pending_hash'] ?? null) === hash('sha256', (string) ($token['refresh_token'] ?? ''));
    }

    /**
     * After an unknown outcome: keep the tokens, record (or count) the recovery, and wait until the
     * portal can recover the rotation.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private static function pendingRecovery(array $token): array
    {
        $pending = self::recoveryPendingFor($token);

        return [
            'refresh_pending_at' => $pending ? (int) $token['refresh_pending_at'] : time(),
            'refresh_pending_hash' => hash('sha256', (string) $token['refresh_token']),
            'refresh_pending_tries' => $pending ? (int) ($token['refresh_pending_tries'] ?? 0) + 1 : 0,
            'refresh_retry_at' => time() + self::RECOVERY_START,
        ] + $token;
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private static function withoutRecovery(array $token): array
    {
        return array_diff_key($token, array_flip(self::RECOVERY_FIELDS));
    }

    /**
     * The token to store after a refresh attempt.
     *
     * @param array<string, mixed> $token
     * @param array<string, mixed>|WP_Error $response
     * @return array<string, mixed>
     */
    private static function refreshOutcome(array $token, array|WP_Error $response): array
    {
        if (is_array($response)) {
            $refreshed = self::tokenFromResponse($response, (string) ($token['scope'] ?? 'full_access'));
            // A response without a refresh token did not rotate it: keep the one that still works.
            $refreshed['refresh_token'] ??= $token['refresh_token'];

            return $refreshed;
        }

        if ($response->get_error_code() === self::UNKNOWN_REFRESH) {
            // The portal may have rotated the pair without this request seeing the answer.
            return self::pendingRecovery($token);
        }

        if (in_array($response->get_error_code(), self::FINAL_REFRESH_ERRORS, true)) {
            // The portal no longer accepts this refresh token: show Login again. Publishing is not
            // affected, because it uses the project token.
            return ['refresh_token' => null, 'expired' => true] + self::withoutRecovery($token);
        }

        // A definite passing failure: keep the stored tokens and wait before the next try.
        return ['refresh_retry_at' => time() + self::REFRESH_BACKOFF] + self::withoutRecovery($token);
    }

    /**
     * POST the refresh grant once. Returns the response data, or an error: the OAuth error code of an
     * HTTP 400 answer (invalid_grant, invalid_client), refresh_unknown when the outcome is unknown, or
     * refresh_failed.
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
            // A timeout or a network error: the request may have reached the portal.
            return self::refreshError(self::UNKNOWN_REFRESH);
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($statusCode === 200 && is_array($data) && !empty($data['access_token'])) {
            return $data;
        }

        return self::refreshError(self::refreshErrorCode($statusCode, $data));
    }

    /**
     * The error code of a refresh answer that is not a usable 200.
     */
    private static function refreshErrorCode(int $statusCode, mixed $data): string
    {
        if ($statusCode === 200 || $statusCode >= 500) {
            return self::UNKNOWN_REFRESH;
        }

        if ($statusCode !== 400 || !is_array($data) || !is_string($data['error'] ?? null)) {
            return 'refresh_failed';
        }

        return self::isJustRotated($data) ? self::UNKNOWN_REFRESH : $data['error'];
    }

    /**
     * Whether a 400 answer says the refresh token was just rotated: the portal saw this request's token
     * rotate within the last 30 s, maybe by this site's own lost request. Not final.
     *
     * @param array<string, mixed> $data
     */
    private static function isJustRotated(array $data): bool
    {
        return $data['error'] === 'invalid_grant'
            && is_string($data['error_description'] ?? null)
            && stripos($data['error_description'], 'just rotated') !== false;
    }

    private static function refreshError(string $code): WP_Error
    {
        return new WP_Error($code, __('Could not refresh the Quant login.', 'quant'));
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

        return self::canRefresh($token) && ($token['access_token'] ?? null) === $rejectedAccessToken;
    }

    /**
     * Whether a refresh may run on this token: it has a refresh token, has not been given up, is not in
     * the backoff after a failed try, and belongs to this site.
     *
     * @param array<string, mixed> $token
     */
    private static function canRefresh(array $token): bool
    {
        $retryAt = $token['refresh_retry_at'] ?? null;

        return !empty($token['refresh_token'])
            && empty($token['expired'])
            && !(is_numeric($retryAt) && (int) $retryAt > time())
            && self::isThisSite($token);
    }

    /**
     * Whether a token may be refreshed and expires within the refresh margin.
     *
     * @param array<string, mixed> $token
     */
    private static function isDue(array $token): bool
    {
        return self::canRefresh($token)
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

    /**
     * The error that asks the user to log in again.
     *
     * @param array<string, mixed> $token
     */
    private static function expiredError(array $token = []): WP_Error
    {
        if (!self::isThisSite($token)) {
            return new WP_Error(
                'site_changed',
                __('This Quant login belongs to another copy of this site. Reconnect this site to Quant.', 'quant'),
                ['status' => 401]
            );
        }

        return new WP_Error('token_expired', __('OAuth token has expired. Please reconnect.', 'quant'), ['status' => 401]);
    }

    /**
     * Whether a token was stored by this site. A copy of the database (production to staging) carries the
     * same refresh token; only one copy may use it, or the copies revoke each other's logins. Tokens
     * from before 2.0.2 have no fingerprint and count as this site's.
     *
     * @param array<string, mixed> $token
     */
    private static function isThisSite(array $token): bool
    {
        return !isset($token['site']) || hash_equals((string) $token['site'], self::siteFingerprint());
    }

    private static function siteFingerprint(): string
    {
        return hash('sha256', (string) home_url());
    }

    /**
     * Whether the plugin treats a stored token as expired.
     *
     * A token without a refresh token is a legacy token: the plugin never expires it, whatever its
     * expires_at says (2.0.1 stored connect time + 24 h for tokens that do not expire). The portal is the
     * authority for those; a 401 from it asks the user to reconnect.
     *
     * A token stored by another copy of the site counts as expired here.
     *
     * @param array<string, mixed> $token
     */
    private static function isExpired(array $token): bool
    {
        if (!empty($token['expired']) || !self::isThisSite($token)) {
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
