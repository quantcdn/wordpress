// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin } = require('../lib/wp');
const { gotoQuant, screenshot } = require('../lib/admin');

test.describe('diagnostics', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('shows the environment and downloads a report', async ({ page }) => {
    await gotoQuant(page, 'diagnostics');
    await expect(page.getByRole('heading', { name: 'Diagnostics' })).toBeVisible();
    await expect(page.getByRole('cell', { name: /^8\.\d+\.\d+/ })).toBeVisible();
    await expect(page.getByText('Connected', { exact: true })).toBeVisible();
    await expect(page.getByText('Exists', { exact: true })).toBeVisible();
    await screenshot(page, 'diagnostics');

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Download Report' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^quant-diagnostics-\d{4}-\d{2}-\d{2}\.txt$/);
    const file = await download.path();
    const content = require('fs').readFileSync(file, 'utf8');
    expect(content).toContain('QuantCDN Diagnostic Report');
    expect(content).toContain('Connected: Yes');
  });
});
