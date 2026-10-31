// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, seedV1Options, optionJson, CREDENTIALS, wp } = require('../lib/wp');
const { gotoQuant, rest, screenshot } = require('../lib/admin');

test.describe('migration from 1.x', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    seedV1Options();
    // Every wp-cli call boots the plugin and stamps quant_version. Remove the stamp so the
    // next request behaves like the first request after the plugin files were updated.
    wp(['option', 'delete', 'quant_version'], { allowFailure: true });
  });

  test('settings migrate automatically on the first admin request', async ({ page }) => {
    await page.goto('/wp-admin/index.php');

    const credentials = optionJson('quant_credentials');
    expect(credentials.organization).toBe(CREDENTIALS.organization);
    expect(credentials.project).toBe(CREDENTIALS.project);
    expect(credentials.token).toBe(CREDENTIALS.token);
    expect(credentials.endpoint).toBe(CREDENTIALS.endpoint);
    expect(credentials.http_request_timeout).toBe(15);

    const staticConfig = optionJson('quant_static_config');
    expect(staticConfig.route_404).toBe('/404');
    expect(staticConfig.custom_routes).toContain('/robots.txt');
    expect(staticConfig.webserver_url).toBe('http://localhost');
    expect(staticConfig.webserver_host).toBe('localhost:8088');

    const sync = optionJson('quant_sync_settings');
    expect(sync.auto_sync).toBe(true);
    expect(sync.auto_purge).toBe(true);

    const schedule = optionJson('quant_schedule');
    expect(schedule.enabled).toBe(true);
    expect(schedule.interval).toBe('daily');
    expect(schedule.types).toEqual(expect.arrayContaining(['home', 'post']));
    expect(schedule.custom_routes).toContain('/robots.txt');

    expect(wp(['option', 'get', 'quant_migration_complete'])).toBe('1');
    expect(wp(['option', 'get', 'quant_mode'])).toBe('static');

    await expect(page.locator('.notice').filter({ hasText: 'migrated automatically' })).toBeVisible();
    await screenshot(page, 'migration-notice');

    await gotoQuant(page, 'migration');
    await expect(page.getByRole('heading', { name: 'Migration Complete' })).toBeVisible();
    await screenshot(page, 'migration-complete');

    // v1 options stay until cleanup.
    expect(optionJson('wp_quant_settings')).toBeTruthy();
    const result = await rest(page, '/migration/cleanup', { method: 'POST' });
    expect(result.success).toBe(true);
    expect(optionJson('wp_quant_settings')).toBeNull();
  });

  test('the Migration page offers the cleanup button after migration', async ({ page }) => {
    await gotoQuant(page, 'migration');
    await expect(page.getByRole('button', { name: 'Clean Up v1 Data' })).toBeVisible();
  });

  test('the legacy Settings > QuantCDN URL redirects to the new page', async ({ page }) => {
    const response = await page.request.get('/wp-admin/options-general.php?page=quant', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toContain('admin.php?page=quant');
  });
});
