// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, CREDENTIALS, optionJson, wp } = require('../lib/wp');
const { gotoQuant, rest, screenshot, notice } = require('../lib/admin');

test.describe('settings', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
  });

  test('manual credentials save and connection tests succeed', async ({ page }) => {
    await gotoQuant(page, 'settings');
    // On a fresh install the manual form sits behind a link next to "Login to Quant".
    await page.getByRole('button', { name: 'Use manual credentials instead' }).click();
    await expect(page.getByRole('heading', { name: 'Project Credentials' })).toBeVisible();

    await page.getByLabel('API Endpoint').fill(CREDENTIALS.endpoint);
    await page.getByLabel('Organization (API Customer)').fill(CREDENTIALS.organization);
    await page.getByLabel('Project', { exact: true }).fill(CREDENTIALS.project);
    await page.getByLabel('API Token').fill(CREDENTIALS.token);
    await page.getByRole('button', { name: 'Save Credentials' }).click();
    await expect(notice(page, 'Settings saved.')).toBeVisible();

    await page.getByRole('button', { name: 'Test Connection' }).click();
    await expect(notice(page, 'Connected to the Quant API.')).toBeVisible();

    const credentials = optionJson('quant_credentials');
    expect(credentials.token).toBe(CREDENTIALS.token);
    expect(credentials.organization).toBe(CREDENTIALS.organization);

    // Internal connectivity: the web container reaches itself at http://localhost.
    await page.getByLabel('Webserver URL').fill('http://localhost');
    await page.getByLabel('Hostname (Host header)').fill('localhost:8088');
    await page.getByRole('button', { name: 'Save Settings' }).click();
    await expect(notice(page, 'Settings saved.')).toBeVisible();
    await page.getByRole('button', { name: 'Test', exact: true }).click();
    await expect(page.getByText('✓ Working')).toBeVisible({ timeout: 30_000 });
    await screenshot(page, 'settings');
  });

  test('toggles persist across reloads', async ({ page }) => {
    configurePlugin();
    await gotoQuant(page, 'settings');

    const follow = page.getByLabel('Follow pagination links');
    await expect(follow).toBeChecked();
    await follow.click();
    await page.getByLabel('Override existing proxies').click();
    await page.getByRole('button', { name: 'Save Settings' }).click();
    await expect(notice(page, 'Settings saved.')).toBeVisible();

    await page.reload();
    await page.locator('#quant-admin-root h1').first().waitFor();
    await expect(page.getByLabel('Follow pagination links')).not.toBeChecked();
    await expect(page.getByLabel('Override existing proxies')).toBeChecked();

    const config = optionJson('quant_static_config');
    expect(config.follow_links).toBe(false);
    expect(config.proxy_override).toBe(true);
  });

  test('a QUANT_* constant overrides the stored value and locks the field', async ({ page }) => {
    configurePlugin();
    wp(['config', 'set', 'QUANT_PROJECT', 'env-project', '--type=constant']);
    try {
      await gotoQuant(page, 'settings');
      // PHP opcache revalidates wp-config.php every couple of seconds; poll until the constant is live.
      await expect.poll(async () => (await rest(page, '/settings')).project, { timeout: 20_000 }).toBe('env-project');
      await page.reload();
      await page.locator('#quant-admin-root h1').first().waitFor();
      const settings = await rest(page, '/settings');
      expect(settings.overridden.credentials).toContain('project');

      const project = page.getByLabel('Project', { exact: true });
      await expect(project).toHaveValue('env-project');
      await expect(project).toBeDisabled();
      await expect(page.getByText('Set by a QUANT_* environment variable or constant.').first()).toBeVisible();
      await screenshot(page, 'settings-env-override');
    } finally {
      wp(['config', 'delete', 'QUANT_PROJECT'], { allowFailure: true });
    }

    await expect.poll(async () => (await rest(page, '/settings')).project, { timeout: 20_000 }).toBe(CREDENTIALS.project);
  });
});
