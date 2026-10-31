// @ts-check
const { test, expect } = require('@playwright/test');
const { MOCK_URL, CREDENTIALS, debugLog } = require('../lib/wp');

test.describe('smoke', () => {
  test('mock API accepts the right credentials and rejects missing ones', async ({ request }) => {
    const ok = await request.get(`${MOCK_URL}/v1/ping`, {
      headers: {
        'Quant-Token': CREDENTIALS.token,
        'Quant-Customer': CREDENTIALS.organization,
        'Quant-Project': CREDENTIALS.project,
      },
    });
    expect(ok.status()).toBe(200);
    const body = await ok.json();
    expect(body.config.search_enabled).toBe(true);

    const denied = await request.get(`${MOCK_URL}/v1/ping`);
    expect(denied.status()).toBe(403);
  });

  test('WordPress front page loads', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('Quant E2E');
  });

  test('admin can open the Quant admin page', async ({ page }) => {
    const response = await page.goto('/wp-admin/admin.php?page=quant');
    if (!response || response.status() >= 500) {
      test.info().attachments.push({ name: 'debug.log', contentType: 'text/plain', body: Buffer.from(debugLog()) });
      throw new Error(`Quant admin page returned HTTP ${response?.status()}. debug.log:\n${debugLog().slice(-4000)}`);
    }
    expect(response.status()).toBe(200);
    await expect(page.locator('#quant-admin-root')).toBeAttached();
    const errorText = await page.locator('body').textContent();
    if (errorText && /Fatal error|Uncaught/.test(errorText)) {
      throw new Error(`PHP error on the Quant admin page:\n${errorText.slice(0, 3000)}`);
    }
  });
});
