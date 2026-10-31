// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, wp } = require('../lib/wp');
const { processQueue, queueJobs } = require('../lib/queue');
const { gotoQuant, screenshot } = require('../lib/admin');

test.describe('admin integration', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('post list column, row action and admin bar show the sync status', async ({ page }) => {
    wp(['quant', 'sync', '4', '--process']);

    await page.goto('/wp-admin/edit.php');
    await expect(page.locator('th#quant_sync')).toHaveText('Quant');
    const row = page.locator('tr#post-4');
    await expect(row.locator('.quant-sync-status')).toContainText('Synced');
    await screenshot(page, 'post-list-column');

    await row.hover();
    await row.getByRole('link', { name: 'Push to Quant' }).click();
    await page.waitForURL(/quant_queued=4/);
    const jobs = queueJobs();
    expect(jobs.some((j) => j.type === 'post' && j.object_id === '4' && j.status === 'pending')).toBe(true);
    processQueue();

    await page.goto('/hello-quant/');
    await expect(page.locator('#wp-admin-bar-quant-sync-status')).toContainText('Quant: Synced');
  });

  test('the dashboard shows queue statistics', async ({ page }) => {
    wp(['quant', 'sync', '--type=page']);
    await gotoQuant(page, 'dashboard');
    await expect(page.getByText('Connected to Quant')).toBeVisible();
    const published = wp(['post', 'list', '--post_type=page', '--post_status=publish', '--format=count']);
    const pending = page.locator('.quant-stat--info .quant-stat__value');
    await expect(pending).toHaveText(published);
    await screenshot(page, 'dashboard-queue');
    processQueue();
  });
});
