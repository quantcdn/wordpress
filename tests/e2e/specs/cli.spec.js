// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, optionUpdateJson, mockState, mockRequests, wp } = require('../lib/wp');
const { queueStatus } = require('../lib/queue');

test.describe('WP-CLI', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('status reports the connection', async () => {
    const out = wp(['quant', 'status']);
    expect(out).toContain('Connected to the Quant API');
    expect(out).toContain('test-project');
  });

  test('sync by type with --process publishes the pages', async () => {
    const out = wp(['quant', 'sync', '--type=page', '--process']);
    expect(out).toMatch(/Queued \d+ item/);
    const state = await mockState();
    expect(state.published['/about']).toBeTruthy();
    expect(state.published['/contact']).toBeTruthy();
    expect(queueStatus().pending).toBe(0);
  });

  test('queue process with threads drains the queue', async () => {
    wp(['quant', 'sync', '--type=post']);
    const out = wp(['quant', 'queue', 'process', '--all', '--threads=3']);
    expect(out).toContain('Workers finished');
    expect(queueStatus().pending).toBe(0);
    expect((await mockState()).published['/hello-quant']).toBeTruthy();
  });

  test('1.x commands still work', async () => {
    expect(wp(['quant', 'info'])).toContain('Connected to the Quant API');
    expect(wp(['quant', 'reset_queue', 'posts'])).toContain('Queue reset');
    const published = wp(['post', 'list', '--post_type=post', '--post_status=publish', '--format=count']);
    expect(queueStatus().pending).toBe(parseInt(published, 10));
    const out = wp(['quant', 'process_queue', 'all', '--threads=2']);
    expect(out).toContain('Workers finished');
    expect(queueStatus().pending).toBe(0);
  });

  test('purge and unpublish hit the API', async () => {
    expect(wp(['quant', 'purge', '/hello-quant'])).toContain('Purged 1 path');
    const purges = await mockRequests({ path: '/v1/purge', method: 'POST' });
    expect(purges.map((r) => r.headers['quant-url']).sort()).toEqual(['/hello-quant', '/hello-quant/']);

    expect(wp(['quant', 'unpublish', '/about'])).toContain('Unpublished /about');
    const unpublish = await mockRequests({ path: '/v1/unpublish', method: 'PATCH' });
    expect(unpublish[0].headers['quant-url']).toBe('/about');
  });

  test('search --process indexes records', async () => {
    optionUpdateJson('quant_search_settings', { enabled: true, post_types: ['post', 'page'], excluded_post_types: [], summary_source: 'excerpt', image_size: 'thumbnail', replace_wp_search: false, default_page: '' });
    const out = wp(['quant', 'search', '--process']);
    expect(out).toMatch(/Processed \d+ item/);
    const state = await mockState();
    expect(state.searchRecords['/hello-quant']).toMatchObject({ title: 'Hello Quant', content_type: 'post' });
    expect(state.searchRecords['/about']).toMatchObject({ content_type: 'page' });
  });
});
