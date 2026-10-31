// @ts-check
/**
 * Helpers shared by the e2e specs: WP-CLI inside Docker, the mock Quant API,
 * and plugin state fixtures.
 */
const path = require('path');
const { execFileSync } = require('child_process');

const COMPOSE_FILE = path.resolve(__dirname, '..', '..', '..', 'docker-compose.e2e.yml');
const MOCK_URL = process.env.E2E_MOCK_URL || 'http://localhost:4000';

const CREDENTIALS = {
  organization: 'test-org',
  project: 'test-project',
  token: 'test-token-123',
  endpoint: 'http://mock-api:4000',
};

/**
 * Run a WP-CLI command inside the wpcli container and return stdout.
 * @param {string[]|string} args
 */
function wp(args, { allowFailure = false } = {}) {
  const list = Array.isArray(args) ? args : args.split(' ');
  const cmd = ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'wpcli', 'wp', ...list, '--path=/var/www/html'];
  try {
    return execFileSync('docker', cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 50 * 1024 * 1024 }).trim();
  } catch (err) {
    if (allowFailure) return (err.stdout || '') + (err.stderr || '');
    throw new Error(`wp ${list.join(' ')} failed: ${err.stderr || err.stdout || err.message}`);
  }
}

/** Run arbitrary PHP in WordPress context. */
function wpEval(php) {
  return wp(['eval', php]);
}

function optionUpdateJson(name, value) {
  return wp(['option', 'update', name, JSON.stringify(value), '--format=json']);
}

function debugLog() {
  return wp(['eval', 'echo @file_get_contents("/var/www/html/wp-content/debug.log");'], { allowFailure: true });
}

// ---------------------------------------------------------------------------
// Mock API
// ---------------------------------------------------------------------------

async function mockFetch(method, pathname, body, attempt = 0) {
  try {
    // Connection: close avoids undici reusing a socket the mock has already closed.
    const res = await fetch(`${MOCK_URL}${pathname}`, {
      method,
      headers: { Connection: 'close', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) { /* non-JSON */ }
    return { status: res.status, json, text };
  } catch (err) {
    if (attempt < 3) {
      await new Promise((r) => setTimeout(r, 250 * (attempt + 1)));
      return mockFetch(method, pathname, body, attempt + 1);
    }
    throw err;
  }
}

async function mockGet(pathname) {
  return (await mockFetch('GET', pathname)).json;
}

/** @param {{path?: string, method?: string}} filter */
async function mockRequests(filter = {}) {
  const qs = new URLSearchParams();
  if (filter.path) qs.set('path', filter.path);
  if (filter.method) qs.set('method', filter.method);
  return mockGet(`/_mock/requests${qs.toString() ? '?' + qs : ''}`);
}

async function mockState() {
  return mockGet('/_mock/state');
}

async function mockReset() {
  return (await mockFetch('DELETE', '/_mock/requests')).json;
}

async function mockFail({ path: p, status = 500, times = 1 }) {
  return (await mockFetch('POST', '/_mock/fail', { path: p, status, times })).json;
}

/** Poll until predicate(state) is truthy or timeout. */
async function waitForMock(predicate, { timeout = 60_000, interval = 1000 } = {}) {
  const start = Date.now();
  let last;
  while (Date.now() - start < timeout) {
    last = await mockState();
    if (await predicate(last)) return last;
    await new Promise((r) => setTimeout(r, interval));
  }
  throw new Error(`waitForMock timed out after ${timeout}ms. Last state: ${JSON.stringify(last).slice(0, 2000)}`);
}

// ---------------------------------------------------------------------------
// Plugin state
// ---------------------------------------------------------------------------

const V2_OPTIONS = [
  'quant_version', 'quant_db_version', 'quant_mode', 'quant_credentials', 'quant_static_config',
  'quant_sync_settings', 'quant_schedule', 'quant_settings', 'quant_internal_token',
  'quant_migration_complete', 'quant_migration_date', 'quant_oauth_token', 'quant_oauth_user',
  'quant_oauth_organizations', 'quant_search_settings', 'quant_search_pages',
];
const V1_OPTIONS = ['wp_quant_settings', 'wp_quant_seed', 'wp_quant_cron_settings'];

/** Remove every plugin option, empty the queue table, clear cron hooks, reset the mock. */
async function resetPlugin() {
  const names = [...V2_OPTIONS, ...V1_OPTIONS].map((n) => `'${n}'`).join(',');
  wpEval(`
    foreach ([${names}] as $o) { delete_option($o); }
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'batch_quant_%' OR option_name LIKE '_transient_quant_%' OR option_name LIKE '_transient_timeout_quant_%'");
    $t = $wpdb->prefix . 'quant_sync_queue';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) { $wpdb->query("TRUNCATE TABLE {$t}"); }
    wp_clear_scheduled_hook('quant_process_queue');
    wp_clear_scheduled_hook('quant_scheduled_sync');
    wp_cache_flush();
    echo 'reset';
  `);
  await mockReset();
}

/**
 * Configure the v2 plugin directly through options (bypasses the UI).
 * @param {{mode?: 'static'|'cdn_only', autoSync?: boolean, autoPurge?: boolean}} opts
 */
function configurePlugin({ mode = 'static', autoSync = true, autoPurge = true } = {}) {
  optionUpdateJson('quant_credentials', CREDENTIALS);
  wp(['option', 'update', 'quant_mode', mode]);
  // http://wordpress resolves from both the web container and the wpcli container.
  optionUpdateJson('quant_static_config', {
    webserver_url: 'http://wordpress',
    webserver_host: 'localhost:8088',
    route_404: '/404',
    custom_routes: ['/robots.txt'],
    domain_rewrites: [],
    strip_host: true,
    proxy_override: false,
    sitemap: true,
    theme_assets: true,
    follow_links: true,
  });
  optionUpdateJson('quant_sync_settings', { auto_sync: autoSync, auto_purge: autoPurge, sync_drafts: false, sync_related: true });
  wp(['cache', 'flush'], { allowFailure: true });
}

/** Write the v1 (1.x) option set so the migration path can be exercised. */
function seedV1Options() {
  optionUpdateJson('wp_quant_settings', {
    api_account: CREDENTIALS.organization,
    api_project: CREDENTIALS.project,
    api_token: CREDENTIALS.token,
    api_endpoint: CREDENTIALS.endpoint,
    enabled: 1,
    purge_on_save: 1,
    disable_tls_verify: 0,
    http_request_timeout: 15,
    webserver_url: 'http://localhost',
    webserver_host: 'localhost:8088',
  });
  optionUpdateJson('wp_quant_seed', { '404_route': '/404', custom_routes: '/robots.txt', domains_strip: '' });
  optionUpdateJson('wp_quant_cron_settings', {
    cron_enabled: 1,
    cron_schedule: 'daily',
    cron_home: 1,
    cron_posts: 1,
    cron_pages: 0,
    cron_categories: 0,
    cron_tags: 0,
    cron_custom_routes: '/robots.txt',
  });
}

/** Read an option as JSON (null when missing). */
function optionJson(name) {
  const out = wp(['option', 'get', name, '--format=json'], { allowFailure: true });
  try { return JSON.parse(out); } catch (e) { return null; }
}

/** Copy a local file into the wpcli container. */
function copyToContainer(localPath, containerPath, service = 'wpcli') {
  execFileSync('docker', ['compose', '-f', COMPOSE_FILE, 'cp', localPath, `${service}:${containerPath}`], { stdio: 'ignore' });
}

/** Look up a post ID by slug (and optional post type). */
function postIdBySlug(slug, type = 'post') {
  return parseInt(wp(['post', 'list', `--post_type=${type}`, `--name=${slug}`, '--field=ID', '--post_status=any']).split('\n')[0], 10);
}

module.exports = {
  COMPOSE_FILE,
  MOCK_URL,
  CREDENTIALS,
  wp,
  wpEval,
  optionUpdateJson,
  debugLog,
  mockFetch,
  mockGet,
  mockRequests,
  mockState,
  mockReset,
  mockFail,
  waitForMock,
  resetPlugin,
  configurePlugin,
  seedV1Options,
  postIdBySlug,
  optionJson,
  copyToContainer,
};
