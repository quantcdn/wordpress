// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockState, mockRequests, mockFail, wp } = require('../lib/wp');
const { processQueue, queueStatus, waitForMock } = require('../lib/queue');
const { gotoQuant, rest, screenshot, acceptDialogs, notice } = require('../lib/admin');

const PATHS = ['/', '/hello-quant', '/about', '/books/my-book', '/category/news', '/tag/alpha', '/genre/fiction', '/books', '/_quant404', '/robots.txt', '/wp-sitemap.xml'];

test.describe('content sync (static mode)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('push everything publishes the whole site to the mock API', async ({ page }) => {
    acceptDialogs(page);
    await gotoQuant(page, 'content');
    await expect(page.getByRole('button', { name: 'Push everything' })).toBeEnabled();
    await page.getByRole('button', { name: 'Push everything' }).click();
    await expect(notice(page, /Queued \d+ items/)).toBeVisible();
    await expect(page.locator('.quant-progress')).toBeVisible();

    // The progress card processes five jobs every two seconds; drain the rest with WP-CLI,
    // then wait until nothing is pending and the card has finished.
    processQueue();
    await expect.poll(() => queueStatus().pending + queueStatus().processing, { timeout: 120_000 }).toBe(0);
    await expect(page.locator('.quant-progress')).toHaveCount(0, { timeout: 30_000 });

    const state = await mockState();
    for (const p of PATHS) {
      expect(Object.keys(state.published), `published ${p}`).toContain(p);
    }

    const files = Object.keys(state.files);
    expect(files.some((f) => /\/wp-content\/themes\/.+\.css$/.test(f)), 'theme css uploaded').toBe(true);
    expect(files.some((f) => /\/wp-content\/uploads\/\d{4}\/\d{2}\/sample\.png$/.test(f)), 'media uploaded').toBe(true);
    // The fixture image is small, so WordPress may not generate intermediate sizes.
    const sizes = parseInt(wp(['eval', 'echo count(wp_get_attachment_metadata(10)["sizes"] ?? []);']), 10);
    if (sizes > 0) {
      expect(files.some((f) => /\/wp-content\/uploads\/\d{4}\/\d{2}\/sample-\d+x\d+\.png$/.test(f)), 'resized variant uploaded').toBe(true);
    }

    expect(state.redirects).toContainEqual(expect.objectContaining({ url: '/old-page', redirect_url: '/about', redirect_http_code: 301 }));

    const hello = state.published['/hello-quant'];
    expect(hello.content).not.toContain('localhost:8088');
    expect(hello.content).not.toContain('http://');
    expect(hello.info.source).toBe('wordpress');
    expect(hello.published).toBe(true);

    const samplePage = state.published['/sample-page'];
    expect(samplePage, 'page with an author').toBeTruthy();
    expect(samplePage.info.author_name).toBe('admin');

    expect(state.published['/wp-sitemap.xml'].headers.content_type).toBe('application/xml');
    expect(state.published['/robots.txt'].headers.content_type).toBe('text/plain');
    expect(state.published['/_quant404'].content.length).toBeGreaterThan(100);

    await page.reload();
    await page.locator('#quant-admin-root h1').first().waitFor();
    const row = page.locator('tr', { hasText: 'Hello Quant' });
    await expect(row.locator('.quant-status-badge')).toHaveText('Synced');
    await screenshot(page, 'content-after-push');
  });

  test('push selected republishes only the chosen post', async ({ page }) => {
    await gotoQuant(page, 'content');
    const row = page.locator('tr', { hasText: 'Second Post' });
    await row.getByRole('checkbox').check();
    await page.getByRole('button', { name: /Push selected \(1\)/ }).click();
    await expect(notice(page, /Queued 1 items/)).toBeVisible();
    processQueue();

    const state = await waitForMock((s) => s.published['/second-post'], { timeout: 30_000 });
    expect(Object.keys(state.published)).toEqual(['/second-post']);
  });

  test('unpublish selected sends PATCH /v1/unpublish for the path', async ({ page }) => {
    await gotoQuant(page, 'content');
    await page.getByRole('tab', { name: /Pages/ }).click();
    const row = page.locator('tr', { hasText: 'Contact' });
    await row.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Unpublish selected' }).click();
    await expect(notice(page, /Queued 1 items to unpublish/)).toBeVisible();
    processQueue();

    await waitForMock((s) => s.unpublished.includes('/contact'), { timeout: 30_000 });
    const requests = await mockRequests({ path: '/v1/unpublish', method: 'PATCH' });
    expect(requests.map((r) => r.headers['quant-url'])).toContain('/contact');
  });

  test('a failed push is reported, retried and completes', async ({ page }) => {
    await mockFail({ path: '/v1', status: 500, times: 1 });
    await gotoQuant(page, 'content');
    await rest(page, '/sync', { method: 'POST', data: { items: [{ type: 'post', id: 6 }], action: 'sync' } });
    processQueue();

    const status = await rest(page, '/queue/status');
    expect(status.failed).toBe(1);

    await page.reload();
    await page.locator('#quant-admin-root h1').first().waitFor();
    await expect(page.locator('tr', { hasText: 'Third Post' }).locator('.quant-status-badge')).toHaveText('Failed');

    await gotoQuant(page, 'dashboard');
    await expect(page.getByText('Simulated failure')).toBeVisible();
    await screenshot(page, 'dashboard-failed-job');

    await page.getByRole('button', { name: 'Retry failed' }).click();
    processQueue();
    await waitForMock((s) => s.published['/third-post'], { timeout: 30_000 });

    await gotoQuant(page, 'content');
    await expect(page.locator('tr', { hasText: 'Third Post' }).locator('.quant-status-badge')).toHaveText('Synced');
  });
});
