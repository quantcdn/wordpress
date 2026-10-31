// @ts-check
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { chromium } = require('@playwright/test');

const ROOT = __dirname;
const OUTPUT = path.join(ROOT, 'output');
const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:8088';
const ADMIN_USER = process.env.E2E_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'password';

async function login() {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await Promise.all([
    page.waitForURL(/wp-admin/, { timeout: 60_000 }),
    page.click('#wp-submit'),
  ]);
  await page.context().storageState({ path: path.join(OUTPUT, 'admin-state.json') });
  await browser.close();
}

module.exports = async () => {
  fs.mkdirSync(OUTPUT, { recursive: true });
  if (process.env.E2E_SKIP_SETUP !== '1') {
    execFileSync('bash', [path.join(ROOT, 'bin', 'setup.sh')], { stdio: 'inherit' });
  }
  await login();
};
