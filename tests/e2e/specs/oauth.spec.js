// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, optionJson, MOCK_URL } = require('../lib/wp');
const { gotoQuant, screenshot, notice } = require('../lib/admin');

/**
 * The portal URL the plugin redirects the browser to is the in-network mock
 * address (http://mock-api:4000), which the host browser cannot resolve.
 * Re-route that one navigation to the published port of the mock.
 */
async function routePortal(page) {
  await page.route('http://mock-api:4000/**', async (route) => {
    const url = route.request().url().replace('http://mock-api:4000', MOCK_URL);
    const response = await route.fetch({ url, maxRedirects: 0 });
    const headers = response.headers();
    await route.fulfill({ status: response.status(), headers, body: await response.body() });
  });
}

test.describe('oauth', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
  });

  test('login through the portal, pick a project, change it, disconnect', async ({ page }) => {
    await routePortal(page);
    await gotoQuant(page, 'settings');
    await expect(page.getByRole('heading', { name: 'Connect to Quant' })).toBeVisible();

    await page.getByRole('button', { name: 'Login to Quant' }).click();
    await page.waitForURL(/oauth=success/, { timeout: 60_000 });
    await expect(notice(page, 'Connected to Quant. Now select a project below.')).toBeVisible();

    await page.getByLabel('Organization').selectOption('test-org');
    await page.getByLabel('Project').selectOption('test-project');
    await page.getByRole('button', { name: 'Use This Project' }).click();
    await expect(notice(page, 'Project selected.')).toBeVisible();
    await expect(page.locator('.quant-connected-badge')).toContainText('Connected');
    await screenshot(page, 'oauth-connected');

    const credentials = optionJson('quant_credentials');
    expect(credentials.organization).toBe('test-org');
    expect(credentials.project).toBe('test-project');
    expect(credentials.token).toBe('test-token-123');

    const oauth = optionJson('quant_oauth_token');
    expect(oauth.access_token).toBe('mock-access');

    await page.getByRole('button', { name: 'Change Project' }).click();
    await expect(page.getByLabel('Organization')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Switch to This Project' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();

    await page.getByRole('button', { name: 'Disconnect' }).click();
    await expect(notice(page, 'Disconnected from Quant')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Login to Quant' })).toBeVisible();

    const cleared = optionJson('quant_credentials');
    expect(cleared.token).toBe('');
    expect(cleared.project).toBe('');
    expect(optionJson('quant_oauth_token')).toBeFalsy();
  });
});
