const { test, expect } = require('@playwright/test');

const appointments = '/admin/appointments';

async function signIn(page, email) {
  await page.goto('/admin/login');
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

async function calendarData(page, path) {
  const response = await page.request.get(path);
  expect(response.ok()).toBeTruthy();
  return (await response.json()).data;
}

function bookingMetrics(summary) {
  const keys = ['booking_count', 'active_booking_count', 'work_booking_count', 'absence_count', 'missing_count', 'employee_count', 'net_minutes'];
  return Object.fromEntries(keys.map(key => [key, summary[key]]));
}

test.describe('real appointment management and calendar integration', () => {
  test.describe.configure({ timeout: 90000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  test('create, find, edit, cancel and archive a mobile appointment without affecting bookings or showing restricted data', async ({ page, browser, baseURL }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const date = process.env.UI_TEST_APPOINTMENT_DAY;
    const eventTitle = process.env.UI_TEST_APPOINTMENT_EVENT;
    const title = 'UI Termin <b data-appointment-probe>Prüftext</b>';
    const editedTitle = 'UI Termin zur Einsatzplanung aktualisiert';
    const calendar = `/admin/calendar?month=${date.slice(0, 7)}&date=${date}`;
    const calendarDay = `/admin/calendar/day?date=${date}`;
    const calendarMonth = `/admin/calendar/month?month=${date.slice(0, 7)}`;
    const form = page.locator('[data-appointment-form]');

    await signIn(page, process.env.UI_TEST_ADMIN_EMAIL);
    const before = await calendarData(page, calendarDay + '&view=all');
    const beforeMonth = await calendarData(page, calendarMonth + '&view=bookings');
    expect(before.summary.active_booking_count).toBe(1);
    expect(before.summary.net_minutes).toBe(60);
    expect(before.html).toContain(eventTitle);
    const invalidCsrf = await page.request.post(appointments, { form: { title: 'UI CSRF control' } });
    expect(invalidCsrf.status()).toBe(403);

    await page.goto(calendar + '&view=appointments');
    const dayPanel = page.locator('[data-calendar-day-panel]');
    await dayPanel.getByRole('link', { name: 'Termin anlegen', exact: true }).click();
    await expect(form.getByLabel('Beginn', { exact: true })).toHaveValue(new RegExp(`^${date}T`));
    await form.getByLabel('Terminbezeichnung').fill(title);
    await form.getByLabel('Beginn', { exact: true }).fill(`${date}T09:30`);
    await form.getByLabel('Ende', { exact: true }).fill(`${date}T10:45`);
    await form.getByRole('combobox', { name: 'Projekt', exact: true }).selectOption(process.env.UI_TEST_APPOINTMENT_PROJECT);
    await form.getByLabel('Einsatzort').fill('UI Treffpunkt Baucontainer');
    await form.getByLabel('Beschreibung').fill('Synthetische Monatsbesprechung');
    const participants = form.getByRole('group', { name: 'Mitarbeiter' });
    await participants.getByRole('checkbox', { name: /UI Mitarbeiter/ }).check();
    await participants.getByRole('checkbox', { name: /UI Materialerfassung/ }).check();
    expect(await form.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBeTruthy();
    await form.getByRole('button', { name: 'Termin speichern', exact: true }).click();
    await expect(page.getByText('Termin angelegt.', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    await expect(page.locator('[data-appointment-probe]')).toHaveCount(0);
    const detail = new URL(page.url()).pathname;

    await page.goto(appointments);
    await page.getByLabel('Suchtext').fill('UI Termin');
    await page.getByRole('button', { name: 'Filtern', exact: true }).click();
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: title, exact: true }) });
    await expect(row).toContainText('UI-FORM-STABILITY');
    await expect(row).toContainText('UI Mitarbeiter');
    await expect(row).toContainText('UI Materialerfassung');
    await expect(row).toContainText('UI Treffpunkt Baucontainer');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBeTruthy();

    await page.goto(calendar + '&view=all');
    const tabs = page.getByRole('navigation', { name: 'Kalenderansichten' });
    await expect(dayPanel.getByText(title, { exact: true })).toBeVisible();
    await expect(dayPanel.getByText(eventTitle, { exact: true })).toBeVisible();
    await expect(dayPanel.getByRole('heading', { name: 'Buchungen am Tag' })).toBeVisible();
    await expect(page.locator(`[data-calendar-date="${date}"]`)).toContainText('1 Termin');

    await tabs.getByRole('link', { name: 'Buchungen', exact: true }).click();
    await expect(dayPanel.getByRole('heading', { name: 'Buchungen am Tag' })).toBeVisible();
    await expect(dayPanel.getByText(title, { exact: true })).toHaveCount(0);
    await expect(dayPanel.getByText(eventTitle, { exact: true })).toHaveCount(0);

    await tabs.getByRole('link', { name: 'Personal', exact: true }).click();
    await expect(dayPanel.getByText(eventTitle, { exact: true })).toBeVisible();
    await expect(dayPanel.getByText(title, { exact: true })).toHaveCount(0);
    await expect(dayPanel.getByRole('heading', { name: 'Buchungen am Tag' })).toHaveCount(0);

    await tabs.getByRole('link', { name: 'Termine', exact: true }).click();
    await expect(dayPanel.getByText(title, { exact: true })).toBeVisible();
    await expect(dayPanel.getByText(eventTitle, { exact: true })).toHaveCount(0);
    await expect(dayPanel.getByRole('heading', { name: 'Buchungen am Tag' })).toHaveCount(0);
    expect(bookingMetrics((await calendarData(page, calendarDay + '&view=all')).summary)).toEqual(bookingMetrics(before.summary));
    expect((await calendarData(page, calendarMonth + '&view=bookings')).totals).toEqual(beforeMonth.totals);

    await dayPanel.getByRole('link', { name: `Bearbeiten · ${title}`, exact: true }).click();
    await form.getByLabel('Terminbezeichnung').fill(editedTitle);
    await form.getByLabel('Einsatzort').fill('UI Treffpunkt Lager');
    await participants.getByRole('checkbox', { name: /UI Materialerfassung/ }).uncheck();
    await form.getByRole('button', { name: 'Änderungen speichern', exact: true }).click();
    await expect(page.getByText('Änderungen gespeichert.', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: editedTitle, exact: true })).toBeVisible();

    const viewer = await browser.newPage({ baseURL });
    const denied = await browser.newPage({ baseURL });
    try {
      await signIn(viewer, process.env.UI_TEST_APPOINTMENT_VIEWER_EMAIL);
      const viewerList = await viewer.goto(appointments);
      expect(viewerList.status()).toBe(200);
      await expect(viewer.getByRole('heading', { name: 'Termine', exact: true })).toBeVisible();
      await expect(viewer.getByRole('link', { name: 'Termin anlegen', exact: true })).toHaveCount(0);
      expect((await viewer.request.get(appointments + '/new')).status()).toBe(403);
      expect((await viewer.request.post(appointments, { form: {} })).status()).toBe(403);
      expect((await viewer.request.get(detail)).status()).toBe(404);
      const viewerAll = await calendarData(viewer, calendarDay + '&view=all');
      expect(viewerAll.html).not.toContain(eventTitle);
      expect(viewerAll.html).not.toContain(editedTitle);
      expect(viewerAll.html).not.toContain('Buchungen am Tag');
      expect(viewerAll.summary.active_booking_count).toBeUndefined();
      expect((await viewer.request.get(calendarDay + '&view=bookings')).status()).toBe(403);
      expect((await viewer.request.get(calendarDay + '&view=personnel')).status()).toBe(403);
      expect((await viewer.request.get(calendarMonth + '&view=personnel')).status()).toBe(403);

      await signIn(denied, process.env.UI_TEST_APPOINTMENT_DENIED_EMAIL);
      expect((await denied.request.get(appointments)).status()).toBe(403);
      expect((await denied.request.get(calendarDay + '&view=appointments')).status()).toBe(403);
    } finally {
      await viewer.close();
      await denied.close();
    }

    await page.goto(detail + '/edit');
    await form.getByRole('combobox', { name: 'Status', exact: true }).selectOption('cancelled');
    await form.getByRole('button', { name: 'Änderungen speichern', exact: true }).click();
    await expect(page.getByText('Abgesagt', { exact: true })).toBeVisible();
    await page.goto(detail + '/edit');
    const archiveForm = page.getByRole('region', { name: 'Archivierung' });
    const archiveToken = await archiveForm.locator('input[name="csrf_token"]').inputValue();
    const invalidVersion = await page.request.post(detail + '/archive', { form: { csrf_token: archiveToken, version: '4294967296' } });
    expect(invalidVersion.status()).toBe(422);
    await archiveForm.getByRole('button', { name: 'Termin archivieren', exact: true }).click();
    await expect(page.getByText('Termin archiviert.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: editedTitle, exact: true })).toHaveCount(0);
    expect(bookingMetrics((await calendarData(page, calendarDay + '&view=all')).summary)).toEqual(bookingMetrics(before.summary));
  });
});
