// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockState, mockRequests, wp, wpEval, copyToContainer } = require('../lib/wp');
const { processQueue, queueJobs, truncateQueue, runCron, waitForMock } = require('../lib/queue');

const NEWS_CATEGORY = parseInt(wp(['term', 'get', 'category', 'news', '--by=slug', '--field=term_id']), 10);

function createPost(slug, title) {
  return parseInt(wp(['post', 'create', `--post_title=${title}`, `--post_name=${slug}`, '--post_status=publish', '--post_author=1', `--post_category=${NEWS_CATEGORY}`, '--post_content=Body text', '--porcelain']), 10);
}

test.describe('automatic sync on content changes', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static', autoSync: true });
  });

  test.afterEach(async () => {
    // Remove the posts this spec created so other specs keep the fixture counts.
    const ids = wp(['post', 'list', '--post_type=post', '--post_status=any', '--fields=ID,post_name', '--format=json'], { allowFailure: true });
    try {
      for (const post of JSON.parse(ids)) {
        if (/^(auto-|renamed-slug)/.test(post.post_name)) {
          wp(['post', 'delete', String(post.ID), '--force'], { allowFailure: true });
        }
      }
    } catch (e) { /* nothing to clean */ }
    wp(['term', 'delete', 'category', 'temp', '--by=slug'], { allowFailure: true });
  });

  test('publishing a post queues the post and its related pages', async () => {
    const id = createPost('auto-one', 'Auto One');
    const jobs = queueJobs();
    const keys = jobs.map((j) => `${j.type}:${j.object_id}`);
    expect(keys).toContain(`post:${id}`);
    expect(keys).toContain('home:/');
    expect(keys).toContain(`taxonomy:${NEWS_CATEGORY}`);
    expect(keys).toContain('author:/author/admin');

    processQueue();
    const state = await mockState();
    expect(state.published['/auto-one']).toBeTruthy();
    expect(state.published['/auto-one'].info.author_name).toBe('admin');
    expect(state.published['/']).toBeTruthy();
    expect(state.published['/category/news']).toBeTruthy();
    expect(state.published['/author/admin']).toBeTruthy();
  });

  test('WP-Cron drains the queue on its own', async () => {
    createPost('auto-cron', 'Auto Cron');
    expect(queueJobs().some((j) => j.status === 'pending')).toBe(true);
    runCron();
    const state = await waitForMock((s) => s.published['/auto-cron'], { timeout: 30_000 });
    expect(state.published['/auto-cron'].info.source).toBe('wordpress');
  });

  test('a slug change redirects the old path and publishes the new one', async () => {
    const id = createPost('auto-rename', 'Auto Rename');
    processQueue();
    truncateQueue();

    wp(['post', 'update', String(id), '--post_name=renamed-slug']);
    processQueue();

    const state = await mockState();
    expect(state.redirects).toContainEqual(expect.objectContaining({ url: '/auto-rename', redirect_url: '/renamed-slug', redirect_http_code: 301 }));
    expect(state.published['/renamed-slug']).toBeTruthy();
  });

  test('unpublishing a post removes it from Quant', async () => {
    const id = createPost('auto-draft', 'Auto Draft');
    processQueue();

    wp(['post', 'update', String(id), '--post_status=draft']);
    processQueue();
    await waitForMock((s) => s.unpublished.includes('/auto-draft'), { timeout: 30_000 });

    const requests = await mockRequests({ path: '/v1/unpublish', method: 'PATCH' });
    expect(requests.map((r) => r.headers['quant-url'])).toContain('/auto-draft');
  });

  test('deleting a published post removes it from Quant', async () => {
    const id = createPost('auto-delete', 'Auto Delete');
    processQueue();

    wp(['post', 'delete', String(id), '--force']);
    processQueue();
    await waitForMock((s) => s.unpublished.includes('/auto-delete'), { timeout: 30_000 });
  });

  test('deleting a term unpublishes its listing page', async () => {
    const termId = wp(['term', 'create', 'category', 'Temp', '--slug=temp', '--porcelain']);
    truncateQueue();
    wp(['term', 'delete', 'category', termId]);

    const jobs = queueJobs();
    const unpublish = jobs.find((j) => j.type === 'taxonomy' && j.action === 'unpublish');
    expect(unpublish).toBeTruthy();
    expect(JSON.parse(unpublish.meta).urls).toContain('/category/temp');

    processQueue();
    await waitForMock((s) => s.unpublished.includes('/category/temp'), { timeout: 30_000 });
  });

  test('media uploads and deletions follow the library', async () => {
    copyToContainer(path.join(__dirname, '..', 'fixtures', 'sample.png'), '/tmp/sample-auto.png');
    const id = wp(['media', 'import', '/tmp/sample-auto.png', '--title=Auto Media', '--porcelain']).split('\n').pop();
    processQueue();

    const state = await waitForMock((s) => Object.keys(s.files).some((f) => f.endsWith('/sample-auto.png')), { timeout: 30_000 });
    const fileUrl = Object.keys(state.files).find((f) => f.endsWith('/sample-auto.png'));

    wp(['post', 'delete', String(id).trim(), '--force']);
    processQueue();
    await waitForMock((s) => s.unpublished.includes(fileUrl), { timeout: 30_000 });
  });

  test('Redirection plugin changes are mirrored as Quant redirects', async () => {
    truncateQueue();
    const created = wpEval(`
      $groups = Red_Group::get_all();
      $group_id = !empty($groups) ? (int) $groups[0]["id"] : 1;
      $r = Red_Item::create(["url" => "/auto-redirect", "match_type" => "url", "action_type" => "url", "action_code" => 302, "action_data" => ["url" => "/contact"], "group_id" => $group_id, "status" => "enabled", "regex" => false]);
      echo is_wp_error($r) ? "error" : $r->get_id();
    `);
    expect(created).toMatch(/^\d+$/);

    processQueue();
    const state = await waitForMock((s) => s.redirects.some((r) => r.url === '/auto-redirect'), { timeout: 30_000 });
    expect(state.redirects).toContainEqual(expect.objectContaining({ url: '/auto-redirect', redirect_url: '/contact', redirect_http_code: 302 }));

    wpEval(`$item = Red_Item::get_by_id(${created}); $item->delete(); echo "deleted";`);
    processQueue();
    await waitForMock((s) => s.unpublished.includes('/auto-redirect'), { timeout: 30_000 });
  });
});
