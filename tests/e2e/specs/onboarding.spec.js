// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, CREDENTIALS, optionJson } = require('../lib/wp');
const { gotoQuant, screenshot } = require('../lib/admin');

test.describe('onboarding', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
  });

  test('fresh install walks through the wizard and ends connected', async ({ page }) => {
    await gotoQuant(page, 'onboarding');
    await expect(page.getByRole('heading', { name: 'Welcome to QuantCDN' })).toBeVisible();
    await screenshot(page, 'onboarding-welcome');

    await page.getByRole('button', { name: 'Get Started' }).click();
    await expect(page.getByRole('heading', { name: /Step 1: API Credentials/ })).toBeVisible();

    await page.getByLabel('Organization').fill(CREDENTIALS.organization);
    await page.getByLabel('Project').fill(CREDENTIALS.project);
    await page.getByLabel('API Token').fill(CREDENTIALS.token);
    await page.getByLabel('API Endpoint').fill(CREDENTIALS.endpoint);
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: /Step 2: Choose Mode/ })).toBeVisible();
    await page.getByLabel('Plugin Mode').selectOption('static');
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: /Step 3: Test Connection/ })).toBeVisible();
    await page.getByRole('button', { name: 'Test Connection' }).click();
    await expect(page.getByText('Connection successful!')).toBeVisible();
    await expect(page.getByRole('heading', { name: "You're All Set!" })).toBeVisible({ timeout: 20_000 });
    await screenshot(page, 'onboarding-complete');

    // Regression: the mode step posts only { mode } and must not blank the credentials.
    const credentials = optionJson('quant_credentials');
    expect(credentials.organization).toBe(CREDENTIALS.organization);
    expect(credentials.project).toBe(CREDENTIALS.project);
    expect(credentials.token).toBe(CREDENTIALS.token);
    expect(credentials.endpoint).toBe(CREDENTIALS.endpoint);

    await page.getByRole('button', { name: 'Go to Dashboard' }).click();
    await expect(page.getByText('Connected to Quant')).toBeVisible({ timeout: 20_000 });
    await screenshot(page, 'dashboard');
  });
});
