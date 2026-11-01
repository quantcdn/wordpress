// @ts-check
/**
 * Regression tests for defects found in the pre-release review (2026-11-01).
 */
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockState, mockRequests, mockReset, wp, optionJson, optionUpdateJson, postIdBySlug } = require('../lib/wp');
const { processQueue, queueStatus, queueJobs, truncateQueue } = require('../lib/queue');
const { gotoQuant } = require('../lib/admin');

const quantUrls = async (path, method) => (await mockRequests({ path, method })).map((r) => r.headers['quant-url']);

test.describe('review regressions', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('publish, draft, publish before the queue runs leaves the post online', async () => {
    const id = wp(['post', 'create', '--post_title=Flip Flop', '--post_status=publish', '--post_author=1', '--porcelain']).trim();
    try {
      wp(['post', 'update', id, '--post_status=draft']);
      wp(['post', 'update', id, '--post_status=publish']);

      const pending = queueJobs().filter((j) => j.object_id === id && j.status === 'pending');
      expect(pending.map((j) => j.action)).toEqual(['publish']);

      await mockReset();
      processQueue();
      expect(await quantUrls('/v1/unpublish', 'PATCH')).not.toContain('/flip-flop');
      expect((await mockState()).published['/flip-flop']?.published).toBe(true);
    } finally {
      wp(['post', 'delete', id, '--force']);
    }
  });

  test('every page of a paginated term listing is pushed', async () => {
    wp(['option', 'update', 'posts_per_page', '1']);
    try {
      wp(['quant', 'sync', '--type=taxonomy', '--process']);
      const published = Object.keys((await mockState()).published);
      expect(published).toEqual(expect.arrayContaining(['/category/news', '/category/news/page/2']));
    } finally {
      wp(['option', 'update', 'posts_per_page', '10']);
    }
  });

  test('publishing purges both slash variants of the page', async () => {
    wp(['quant', 'sync', String(postIdBySlug('hello-quant')), '--process']);
    expect(await quantUrls('/v1/purge', 'POST')).toEqual(expect.arrayContaining(['/hello-quant', '/hello-quant/']));
  });

  test('custom routes never upload PHP, configuration or hidden files', async () => {
    optionUpdateJson('quant_static_config', {
      ...optionJson('quant_static_config'),
      custom_routes: ['/wp-config.php', '/wp-includes/version.php', '/.htaccess', '/wp-includes/js/jquery/jquery.min.js'],
    });
    wp(['quant', 'sync', '--type=custom_routes', '--process'], { allowFailure: true });

    const state = await mockState();
    const files = Object.keys(state.files);
    for (const forbidden of ['/wp-config.php', '/wp-includes/version.php', '/.htaccess']) {
      expect(files, forbidden).not.toContain(forbidden);
    }
    expect(files).toContain('/wp-includes/js/jquery/jquery.min.js');
    const leaked = Object.values(state.published).some((p) => String(p.content || '').includes('DB_PASSWORD'));
    expect(leaked).toBe(false);
  });

  test('a custom route that now returns 404 is unpublished, not failed', async () => {
    optionUpdateJson('quant_static_config', { ...optionJson('quant_static_config'), custom_routes: ['/no-such-page'] });
    wp(['quant', 'sync', '--type=custom_routes', '--process']);
    expect(await quantUrls('/v1/unpublish', 'PATCH')).toContain('/no-such-page');
    expect(queueStatus().failed).toBe(0);
  });

  test('a block editor (REST) save refreshes the category it was saved with', async ({ page }) => {
    await gotoQuant(page, 'dashboard');
    const catId = wp(['term', 'create', 'category', 'Editor Cat', '--porcelain']).trim();
    truncateQueue();
    const post = await page.evaluate(
      (cat) => window.wp.apiFetch({ path: '/wp/v2/posts', method: 'POST', data: { title: 'Block Editor Post', status: 'publish', categories: [cat] } }),
      parseInt(catId, 10)
    );
    try {
      const termJobs = queueJobs().filter((j) => j.type === 'taxonomy' && j.object_id === catId);
      expect(termJobs.length, 'category listing queued').toBeGreaterThan(0);
      processQueue();
      expect(Object.keys((await mockState()).published)).toEqual(expect.arrayContaining(['/block-editor-post', '/category/editor-cat']));
    } finally {
      wp(['post', 'delete', String(post.id), '--force']);
      wp(['term', 'delete', 'category', catId]);
    }
  });

  test('URLs on private addresses become relative (cached plugin output from an internal host)', async () => {
    const id = wp(['post', 'create', '--post_title=Private Host', '--post_status=publish', '--porcelain',
      '--post_content=<img src="//10.3.12.115/wp-content/uploads/x.jpg"><a href="http://192.168.1.20:8080/about">About</a>']).trim();
    try {
      wp(['quant', 'sync', id, '--process']);
      const content = (await mockState()).published['/private-host'].content;
      expect(content).toContain('src="/wp-content/uploads/x.jpg"');
      expect(content).toContain('href="/about"');
      expect(content).not.toMatch(/10\.3\.12\.115|192\.168\.1\.20/);
    } finally {
      wp(['post', 'delete', id, '--force']);
    }
  });

  test('CDN only: unpublishing a post purges its page and listings', async () => {
    configurePlugin({ mode: 'cdn_only' });
    const id = wp(['post', 'create', '--post_title=Cdn Flip', '--post_status=publish', '--porcelain']).trim();
    try {
      await mockReset();
      wp(['post', 'update', id, '--post_status=draft']);
      expect(await quantUrls('/v1/purge', 'POST')).toEqual(expect.arrayContaining(['/cdn-flip', '/cdn-flip/', '/']));
    } finally {
      wp(['post', 'delete', id, '--force']);
    }
  });
});
