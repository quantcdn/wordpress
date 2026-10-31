// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, optionJson, mockState, mockRequests, wp, postIdBySlug } = require('../lib/wp');
const { processQueue, waitForMock } = require('../lib/queue');
const { gotoQuant, rest, screenshot, acceptDialogs, notice } = require('../lib/admin');

test.describe('Quant Search', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
    wp(['post', 'delete', '--force', ...wp(['post', 'list', '--post_type=page', '--name=site-search', '--field=ID', '--post_status=any'], { allowFailure: true }).split('\n').filter(Boolean)], { allowFailure: true });
  });

  test.afterEach(async () => {
    // Remove the search page so other specs keep the fixture page count.
    for (const id of wp(['post', 'list', '--post_type=page', '--name=site-search', '--field=ID', '--post_status=any'], { allowFailure: true }).split('\n').filter((v) => /^\d+$/.test(v))) {
      wp(['post', 'delete', id, '--force'], { allowFailure: true });
    }
  });

  test('status, records, pages, reindex, clear and the native search redirect', async ({ page }) => {
    acceptDialogs(page);
    await gotoQuant(page, 'search');

    const status = await rest(page, '/search/status');
    expect(status.enabled).toBe(true);
    expect(status.index.algolia_index).toBe('test-index');
    await expect(page.getByRole('cell', { name: 'test-index' })).toBeVisible();

    // Records on.
    await page.getByLabel('Keep search records updated').check();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(notice(page, 'Search settings saved.')).toBeVisible();
    expect(optionJson('quant_search_settings').enabled).toBe(true);

    // Search page with a category facet.
    await page.getByRole('button', { name: 'Add search page' }).click();
    await page.getByLabel('Label').fill('Site Search');
    await page.getByLabel('Route').fill('/site-search');
    await page.getByRole('button', { name: 'Add facet' }).click();
    await page.getByLabel('Taxonomy', { exact: true }).selectOption('category');
    await page.getByLabel('Heading').fill('Category');
    await page.getByRole('button', { name: 'Save search page' }).click();
    await expect(notice(page, 'Search page saved.')).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Site Search' })).toBeVisible();
    await screenshot(page, 'search-admin');

    const pages = optionJson('quant_search_pages');
    expect(pages).toHaveLength(1);
    expect(pages[0].facets[0]).toMatchObject({ facet_type: 'taxonomy', taxonomy: 'category' });
    const wpPage = wp(['post', 'get', String(pages[0].page_id), '--field=post_content']);
    expect(wpPage).toContain(`[quant_search page="${pages[0].id}"]`);

    const facetRequests = await mockRequests({ path: '/v1/search/facet', method: 'POST' });
    expect(facetRequests.length).toBeGreaterThan(0);
    expect(facetRequests[facetRequests.length - 1].body).toEqual(expect.arrayContaining(['lang_code', 'content_type', 'category_en']));

    // Public page renders the InstantSearch container.
    const publicPage = await page.context().newPage();
    const response = await publicPage.goto('/site-search/');
    expect(response?.status()).toBe(200);
    const container = publicPage.locator('.quant-search[data-quant-search]');
    await expect(container).toBeVisible();
    const config = JSON.parse((await container.getAttribute('data-quant-search')) || '{}');
    expect(config.index.index).toBe('test-index');
    expect(config.facets).toContainEqual(expect.objectContaining({ attribute: 'category_en' }));
    await publicPage.screenshot({ path: `${require('../lib/admin').SCREENS}/search-public.png`, fullPage: true });
    await publicPage.close();

    // Rebuild index.
    await page.getByRole('button', { name: 'Rebuild index' }).click();
    await expect(notice(page, /Queued \d+ records/)).toBeVisible();
    processQueue();
    const state = await waitForMock((s) => s.searchRecords['/hello-quant'], { timeout: 60_000 });
    const record = state.searchRecords['/hello-quant'];
    expect(record.title).toBe('Hello Quant');
    expect(record.url).toBe('/hello-quant');
    expect(record.content_type).toBe('post');
    expect(record.category_en).toEqual(['News']);

    // A page push carries the search record.
    await rest(page, '/sync', { method: 'POST', data: { items: [{ type: 'post', id: postIdBySlug('second-post') }], action: 'sync' } });
    processQueue();
    const publishes = (await mockRequests({ path: '/v1', method: 'POST' })).filter((r) => r.body && r.body.url === '/second-post');
    expect(publishes.length).toBeGreaterThan(0);
    expect(publishes[publishes.length - 1].body.search_record).toMatchObject({ title: 'Second Post', content_type: 'post' });

    // Clear index.
    await page.getByRole('button', { name: 'Clear index' }).click();
    await expect(notice(page, 'Search index cleared.')).toBeVisible();
    const clears = await mockRequests({ path: '/v1/search/all', method: 'DELETE' });
    expect(clears).toHaveLength(1);
    expect((await mockState()).searchRecords).toEqual({});

    // Native search goes to the search page.
    await rest(page, '/search/settings', { method: 'POST', data: { replace_wp_search: true, default_page: pages[0].id } });
    const redirect = await page.request.get('/?s=hello', { maxRedirects: 0 });
    expect(redirect.status()).toBe(302);
    expect(redirect.headers().location).toMatch(/\/site-search\/?\?keys=hello$/);
  });
});
