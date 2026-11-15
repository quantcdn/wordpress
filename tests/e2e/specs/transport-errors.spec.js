// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockRequests, mockFail, wp, postIdBySlug } = require('../lib/wp');
const { processQueue, waitForMock } = require('../lib/queue');
const { gotoQuant, rest } = require('../lib/admin');

/** POST /v1 publish requests for one page. */
async function publishAttempts(url) {
  const requests = await mockRequests({ path: '/v1', method: 'POST' });
  return requests.filter((r) => r.body && r.body.url === url).length;
}

test.describe('Quant API stalls and gateway errors', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('a gateway error is retried in the same run', async ({ page }) => {
    await mockFail({ path: '/v1', status: 503, times: 1 });
    await gotoQuant(page, 'content');
    await rest(page, '/sync', { method: 'POST', data: { items: [{ type: 'post', id: postIdBySlug('third-post') }], action: 'sync' } });
    processQueue();

    await waitForMock((s) => s.published['/third-post'], { timeout: 30_000 });
    expect(await publishAttempts('/third-post')).toBe(2);
    const status = await rest(page, '/queue/status');
    expect(status.failed).toBe(0);
  });

  test('a stalled API shows a short message and the report keeps the raw text', async ({ page }) => {
    // The lowest timeout the plugin accepts; the mock answers after 8 s.
    wp(['option', 'patch', 'insert', 'quant_credentials', 'http_request_timeout', '5']);
    await mockFail({ path: '/v1', status: 504, times: 3, delayMs: 8000 });
    await gotoQuant(page, 'content');
    await rest(page, '/sync', { method: 'POST', data: { items: [{ type: 'post', id: postIdBySlug('third-post') }], action: 'sync' } });
    processQueue();

    // One attempt and two retries in the same run.
    expect(await publishAttempts('/third-post')).toBe(3);
    const errors = await rest(page, '/queue/errors');
    expect(errors).toHaveLength(1);
    expect(errors[0].error_message).toBe('API error publishing /third-post: Quant did not answer in time. The plugin will try again.');
    expect(errors[0].error_detail).toMatch(/^http_request_failed: cURL error 28: .* \(3 attempts\)$/);

    await gotoQuant(page, 'dashboard');
    await expect(page.getByText('Quant did not answer in time. The plugin will try again.')).toBeVisible();
    await expect(page.getByText(/cURL error/)).toHaveCount(0);

    await gotoQuant(page, 'diagnostics');
    await expect(page.getByRole('heading', { name: 'Diagnostics' })).toBeVisible();
    await expect(page.getByText(/cURL error/)).toHaveCount(0);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Download Report' }).click(),
    ]);
    const content = require('fs').readFileSync(await download.path(), 'utf8');
    expect(content).toContain('[post:');
    expect(content).toContain('Quant did not answer in time. The plugin will try again.');
    expect(content).toMatch(/ {3}Detail: http_request_failed: cURL error 28: .* \(3 attempts\)/);
  });
});
