const { readFileSync } = require('node:fs');
const { test, expect } = require('@playwright/test');

async function adminLogin(page, email) {
  await page.goto('/admin/login');
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

async function employeeLogin(page, path, email) {
  await page.goto(path);
  const form = page.locator('#loginForm');
  await form.getByLabel('E-Mail').fill(email);
  await form.getByLabel('Passwort').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
  await form.getByRole('button', { name: 'Anmelden', exact: true }).click();
}

async function dispatchFields(page) {
  const form = page.getByRole('region', { name: 'Empfängervorschau' }).locator('form');
  const fields = {};
  for (const name of ['csrf_token', 'version', 'request_key']) {
    fields[name] = await form.locator(`input[name="${name}"]`).inputValue();
  }
  return fields;
}

function pushCalls() {
  const entries = readFileSync(process.env.UI_APPOINTMENT_DISPATCH_LOG, 'utf8').trim();
  return entries ? entries.split('\n').map(entry => JSON.parse(entry)) : [];
}

async function bookingSummary(page, date) {
  const response = await page.request.get(`/admin/calendar/day?date=${date}&view=bookings`);
  expect(response.status()).toBe(200);
  return (await response.json()).data.summary;
}

test.describe('real appointment dispatch and employee confirmation', () => {
  test.describe.configure({ timeout: 90_000, retries: 0 });
  test.use({ actionTimeout: 12_000 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1'
    || process.env.UI_APPOINTMENT_DISPATCH_FIXTURE !== '1', 'requires the isolated MariaDB UI runner and fake PushSender');

  test('sends only assigned staff after confirmation and keeps provider acceptance separate from a versioned acknowledgement', async ({ page, browser, baseURL }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const date = process.env.UI_TEST_APPOINTMENT_DISPATCH_DAY;
    const title = 'UI Einsatzplanung Push';
    const editForm = page.locator('[data-appointment-form]');
    const preview = page.getByRole('region', { name: 'Empfängervorschau' });
    const acknowledge = page.getByRole('region', { name: 'Terminbestätigungen' });
    const employeeAck = acknowledge.getByRole('row').filter({ hasText: 'UI Mitarbeiter' });
    const noDeviceAck = acknowledge.getByRole('row').filter({ hasText: 'UI Materialerfassung' });
    const historyResults = page.locator('section[id^="appointment-dispatch-"]');

    await adminLogin(page, process.env.UI_TEST_ADMIN_EMAIL);
    const originalBookings = await bookingSummary(page, date);
    await page.goto(`/admin/appointments/new?date=${date}`);
    await editForm.getByLabel('Terminbezeichnung').fill(title);
    await editForm.getByLabel('Beginn', { exact: true }).fill(`${date}T10:15`);
    await editForm.getByLabel('Ende', { exact: true }).fill(`${date}T11:30`);
    await editForm.getByRole('combobox', { name: 'Projekt', exact: true }).selectOption(process.env.UI_TEST_APPOINTMENT_DISPATCH_PROJECT);
    await editForm.getByLabel('Einsatzort').fill('Baucontainer');
    await editForm.getByRole('checkbox', { name: 'UI Mitarbeiter', exact: true }).check();
    await editForm.getByRole('checkbox', { name: 'UI Materialerfassung', exact: true }).check();
    await editForm.getByRole('button', { name: 'Termin speichern', exact: true }).click();
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    const detail = new URL(page.url()).pathname;
    const dispatchPath = detail + '/dispatch';
    const historyPath = detail + '/dispatches';
    const id = Number(detail.split('/').at(-1));
    const appPath = `/app/termine?id=${id}`;
    const appApi = `/api/v1/app/appointments/${id}`;
    await expect(employeeAck).toContainText('Ausstehend');
    await expect(noDeviceAck).toContainText('Ausstehend');
    expect(pushCalls()).toEqual([]);

    await page.getByRole('link', { name: 'Termin jetzt per Push senden', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Empfängervorschau', exact: true })).toBeVisible();
    await expect(preview.getByRole('row').filter({ hasText: 'UI Mitarbeiter' })).toContainText('Versand möglich');
    await expect(preview.getByRole('row').filter({ hasText: 'UI Mitarbeiter' }).getByRole('cell').nth(2)).toHaveText(/2$/);
    await expect(preview.getByRole('row').filter({ hasText: 'UI Materialerfassung' })).toContainText('Kein aktives Gerät');
    await expect(preview.getByText('UI Pagination', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBeTruthy();
    const fields = await dispatchFields(page);
    expect((await page.request.post(dispatchPath, { form: { ...fields, csrf_token: '' } })).status()).toBe(403);
    expect(pushCalls()).toEqual([]);
    await preview.getByRole('button', { name: 'Push-Versand bestätigen', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Versandhistorie', exact: true })).toBeVisible();
    await expect(historyResults).toHaveCount(1);
    await expect(historyResults.getByRole('row').filter({ hasText: 'UI Mitarbeiter' })).toContainText('Teilweise vom Push-Dienst angenommen');
    await expect(historyResults.getByRole('row').filter({ hasText: 'UI Materialerfassung' })).toContainText('Kein aktives Gerät');
    await expect(historyResults.getByText('UI Pagination', { exact: true })).toHaveCount(0);
    const calls = pushCalls();
    expect(calls).toHaveLength(2);
    expect(calls.map(call => call.result).sort()).toEqual(['accepted', 'rejected']);
    expect(calls.every(call => call.user_id === Number(process.env.UI_TEST_EMPLOYEE_ID)
      && call.url === appPath && call.type === 'appointment_dispatch' && !call.is_cancellation)).toBeTruthy();
    expect(new Set(calls.map(call => call.dedupe_key)).size).toBe(2);
    const firstLocation = new URL(page.url()).pathname + new URL(page.url()).hash;
    const duplicate = await page.request.post(dispatchPath, { form: fields, maxRedirects: 0 });
    expect(duplicate.status()).toBe(303);
    expect(duplicate.headers().location).toBe(firstLocation);
    expect(pushCalls()).toEqual(calls);

    const viewer = await browser.newPage({ baseURL });
    const employee = await browser.newPage({ baseURL, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const otherEmployee = await browser.newPage({ baseURL });
    try {
      await adminLogin(viewer, process.env.UI_TEST_APPOINTMENT_VIEWER_EMAIL);
      expect((await viewer.goto(detail)).status()).toBe(200);
      await expect(viewer.getByRole('link', { name: 'Termin jetzt per Push senden', exact: true })).toHaveCount(0);
      await expect(viewer.getByRole('link', { name: 'Versandhistorie', exact: true })).toHaveCount(0);
      expect((await viewer.request.get(dispatchPath)).status()).toBe(403);
      expect((await viewer.request.post(dispatchPath, { form: fields })).status()).toBe(403);
      expect((await viewer.request.get(historyPath)).status()).toBe(403);
      expect((await employee.request.get(appApi)).status()).toBe(401);

      await page.goto(detail);
      await expect(employeeAck).toContainText('Ausstehend');
      await employeeLogin(employee, calls[0].url, process.env.UI_TEST_EMPLOYEE_EMAIL);
      const employeeDetail = employee.locator('[data-app-appointment-detail]');
      await expect(employeeDetail.getByRole('heading', { name: title, exact: true })).toBeVisible();
      await expect(employee).toHaveURL(new RegExp(`/app/termine\\?id=${id}$`));
      const appResponse = await employee.request.get(appApi);
      expect(appResponse.status()).toBe(200);
      expect(appResponse.headers()['cache-control']).toContain('no-store');
      const employeeAppointment = (await appResponse.json()).data;
      expect(employeeAppointment.acknowledged).toBe(false);
      expect(employeeAppointment).not.toHaveProperty('participants');
      await employeeDetail.getByRole('button', { name: 'Termin zur Kenntnis genommen', exact: true }).click();
      await expect(employeeDetail.getByText('Bestätigt', { exact: true })).toBeVisible();
      expect((await employee.request.post(appApi + '/acknowledge', { data: { version: employeeAppointment.version } })).status()).toBe(403);
      await page.reload();
      await expect(employeeAck).toContainText('Aktuelle Version bestätigt');
      await expect(noDeviceAck).toContainText('Ausstehend');

      await employeeLogin(otherEmployee, appPath, process.env.UI_TEST_APPOINTMENT_DISPATCH_NON_PARTICIPANT);
      await expect(otherEmployee.getByText('Dieser Terminbereich ist nicht verfügbar.', { exact: true })).toBeVisible();
      expect((await otherEmployee.request.get(appApi)).status()).toBe(404);
      expect((await otherEmployee.request.get('/api/v1/app/appointments')).status()).toBe(200);

      await page.goto(dispatchPath);
      const staleFields = await dispatchFields(page);
      await page.goto(detail + '/edit');
      await editForm.getByLabel('Einsatzort').fill('Neuer Treffpunkt');
      await editForm.getByRole('button', { name: 'Änderungen speichern', exact: true }).click();
      await expect(employeeAck).toContainText('Ältere Version bestätigt');
      const stale = await page.request.post(dispatchPath, { form: staleFields, maxRedirects: 0 });
      expect(stale.status()).toBe(409);
      expect(pushCalls()).toEqual(calls);
      await employee.reload();
      await expect(employeeDetail.getByRole('button', { name: 'Termin zur Kenntnis genommen', exact: true })).toBeVisible();

      await page.goto(dispatchPath);
      const freshFields = await dispatchFields(page);
      expect(freshFields.request_key).not.toBe(fields.request_key);
      expect(Number(freshFields.version)).toBe(Number(fields.version) + 1);
      await preview.getByRole('button', { name: 'Push-Versand bestätigen', exact: true }).click();
      await expect(historyResults).toHaveCount(2);
      expect(pushCalls()).toHaveLength(4);

      await page.goto(detail + '/edit');
      await editForm.getByRole('combobox', { name: 'Status', exact: true }).selectOption('cancelled');
      await editForm.getByRole('button', { name: 'Änderungen speichern', exact: true }).click();
      await page.getByRole('link', { name: 'Termin jetzt per Push senden', exact: true }).click();
      await preview.getByRole('button', { name: 'Absage per Push senden', exact: true }).click();
      await expect(historyResults).toHaveCount(3);
      const finalCalls = pushCalls();
      expect(finalCalls).toHaveLength(6);
      expect(finalCalls.filter(call => call.is_cancellation)).toHaveLength(2);

      await page.goto(detail + '/edit');
      await page.getByRole('button', { name: 'Termin archivieren', exact: true }).click();
      expect((await page.goto(dispatchPath)).status()).toBe(409);
      expect((await employee.request.get(appApi)).status()).toBe(404);
      expect((await page.goto(historyPath)).status()).toBe(200);
      await expect(historyResults).toHaveCount(3);
      expect(pushCalls()).toEqual(finalCalls);
      expect(await bookingSummary(page, date)).toEqual(originalBookings);
    } finally {
      await viewer.close();
      await employee.close();
      await otherEmployee.close();
    }
  });
});
