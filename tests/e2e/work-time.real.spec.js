const { test, expect } = require('@playwright/test');
const { createHash } = require('crypto');

test.describe('real work-time windows workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 60000, retries: 0 });
  test.skip(
    process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1',
    'requires the isolated MariaDB UI runner'
  );

  async function login(page, flow) {
    const requests = [];
    const fingerprint = (value) => value ? createHash('sha256').update(value).digest('hex').slice(0, 12) : null;
    const captureResponse = async (response) => {
      const pathname = new URL(response.url()).pathname;
      if (!pathname.startsWith('/api/') && pathname !== '/app' && pathname !== '/app/sw.js') return;
      try {
        const headers = await response.allHeaders();
        requests.push({ path: pathname, status: response.status(), requestCookie: fingerprint(response.request().headers().cookie), setCookie: fingerprint(headers['set-cookie']?.split(';')[0]) });
      } catch (_) { /* A failed navigation can dispose its response before diagnostics finish. */ }
    };
    const captureError = (error) => requests.push({ pageerror: error.message });
    page.on('response', captureResponse);
    page.on('pageerror', captureError);
    await page.goto('/app/login');
    await page.locator('#loginForm input[name="email"]').fill(`ui-worktime-${flow}@example.test`);
    await page.locator('#loginForm input[name="password"]').fill(process.env.UI_TEST_WORKTIME_PASSWORD);
    try {
      const result = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/auth/login', { timeout: 10000 });
      await page.locator('#loginForm button[type="submit"]').click();
      const response = await result;
      expect(response.ok(), await response.text()).toBeTruthy();
      await expect(page).toHaveURL(/\/app\/heute$/);
    } catch (error) {
      const form = await page.locator('#loginForm').evaluate((element) => ({ email: element.elements.email.value, passwordPresent: element.elements.password.value.length > 0, valid: element.checkValidity() })).catch(() => null);
      await test.info().attach('login-diagnostics.json', { body: Buffer.from(JSON.stringify({ url: page.url(), form, requests }, null, 2)), contentType: 'application/json' });
      throw error;
    } finally {
      page.off('response', captureResponse);
      page.off('pageerror', captureError);
    }
    await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toBeVisible();
  }

  async function history(page) {
    const response = await page.request.get('/api/v1/app/me/timesheets?scope=all');
    expect(response.ok()).toBeTruthy();
    return (await response.json()).data.items;
  }

  test('an unconfirmed proposal and continuing work preserve the actual open session', async ({ page }) => {
    await login(page, 'continue');
    const id = Number(process.env.UI_TEST_WORKTIME_CONTINUE_ID);
    const before = (await history(page)).find((row) => row.id === id);
    expect(before.end_time).toBeNull();
    const statusBefore = await (await page.request.get('/api/v1/app/me/work-time')).json();
    expect(statusBefore.data.checkout_proposals).toHaveLength(1);
    await page.getByRole('button', { name: 'Ich arbeite weiter', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
    expect((await history(page)).find((row) => row.id === id).end_time).toBeNull();
    const manualOut = await page.request.post('/api/v1/app/timesheets/sync', { data: {
      client_request_id: 'real-ui-continue-manual-out', action: 'check_out', work_date: process.env.UI_TEST_WORKTIME_WORK_DATE,
      end_time: process.env.UI_TEST_WORKTIME_NOW_END, expected_timesheet_id: id,
    } });
    expect(manualOut.ok(), await manualOut.text()).toBeTruthy();
    expect((await history(page)).find((row) => row.id === id).end_time).toBeTruthy();
  });

  test('explicit confirmation closes the proposed session and permits a new check-in', async ({ page }) => {
    await login(page, 'confirm');
    const id = Number(process.env.UI_TEST_WORKTIME_CONFIRM_ID);
    expect((await history(page)).find((row) => row.id === id).end_time).toBeNull();
    const proposal = page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true });
    await proposal.locator('[data-worktime-response="confirm"]').click();
    await expect(proposal).toHaveCount(0);
    expect((await history(page)).find((row) => row.id === id).end_time).toBe(process.env.UI_TEST_WORKTIME_END);
    await page.goto('/app/heute');
    const checkIn = page.locator('[data-action="check_in"]').first();
    await expect(checkIn).toBeVisible();
    await checkIn.click();
    await page.locator('#projectlessDialogNote').fill('Zweiter echter Einsatz nach bestaetigtem Abschluss');
    await page.locator('#dialogConfirmPrimary').click();
    await expect.poll(async () => (await history(page)).filter((row) => row.entry_type === 'work' && row.end_time === null).length).toBe(1);
    const rows = await history(page);
    expect(rows.filter((row) => row.entry_type === 'work')).toHaveLength(2);
    expect(rows.find((row) => row.id === id).end_time).toBe(process.env.UI_TEST_WORKTIME_END);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
  });

  test('admin previews a rule before creating an immutable version', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.goto('/admin/work-time');
    const form = page.locator('form[action="/admin/work-time/templates"]');
    await form.locator('[name="name"]').fill('Browser Kernzeit 08 bis 16');
    const save = form.locator('[data-worktime-save]');
    await expect(save).toBeDisabled();
    await form.getByRole('button', { name: 'Beispiel prüfen', exact: true }).click();
    await expect(form.locator('[data-worktime-result]')).toContainText('Tatsächliche Arbeit: 503 Min.');
    await expect(form.locator('[data-worktime-result]')).toContainText('Im Fenster: 450 Min.');
    await expect(form.locator('[data-worktime-result]')).toContainText('Offene Außenzeit: 53 Min.');
    await expect(save).toBeEnabled();
    await save.click();
    await expect(page.getByText('Gespeichert. Historische Regeln und Stempelzeiten bleiben erhalten.', { exact: true })).toBeVisible();
    await expect(page.locator('table tbody tr').filter({ hasText: 'Browser Kernzeit 08 bis 16' })).toHaveCount(1);
  });
});
