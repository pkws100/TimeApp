const { expect } = require('@playwright/test');
const { test } = require('../Support/playwright-offline-origin');

test.describe('real independent pause workflow', () => {
  test.describe.configure({ timeout: 60000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  test('records a timed pause and two manual intervals plus an untimed duration in one shift', async ({ page, origin }) => {
    const workDate = process.env.UI_TEST_PAUSE_WORK_DATE;
    const url = (path) => new URL(path, origin.baseURL).href;
    for (const path of ['/app?offline-shell=1', '/app/sw.js', '/app/manifest.json']) {
      const resource = await page.request.get(url(path));
      expect(resource.ok()).toBeTruthy();
      expect(resource.headers()['set-cookie']).toBeUndefined();
    }
    await page.goto(url('/app/login'));
    await page.getByLabel('E-Mail').fill('ui-pause-controls@example.test');
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/heute$/);
    const checkIn = await page.request.post(url('/api/v1/app/timesheets/sync'), { data: {
      client_request_id: 'real-pause-shift', action: 'check_in', work_date: workDate,
      start_time: process.env.UI_TEST_PAUSE_WORK_START, note: 'Synthetischer Pausenablauf',
    } });
    expect(checkIn.ok(), await checkIn.text()).toBeTruthy();
    const booking = (await checkIn.json()).data.timesheet;
    await page.reload();

    const syncAction = async (action, button) => {
      const request = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/app/timesheets/sync'
        && response.request().method() === 'POST' && response.request().postDataJSON().action === action);
      await button.click();
      const response = await request;
      expect(response.ok(), await response.text()).toBeTruthy();
      expect(response.request().postDataJSON()).toMatchObject({ expected_timesheet_id: booking.id, work_date: workDate });
      return (await response.json()).data;
    };

    const stamp = await syncAction('pause_start', page.getByRole('button', { name: 'Pause starten', exact: true }));
    expect(stamp.current_break.break_started_at).toBeTruthy();
    await expect(page.locator('[data-live-break-duration], [data-live-prior-break-duration]')).toBeVisible();
    // Database events have second precision. Keep the browser and server clocks real.
    await expect.poll(() => Date.now() - Date.parse(stamp.current_break.break_started_at)).toBeGreaterThanOrEqual(1000);
    const finished = await syncAction('pause_end', page.getByRole('button', { name: /^Pause (des laufenden Einsatzes )?beenden$/ }));
    expect(finished.current_break).toBeNull();
    const stampedMinutes = finished.breaks_today[0].duration_minutes;

    for (const prefix of ['FIRST', 'SECOND']) {
      await page.getByRole('button', { name: 'Pause nachtragen', exact: true }).click();
      const dialog = page.getByRole('dialog', { name: 'Tatsächlich genommene Pause nachtragen' });
      await dialog.getByLabel('Zeitangabe').selectOption('interval');
      await dialog.getByLabel('Beginn (Europe/Berlin)').fill(process.env[`UI_TEST_PAUSE_${prefix}_START`]);
      await dialog.getByLabel('Ende (Europe/Berlin)').fill(process.env[`UI_TEST_PAUSE_${prefix}_END`]);
      await syncAction('pause_record', dialog.getByRole('button', { name: 'Pause speichern', exact: true }));
      await expect(dialog).toHaveCount(0);
    }

    await page.getByRole('button', { name: 'Pause nachtragen', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Tatsächlich genommene Pause nachtragen' });
    await dialog.getByLabel('Dauer in Minuten').fill('7');
    const recorded = await syncAction('pause_record', dialog.getByRole('button', { name: 'Pause speichern', exact: true }));
    await expect(dialog).toHaveCount(0);
    expect(recorded.break_assessment).toMatchObject({ documented_break_minutes: stampedMinutes + 52, template_name: 'UI Pausenmodell Stempeluhr' });
    expect(recorded.breaks_today.map((pause) => pause.duration_minutes).sort((a, b) => a - b)).toEqual([stampedMinutes, 7, 15, 30].sort((a, b) => a - b));
    expect(recorded.breaks_today.find((pause) => pause.duration_minutes === 7)).toMatchObject({ break_started_at: null, break_ended_at: null });

    await page.evaluate((month) => localStorage.setItem('app.timesheetFilterMonth', month), workDate.slice(0, 7));
    await page.goto(url('/app/historie'));
    await page.getByText('Pausen anzeigen', { exact: true }).click();
    await expect(page.getByText('Uhrzeiten unbekannt', { exact: true })).toBeVisible();
    await expect(page.getByText('Dokumentierte Pause', { exact: true })).toBeVisible();
    await expect(page.locator('.app-history-breaks > div')).toHaveCount(4);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

    await page.waitForFunction(() => !!navigator.serviceWorker.controller);
    const cachedShell = await page.evaluate(async () => (await caches.match('/app'))?.text());
    expect(cachedShell).toContain('"session":{"authenticated":false,"user":null');
    expect(await page.evaluate(() => caches.match('/app/historie').then(Boolean))).toBe(false);
    await origin.goOffline();
    await page.goto(url('/app/heute'), { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('region', { name: 'Pausen heute', exact: true })).toBeVisible();
    await page.goto(url('/app/historie'), { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/app\/historie$/);
    await page.getByText('Pausen anzeigen', { exact: true }).click();
    await expect(page.getByText('Uhrzeiten unbekannt', { exact: true })).toBeVisible();
    await expect(page.locator('.app-history-breaks > div')).toHaveCount(4);
  });
});
