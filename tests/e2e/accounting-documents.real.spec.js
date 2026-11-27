const { test, expect } = require('@playwright/test');
const fs = require('node:fs/promises');
const path = require('node:path');

async function screenshot(page, testInfo, name) {
  const output = testInfo.outputPath(name + '.png');
  // Bound optional review images for mobile WebKit, whose 2x full-page raster can crash the page.
  const capture = testInfo.project.use.browserName === 'webkit'
    ? { fullPage: false, scale: 'css' }
    : { fullPage: true };
  await page.screenshot({ path: output, ...capture });
  if (process.env.UI_REVIEW_ARTIFACT_DIR) {
    await fs.mkdir(process.env.UI_REVIEW_ARTIFACT_DIR, { recursive: true, mode: 0o700 });
    const reviewPath = path.join(process.env.UI_REVIEW_ARTIFACT_DIR, name + '-' + testInfo.project.name + '.png');
    await fs.copyFile(output, reviewPath);
    await fs.chmod(reviewPath, 0o600);
  }
}

test.describe('real accounting document workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 90000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1',
    'requires the isolated MariaDB UI runner');

  test('admin reviews leave, sickness and core hours and downloads a finalized snapshot', async ({ page }, testInfo) => {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.setViewportSize({ width: 1440, height: 1000 });
    const period = process.env.UI_TEST_BILLING_PERIOD;
    const user = process.env.UI_TEST_BILLING_USER_ID;
    await page.goto('/admin/accounting?' + new URLSearchParams({ type: 'month', period, user_id: user }));
    const summary = page.getByRole('region', { name: 'Abrechnungsübersicht' });
    await expect(summary).toContainText('UI Abrechnung');
    await expect(summary).toContainText('Bestätigter Kontostand');
    await expect(summary).toContainText('Resturlaub am Standdatum');
    await expect(summary).toContainText('Krankheit im Zeitraum');
    await expect(summary).toContainText('UI Abrechnung Kernzeit');
    await expect(summary).toContainText('07:00–16:00');
    const cells = summary.locator('tbody tr').first().locator('td');
    await expect(cells.nth(2)).toHaveText('1');
    await expect(cells.nth(3)).toHaveText('1');
    await expect(cells.nth(5)).toHaveText('29');
    await expect(page.getByText('Der aktuelle Bereich kann festgeschrieben werden.')).toBeVisible();
    await screenshot(page, testInfo, 'accounting-desktop');
    await page.getByRole('button', { name: 'Dunkel', exact: true }).click();
    await screenshot(page, testInfo, 'accounting-desktop-dark');
    await page.getByRole('button', { name: 'Hell', exact: true }).click();

    for (const [name, signature] of [['Vorlaeufiges PDF', '%PDF'], ['Vorlaeufiges Excel', 'PK'], ['Vorlaeufiges ZIP', 'PK']]) {
      const pending = page.waitForEvent('download');
      await page.getByRole('link', { name, exact: true }).click();
      const download = await pending;
      expect(await download.failure()).toBeNull();
      const content = await fs.readFile(await download.path());
      expect(content.subarray(0, signature.length).toString()).toBe(signature);
    }

    const finalize = page.locator('form[action="/admin/accounting/closures"]');
    await finalize.getByRole('checkbox').check();
    await finalize.getByRole('button', { name: 'Abschluss festschreiben', exact: true }).click();
    await expect(page.getByText('Der Abschluss wurde festgeschrieben.', { exact: true })).toBeVisible();
    const finalLink = page.locator('a[href*="/admin/accounting/closures/"][href$="format=xlsx"]').first();
    const response = await page.request.get(await finalLink.getAttribute('href'));
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('spreadsheetml');
    expect((await response.body()).subarray(0, 2).toString()).toBe('PK');

    // CSV stays machine-readable while carrying the same dated personnel fields.
    const account = await page.request.get('/admin/time-accounts/export?' + new URLSearchParams({
      year: period.slice(0, 4), month: String(Number(period.slice(5))), user_id: user, format: 'csv',
    }));
    expect(account.status()).toBe(200);
    const csv = await account.text();
    expect(csv).toContain('Resturlaub am Standdatum (Tage)');
    expect(csv).toContain('Krankheit im Abrechnungszeitraum (Tage)');
    expect(csv).toContain('Kernarbeitszeit Konfiguration');
    expect(csv).toContain('UI Abrechnung Kernzeit');

    await page.goto('/admin/accounting?' + new URLSearchParams({ type: 'month', period, user_id: user }));
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(summary).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await screenshot(page, testInfo, 'accounting-mobile');

    // Project documents use only project scope and omit private annual accounts.
    await page.goto('/admin/accounting?' + new URLSearchParams({ type: 'month', period,
      project_id: process.env.UI_TEST_BILLING_PROJECT_ID }));
    await expect(page.getByRole('region', { name: 'Abrechnungsübersicht' })).toHaveCount(0);
  });
});
