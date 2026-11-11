const { test, expect } = require('@playwright/test');

test.describe('real work-time windows workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 60000, retries: 0 });
  test.skip(
    process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1',
    'requires the isolated MariaDB UI runner'
  );

  async function login(page, flow) {
    await page.goto('/app/login');
    await page.locator('#loginForm input[name="email"]').fill(`ui-worktime-${flow}@example.test`);
    await page.locator('#loginForm input[name="password"]').fill(process.env.UI_TEST_WORKTIME_PASSWORD);
    await Promise.all([
      page.waitForURL('**/app/heute'),
      page.locator('#loginForm button[type="submit"]').click()
    ]);
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
