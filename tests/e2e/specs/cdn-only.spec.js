// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockRequests, mockReset, wp, postIdBySlug } = require('../lib/wp');
const { gotoQuant, screenshot, acceptDialogs, notice } = require('../lib/admin');

test.describe('CDN only mode', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'cdn_only' });
  });

  test.afterEach(async () => {
    wp(['post', 'update', String(postIdBySlug('hello-quant')), '--post_title=Hello Quant'], { allowFailure: true });
  });

  test('the Content page explains that publishing is off', async ({ page }) => {
    await gotoQuant(page, 'content');
    await expect(notice(page, 'Content publishing is available in Static Site mode.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Push everything' })).toHaveCount(0);
  });

  test('saving a post purges the post, home and its term pages instead of publishing', async () => {
    wp(['post', 'update', String(postIdBySlug('hello-quant')), '--post_title=Updated Hello']);
    const purges = await mockRequests({ path: '/v1/purge', method: 'POST' });
    const urls = purges.map((r) => r.headers['quant-url']);
    expect(urls).toEqual(expect.arrayContaining(['/hello-quant', '/hello-quant/', '/', '/category/news', '/category/news/']));

    const publishes = (await mockRequests({ path: '/v1', method: 'POST' })).filter((r) => r.body && r.body.content);
    expect(publishes).toHaveLength(0);
  });

  test('the Cache page purges listed paths and everything', async ({ page }) => {
    acceptDialogs(page);
    await gotoQuant(page, 'cache');
    await page.getByLabel('Paths to purge').fill('/about\n/contact');
    await page.getByRole('button', { name: 'Purge Selected Paths' }).click();
    await expect(notice(page, 'Cache purged successfully')).toBeVisible();

    let purges = await mockRequests({ path: '/v1/purge', method: 'POST' });
    expect(purges.map((r) => r.headers['quant-url']).sort()).toEqual(['/about', '/about/', '/contact', '/contact/']);
    await screenshot(page, 'cache');

    await mockReset();
    await page.getByRole('button', { name: 'Purge All Cache' }).click();
    await expect(notice(page, 'Entire cache purged')).toBeVisible();
    purges = await mockRequests({ path: '/v1/purge', method: 'POST' });
    expect(purges).toHaveLength(1);
    expect(purges[0].headers['quant-url']).toBe('/*');
  });
});
