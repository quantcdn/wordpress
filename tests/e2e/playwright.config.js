// @ts-check
const path = require('path');
const { defineConfig } = require('@playwright/test');

const OUTPUT = path.join(__dirname, 'output');

module.exports = defineConfig({
  testDir: path.join(__dirname, 'specs'),
  globalSetup: path.join(__dirname, 'global-setup.js'),
  outputDir: path.join(OUTPUT, 'test-results'),
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { outputFolder: path.join(OUTPUT, 'report'), open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8088',
    storageState: path.join(OUTPUT, 'admin-state.json'),
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
    viewport: { width: 1400, height: 1000 },
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
