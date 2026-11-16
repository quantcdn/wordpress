// @ts-check
/**
 * The push progress card reports the outcome of the whole run, whatever
 * processed the jobs: the card's own requests, WP-Cron or WP-CLI.
 */
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, mockFail, wpEval } = require('../lib/wp');
const { processQueue, queueStatus } = require('../lib/queue');
const { gotoQuant, acceptDialogs, notice } = require('../lib/admin');

/** Finished rows in the queue table (resetPlugin empties it, so all rows belong to this run). */
function finishedRows() {
  return JSON.parse(wpEval('global $wpdb; echo json_encode($wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}quant_sync_queue GROUP BY status", OBJECT_K));'));
}

function count(rows, status) {
  return rows[status] ? Number(rows[status].n) : 0;
}

/** Match a browser request to a quant/v1 route, with pretty or plain permalinks. */
function isRoute(url, route) {
  return decodeURIComponent(url).includes(`/quant/v1${route}`);
}

async function pushEverything(page) {
  acceptDialogs(page);
  await gotoQuant(page, 'content');
  await page.getByRole('button', { name: 'Push everything' }).click();
  await expect(notice(page, /Queued \d+ items/)).toBeVisible();
  await expect(page.locator('.quant-progress')).toBeVisible();
}

async function waitForTheEnd(page) {
  await expect.poll(() => queueStatus().pending + queueStatus().processing, { timeout: 120_000 }).toBe(0);
  await expect(page.locator('.quant-progress__title')).toHaveText(/Sync complete/, { timeout: 60_000 });
}

test.describe('push progress counts', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('a run shared by the card and WP-CLI counts every job', async ({ page }) => {
    let browserProcessed = 0;
    page.on('response', async (response) => {
      if (isRoute(response.url(), '/queue/process') && response.ok()) {
        browserProcessed += (await response.json()).processed || 0;
      }
    });

    await pushEverything(page);
    processQueue();
    await waitForTheEnd(page);

    const rows = finishedRows();
    const completed = count(rows, 'completed');
    expect(completed).toBeGreaterThan(0);
    expect(browserProcessed, 'WP-CLI processed part of the run').toBeLessThan(completed);
    await expect(page.locator('.quant-progress__stat--completed')).toHaveText(`${completed} completed`);
    await expect(page.locator('.quant-progress__stat--failed')).toHaveCount(0);
  });

  test('a run that only a background run processed counts every job', async ({ page }) => {
    // The card's batches do nothing, as when WP-Cron claimed every job first.
    await page.route((url) => isRoute(url.toString(), '/queue/process'), (route) =>
      route.fulfill({ json: { success: true, processed: 0, failed: 0, remaining: 0, errors: [] } })
    );

    await pushEverything(page);
    processQueue();
    await waitForTheEnd(page);

    const completed = count(finishedRows(), 'completed');
    expect(completed).toBeGreaterThan(0);
    await expect(page.locator('.quant-progress__stat--completed')).toHaveText(`${completed} completed`);
  });

  test('failures are counted whichever run processed them', async ({ page }) => {
    // A 400 is permanent: no retry, so each failure fails one job.
    await mockFail({ path: '/v1', status: 400, times: 2 });

    await pushEverything(page);
    processQueue();
    await expect.poll(() => queueStatus().pending + queueStatus().processing, { timeout: 120_000 }).toBe(0);
    await expect(page.locator('.quant-progress__title')).toHaveText(/Sync completed with errors/, { timeout: 60_000 });

    const rows = finishedRows();
    expect(count(rows, 'failed')).toBeGreaterThan(0);
    await expect(page.locator('.quant-progress__stat--completed')).toHaveText(`${count(rows, 'completed')} completed`);
    await expect(page.locator('.quant-progress__stat--failed')).toHaveText(`${count(rows, 'failed')} failed`);
    await expect(page.locator('.quant-progress__errors li')).toHaveCount(Math.min(10, count(rows, 'failed')));
  });
});
