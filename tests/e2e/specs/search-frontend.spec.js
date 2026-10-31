// @ts-check
/**
 * The public Quant Search page and autocomplete box, with Algolia answered by
 * Playwright so the bundled InstantSearch and Autocomplete code really runs.
 */
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, wp } = require('../lib/wp');
const { gotoQuant, rest, screenshot } = require('../lib/admin');

const HIT = {
  objectID: '1',
  title: 'Hello Quant',
  summary: 'A post about the static edge',
  url: '/hello-quant',
  category_en: ['News'],
  _highlightResult: { title: { value: 'Hello Quant', matchLevel: 'none', matchedWords: [] } },
  _snippetResult: { summary: { value: 'A post about the static edge', matchLevel: 'none' } },
};

/** Answer Algolia multi-queries with one hit and a category facet. */
async function fakeAlgolia(page, seen) {
  await page.route(/algolia(net)?\.(net|com)\/1\/indexes\/\*\/queries/, async (route) => {
    const body = JSON.parse(route.request().postData() || '{}');
    const requests = body.requests || [];
    seen.push(...requests);
    const results = requests.map((r) => ({
      hits: [HIT],
      nbHits: 1,
      page: 0,
      nbPages: 1,
      hitsPerPage: 10,
      exhaustiveNbHits: true,
      processingTimeMS: 1,
      query: '',
      params: r.params || '',
      index: r.indexName,
      facets: { category_en: { News: 1 } },
    }));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ results }) });
  });
}

test.describe('Quant Search front end', () => {
  test.setTimeout(120_000);
  let pageIds = [];

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test.afterEach(() => {
    for (const id of pageIds) {
      wp(['post', 'delete', String(id), '--force'], { allowFailure: true });
    }
    pageIds = [];
  });

  test('the search page and autocomplete render results from the index', async ({ page, browser }) => {
    await gotoQuant(page, 'search');
    await rest(page, '/search/settings', { method: 'POST', data: { enabled: true } });
    const searchPage = await rest(page, '/search/pages', {
      method: 'POST',
      data: {
        label: 'Front Search',
        route: '/front-search',
        title: 'Front Search',
        facets: [{ facet_type: 'taxonomy', taxonomy: 'category', facet_heading: 'Category', facet_display: 'checkbox' }],
      },
    });
    pageIds.push(searchPage.page_id);
    const boxId = wp(['post', 'create', '--post_type=page', '--post_title=Box', '--post_name=search-box', '--post_status=publish', `--post_content=[quant_search_autocomplete page="${searchPage.id}" show_summary="1"]`, '--porcelain']).trim();
    pageIds.push(boxId);

    const visitor = await browser.newPage({ storageState: { cookies: [], origins: [] } });
    const errors = [];
    visitor.on('pageerror', (e) => errors.push(e.message));
    const seen = [];
    await fakeAlgolia(visitor, seen);

    await visitor.goto('/front-search/?keys=hello');
    await expect(visitor.locator('.quant-search .ais-SearchBox-input')).toHaveValue('hello');
    await expect(visitor.locator('.quant-search__hit-title')).toHaveText('Hello Quant');
    await expect(visitor.locator('.quant-search__hit a').first()).toHaveAttribute('href', '/hello-quant');
    await expect(visitor.locator('.quant-search .ais-RefinementList-labelText')).toHaveText('News');
    await expect(visitor.locator('.quant-search .ais-Stats')).toContainText('1 result');
    expect(seen.some((r) => r.indexName === 'test-index'), 'queries the project index').toBe(true);
    await screenshot(visitor, 'search-results');

    await visitor.goto('/search-box/');
    await visitor.locator('.quant-search-autocomplete .aa-Input').fill('hel');
    await expect(visitor.locator('.aa-Panel .quant-autocomplete__title')).toHaveText('Hello Quant');
    await visitor.locator('.quant-search-autocomplete .aa-Input').press('Enter');
    await visitor.waitForURL(/\/front-search\/?\?keys=hel/);

    expect(errors, 'no script errors').toEqual([]);
    await visitor.close();
  });
});
