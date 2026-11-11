const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.describe('real hardening release candidate workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 60000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  const fixture = (operation, userId, clock) => JSON.parse(execFileSync('php', [path.join(__dirname, '../Support/work-time-hardening-ui.php'), operation, String(userId), ...(clock ? [clock] : [])], { cwd: path.join(__dirname, '../..'), encoding: 'utf8' }));
  const date = () => process.env.UI_TEST_HARDENING_DATE;

  async function adminLogin(page) {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
  }

  async function employeeLogin(page, email, baseUrl = process.env.UI_BASE_URL) {
    await page.goto(baseUrl + '/app/login');
    await page.locator('#loginForm input[name="email"]').fill(email);
    await page.locator('#loginForm input[name="password"]').fill(process.env.UI_TEST_WORKTIME_PASSWORD);
    await Promise.all([page.waitForURL('**/app/heute'), page.locator('#loginForm button[type="submit"]').click()]);
  }

  async function sync(page, payload, baseUrl = process.env.UI_BASE_URL) {
    const response = await page.request.post(baseUrl + '/api/v1/app/timesheets/sync', { data: payload });
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
  }

  test('future rule, presence, actual work, pause, mock push, confirmed end and late start preserve actual minutes', async ({ page, browser }) => {
    const userId = Number(process.env.UI_TEST_HARDENING_EMPLOYEE_ID);
    await adminLogin(page);
    await page.goto('/admin/work-time');
    const assignment = page.locator('form[action="/admin/work-time/assignments"]');
    await assignment.locator('[name="user_id"]').selectOption(String(userId));
    await assignment.locator('[name="policy_version_id"]').selectOption(process.env.UI_TEST_HARDENING_VERSION_ID);
    await expect(assignment.locator('[name="outside_window_notifications_enabled"]')).toBeChecked();
    await expect(assignment.locator('[name="outside_window_delay_minutes"]')).toHaveValue('5');
    await expect(assignment.locator('[name="notify_employee_push"]')).toBeChecked();
    await assignment.locator('[name="effective_from"]').fill(process.env.UI_TEST_HARDENING_FUTURE_DATE);
    await assignment.getByRole('button', { name: 'Beispiel prüfen', exact: true }).click();
    await expect(assignment.locator('[data-worktime-result]')).toContainText('Tatsächliche Arbeit: 503 Min.');
    await assignment.locator('[data-worktime-save]').click();
    await expect(page.locator('.notice-success')).toContainText('Gespeichert.');
    const activated = fixture('activate', userId);
    expect(activated.previous_effective_from).toBe(process.env.UI_TEST_HARDENING_FUTURE_DATE);
    expect(activated.activated_fixture_date).toBe(date());

    const employeeContext = await browser.newContext({ viewport: { width: 390, height: 844 }, timezoneId: 'Europe/Berlin', serviceWorkers: 'block' });
    const employee = await employeeContext.newPage();
    try {
      await employeeLogin(employee, 'ui-hardening-employee@example.test');
      const presence = await sync(employee, { client_request_id: 'hardening-presence', action: 'check_in', work_date: date(), start_time: '07:30' });
      expect(presence.status).toBe('presence_only');
      expect(presence.timesheet).toBeNull();
      await employee.reload();
      await expect(employee.getByText('Außerhalb der Kernarbeitszeit anwesend', { exact: true })).toBeVisible();
      await employee.locator('#workTimeActualStart').fill(date() + 'T07:42');
      const started = employee.waitForResponse((response) => response.url().endsWith('/api/v1/app/timesheets/sync') && response.request().postDataJSON().action === 'work_start');
      await employee.getByRole('button', { name: 'Ich beginne zu arbeiten', exact: true }).click();
      const startedResponse = await started;
      expect(startedResponse.ok(), await startedResponse.text()).toBeTruthy();
      const id = (await startedResponse.json()).data.timesheet.id;
      await sync(employee, { client_request_id: 'hardening-pause-start', action: 'pause_start', work_date: date(), expected_timesheet_id: id, break_started_at: date() + 'T12:00:00' });
      await sync(employee, { client_request_id: 'hardening-pause-end', action: 'pause_end', work_date: date(), expected_timesheet_id: id, break_ended_at: date() + 'T12:30:00' });
      const notification = fixture('worker', userId, '16:05');
      expect(notification.mock_push_only).toBeTruthy();
      expect(notification.summary.errors).toBe(0);
      expect(notification.mock_push_calls).toContainEqual(expect.objectContaining({ recipient: userId, type: 'work_time_checkout_proposal' }));
      expect(notification.notifications).toContainEqual(expect.objectContaining({ status: 'provider_accepted' }));
      expect(fixture('state', userId).entries[0].end_time).toBeNull();

      await employee.reload();
      await expect(employee.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toBeVisible();
      await employee.locator('[data-worktime-end]').fill(date() + 'T16:35');
      await employee.getByRole('button', { name: 'Ich habe länger gearbeitet', exact: true }).click();
      await expect(employee.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
      const closed = fixture('state', userId);
      expect(closed.entries[0]).toMatchObject({ start_time: '07:42:00', end_time: '16:35:00', net_minutes: 503 });
      expect(closed.preview).toMatchObject({ actual_work_minutes: 503, inside_window_minutes: 450, outside_pending_minutes: 53, accountable_work_minutes: 450 });

      const late = await sync(employee, { client_request_id: 'hardening-late-start', action: 'check_in', work_date: date(), start_time: '17:00' });
      expect(late.timesheet.start_time).toBe('17:00:00');
      const beforeThreshold = fixture('worker', userId, '17:04');
      expect(beforeThreshold.notifications.filter((event) => event.event_type === 'work_time_outside_window_started')).toHaveLength(0);
      const threshold = fixture('worker', userId, '17:05');
      expect(threshold.notifications.filter((event) => event.event_type === 'work_time_outside_window_started')).toEqual([expect.objectContaining({ status: 'provider_accepted' })]);
      const repeat = fixture('worker', userId, '17:06');
      expect(repeat.mock_push_calls.filter((call) => call.recipient === userId)).toHaveLength(0);
      expect(fixture('state', userId).entries[1].end_time).toBeNull();
      await sync(employee, { client_request_id: 'hardening-late-end', action: 'check_out', work_date: date(), end_time: '17:10', actual_end_at: date() + 'T17:10', expected_timesheet_id: late.timesheet.id });
      expect(fixture('state', userId).entries[0].net_minutes).toBe(503);

      await page.goto('/admin/work-time/notifications?user_id=' + userId);
      const notificationTable = page.locator('[data-admin-table="work-time-notifications"]');
      await expect(page.getByRole('heading', { name: 'Versandereignisse', exact: true })).toBeVisible();
      await expect(notificationTable).toContainText('Vom Provider angenommen (Zustellung nicht nachgewiesen)');
      await expect(notificationTable.locator('tbody tr')).toHaveCount(2);
      await expect(notificationTable.getByRole('button', { name: 'Erneuten Versuch anfordern', exact: true })).toHaveCount(0);
      await expect(page.getByText('SMS: Kein produktiver Provider konfiguriert. Der Testadapter ist deaktiviert.', { exact: true })).toBeVisible();
      for (const theme of ['light', 'dark']) {
        await page.locator('[data-theme-option="' + theme + '"]').click();
        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
        expect(await page.locator('.admin-table-search').evaluate((element) => element.getBoundingClientRect().height)).toBeLessThan(140);
        await notificationTable.locator('details').first().locator('summary').click();
        await expect(notificationTable.locator('details').first()).toHaveAttribute('open', '');
        if (process.env.UI_REVIEW_ARTIFACT_DIR) {
          await page.screenshot({ path: path.join(process.env.UI_REVIEW_ARTIFACT_DIR, 'work-time-notifications-mobile-' + theme + '.png'), fullPage: true });
        }
        await notificationTable.locator('details').first().locator('summary').click();
      }
    } finally {
      await employeeContext.close();
    }
  });

  test('admin documents proven pause intervals with an audit trail while original work stays unchanged', async ({ page }) => {
    const userId = Number(process.env.UI_TEST_HARDENING_PAUSE_USER_ID);
    const id = Number(process.env.UI_TEST_HARDENING_PAUSE_ID);
    const before = fixture('state', userId);
    expect(before.preview.calculation_status).toBe('needs_clarification');
    expect(before.preview.actual_work_minutes).toBeNull();
    await adminLogin(page);
    await page.goto('/admin/work-time?user_id=' + userId + '&pause_month=' + date().slice(0, 7));
    const panel = page.locator('[data-pause-evidence="' + id + '"]');
    await expect(panel).toContainText('Gespeicherte Pause: 30 Min.');
    await expect(panel).toContainText('Keine konkreten Pausenintervalle dokumentiert.');
    await expect(panel.locator('[name="pause_start[]"]')).toHaveValue('');
    await panel.locator('[name="pause_start[]"]').fill(date() + 'T12:00');
    await panel.locator('[name="pause_end[]"]').fill(date() + 'T12:30');
    await panel.locator('[name="reason"]').fill('UI Abnahme: Mitarbeiter bestaetigt die tatsaechlich genommene Mittagspause.');
    await panel.locator('[name="actual_break_attestation"]').check();
    await panel.getByRole('button', { name: 'Tatsächliche Pausen dokumentieren', exact: true }).click();
    await expect(page.locator('.notice-success')).toContainText('Gespeichert.');
    const after = fixture('state', userId);
    expect(after.entries).toEqual(before.entries);
    expect(after.system_versions).toBe(before.system_versions);
    expect(after.preview).toMatchObject({ actual_work_minutes: 503, inside_window_minutes: 450, outside_pending_minutes: 53 });
    expect(after.corrections).toHaveLength(1);
    await page.locator('[data-pause-evidence="' + id + '"] summary').click();
    await expect(page.locator('[data-pause-evidence="' + id + '"]')).toContainText('UI Abnahme: Mitarbeiter bestaetigt');
  });

  test('a separate feature-disabled PHP server retains ordinary actual stamping and no window workflow', async ({ page }) => {
    const baseUrl = process.env.UI_TEST_HARDENING_DISABLED_URL;
    const userId = Number(process.env.UI_TEST_HARDENING_OFF_ID);
    await employeeLogin(page, 'ui-hardening-off@example.test', baseUrl);
    await expect(page.getByRole('heading', { name: 'Kernarbeitszeit', exact: true })).toHaveCount(0);
    const early = await sync(page, { client_request_id: 'hardening-off-in', action: 'check_in', work_date: date(), start_time: '07:30' }, baseUrl);
    expect(early.status).toBe('synced');
    expect(early.timesheet.start_time).toBe('07:30:00');
    await sync(page, { client_request_id: 'hardening-off-break-start', action: 'pause_start', work_date: date(), break_started_at: date() + 'T12:00:00' }, baseUrl);
    await sync(page, { client_request_id: 'hardening-off-break-end', action: 'pause_end', work_date: date(), break_ended_at: date() + 'T12:30:00' }, baseUrl);
    const out = await sync(page, { client_request_id: 'hardening-off-out', action: 'check_out', work_date: date(), end_time: '16:35' }, baseUrl);
    // The unchanged legacy calculator applies its 45-minute minimum above nine gross hours.
    expect(out.timesheet).toMatchObject({ start_time: '07:30:00', end_time: '16:35:00', break_minutes: 45, net_minutes: 500 });
    const status = await (await page.request.get(baseUrl + '/api/v1/app/me/work-time')).json();
    expect(status.data).toEqual({ enabled: false });
    const worker = fixture('worker-disabled', userId, '17:05');
    expect(worker.summary.enabled).toBe(false);
    expect(worker.mock_push_calls).toEqual([]);
    expect(worker.notifications).toEqual([]);
  });
});
