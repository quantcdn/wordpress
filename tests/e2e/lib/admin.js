// @ts-check
/**
 * Browser helpers for the Quant admin UI.
 */
const path = require('path');
const fs = require('fs');

const SCREENS = path.join(__dirname, '..', 'output', 'screens');

/** Open a Quant admin route (hash router) and wait for the React app. */
async function gotoQuant(page, route = 'dashboard') {
  await page.goto(`/wp-admin/admin.php?page=quant#/${route}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#quant-admin-root').waitFor({ state: 'attached' });
  await page.locator('#quant-admin-root h1').first().waitFor({ timeout: 30_000 });
}

/** Call a quant/v1 REST endpoint through the admin page's authenticated apiFetch. */
async function rest(page, endpoint, { method = 'GET', data } = {}) {
  if (!page.url().includes('wp-admin')) {
    await gotoQuant(page, 'dashboard');
  }
  return page.evaluate(
    ({ endpoint: ep, method: m, data: d }) => window.wp.apiFetch({ path: `/quant/v1${ep}`, method: m, data: d }),
    { endpoint, method, data }
  );
}

/** A WordPress admin notice (ignores the aria-live announcement copy). */
function notice(page, text) {
  return page.locator('.components-notice__content').filter({ hasText: text });
}

/** Save a full-page screenshot under output/screens/<name>.png. */
async function screenshot(page, name) {
  fs.mkdirSync(SCREENS, { recursive: true });
  const file = path.join(SCREENS, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

/** Accept every confirm() dialog on the page. */
function acceptDialogs(page) {
  page.on('dialog', (dialog) => dialog.accept());
}

module.exports = { gotoQuant, rest, notice, screenshot, acceptDialogs, SCREENS };
