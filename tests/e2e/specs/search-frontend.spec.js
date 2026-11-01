// @ts-check
/**
 * The public Quant Search page and autocomplete box, with Algolia answered by
 * Playwright so the bundled InstantSearch and Autocomplete code really runs.
 */
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, wp, mockFetch } = require('../lib/wp');
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

/**
 * Answer Typesense multi_search the way search.quantgov.cloud does, with one
 * hit and a category facet. Records each search and the API key it carried.
 */
async function fakeTypesense(page, seen) {
  await page.route(/search\.quantgov\.cloud(:443)?\/multi_search/, async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' } });
    }
    const url = new URL(request.url());
    const key = request.headers()['x-typesense-api-key'] || url.searchParams.get('x-typesense-api-key');
    const searches = (JSON.parse(request.postData() || '{}').searches || []).map((search) => ({ ...search, key }));
    seen.push(...searches);
    const results = searches.map((search) => ({
      found: 1,
      out_of: 1,
      page: 1,
      search_time_ms: 1,
      request_params: { collection_name: search.collection, per_page: search.per_page || 10, q: search.q },
      facet_counts: (search.facet_by || '').split(',').filter(Boolean).map((field) => ({
        field_name: field,
        counts: field === 'category_en' ? [{ value: 'News', count: 1, highlighted: 'News' }] : [],
        stats: {},
      })),
      hits: [{
        document: { id: '1', title: HIT.title, summary: HIT.summary, url: HIT.url, category_en: HIT.category_en },
        highlights: [],
        highlight: {},
        text_match: 1,
      }],
    }));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': '*' },
      body: JSON.stringify({ results }),
    });
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

  test('on QuantGov the search page and autocomplete query Typesense', async ({ page, browser }) => {
    await mockFetch('POST', '/_mock/platform', { mode: 'aws', custom_ranking: ['desc(date)'] });
    // Loading the admin Search page clears the cached backend.
    await gotoQuant(page, 'search');
    await expect(page.getByText('Typesense')).toBeVisible();
    await rest(page, '/search/settings', { method: 'POST', data: { enabled: true } });
    const searchPage = await rest(page, '/search/pages', {
      method: 'POST',
      data: {
        label: 'Gov Search',
        route: '/gov-search',
        title: 'Gov Search',
        post_types: ['post'],
        facets: [
          { facet_type: 'taxonomy', taxonomy: 'category', facet_heading: 'Category', facet_display: 'checkbox' },
          { facet_type: 'custom', custom_key: 'never_registered', facet_heading: 'Ghost', facet_display: 'checkbox' },
        ],
      },
    });
    pageIds.push(searchPage.page_id);
    // Saving the page registers its facets. Unregister the custom one, as on a
    // project that moved backends, so the page must drop it instead of failing.
    const registered = (await (await fetch('http://localhost:4000/_mock/state')).json()).facets;
    expect(registered).toContain('never_registered');
    const boxId = wp(['post', 'create', '--post_type=page', '--post_title=Gov Box', '--post_name=gov-box', '--post_status=publish', `--post_content=[quant_search_autocomplete page="${searchPage.id}"]`, '--porcelain']).trim();
    pageIds.push(boxId);
    await mockFetch('POST', '/_mock/platform', {
      mode: 'aws',
      custom_ranking: ['desc(date)'],
      facets: registered.filter((facet) => facet !== 'never_registered'),
    });
    wp(['eval', 'delete_transient("quant_search_index_config");']);

    const visitor = await browser.newPage({ storageState: { cookies: [], origins: [] } });
    const errors = [];
    visitor.on('pageerror', (e) => errors.push(e.message));
    const seen = [];
    const algolia = [];
    await fakeTypesense(visitor, seen);
    await fakeAlgolia(visitor, algolia);

    await visitor.goto('/gov-search/?keys=hello');
    await expect(visitor.locator('.quant-search .ais-SearchBox-input')).toHaveValue('hello');
    await expect(visitor.locator('.quant-search__hit-title')).toHaveText('Hello Quant');
    await expect(visitor.locator('.quant-search .ais-RefinementList-labelText').first()).toHaveText('News');
    await expect(visitor.locator('.quant-search .ais-Stats')).toContainText('1 result');

    const main = seen.find((search) => search.q === 'hello');
    expect(main, 'searched for the query').toBeTruthy();
    expect(main.collection).toBe('test-index');
    expect(main.key).toBe('TS-READ-KEY');
    expect(main.filter_by).toContain('content_type:=`post`');
    expect(main.sort_by).toBe('_text_match:desc,date:desc');
    expect(main.query_by).toBe('title,content,summary');
    expect(algolia, 'no Algolia requests').toEqual([]);
    expect(seen.every((search) => !(search.facet_by || '').includes('never_registered')), 'unregistered facet dropped').toBe(true);
    await expect(visitor.getByText('Ghost')).toHaveCount(0);
    await screenshot(visitor, 'search-results-typesense');

    await visitor.goto('/gov-box/');
    await visitor.locator('.quant-search-autocomplete .aa-Input').fill('hel');
    await expect(visitor.locator('.aa-Panel .quant-autocomplete__title')).toHaveText('Hello Quant');
    expect(seen.some((search) => search.q === 'hel'), 'autocomplete sends the typed query').toBe(true);

    expect(errors, 'no script errors').toEqual([]);
    await visitor.close();
  });
});
