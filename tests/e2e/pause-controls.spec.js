const fs = require('node:fs');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

const date = '2026-11-18';
const oldDate = '2026-11-17';
const source = path.resolve(__dirname, '../../public');
const shell = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"></head><body><div id="appRoot"></div><script>window.__APP_BOOTSTRAP__ = {app_name:"Zeiterfassung"}</script><script src="/assets/js/app.js"></script></body></html>';

async function pauseApp(page, { previous = false } = {}) {
  await page.clock.install({ time: new Date(date + 'T12:00:00+01:00') });
  const responses = new Map();
  const user = { id: 7, display_name: 'UI Mitarbeiter', permissions: ['timesheets.view_own', 'timesheets.create'] };
  const project = { id: 11, name: 'Werkstatt', project_number: 'P-11' };
  const state = {
    entry: { id: 42, project_id: project.id, project_name: project.name, start_time: previous ? '21:00:00' : '07:00:00',
      end_time: null, work_date: previous ? oldDate : date, entry_type: 'work', break_minutes: 0, net_minutes: 0, attachments: [] },
    breaks: [], calls: [], dayReads: 0, loseNext: false, rejectNext: false,
  };
  const currentBreak = () => state.breaks.find((record) => record.break_started_at && !record.break_ended_at) || null;
  const assessment = () => ({ template_name: 'Werkstattpausen', version_id: 8, revision: 3, capture_mode: 'stamped', legacy: false,
    documented_break_minutes: state.breaks.reduce((total, pause) => total + (pause.duration_minutes || 0), 0),
    actual_work_minutes: 390, required_break_minutes: 30, missing_break_minutes: 30, calculation_status: 'needs_clarification',
    unplaced_break_minutes: state.breaks.reduce((total, pause) => total + (!pause.break_started_at ? pause.duration_minutes : 0), 0),
  });
  const todayState = () => ({ work_entry: state.entry, status: currentBreak() ? 'paused' : 'working', current_break: currentBreak(), is_missing: false });
  const day = () => ({
    today: date, user, projects: [project], server_time: new Date().toISOString(),
    today_state: previous ? { work_entry: null, current_break: null, status: 'not_started', is_missing: false } : todayState(),
    breaks_today: previous ? [] : state.breaks,
    project_day_summaries: previous ? [] : [{ ...todayState(), project_id: project.id, project_name: project.name, start_time: state.entry.start_time,
      breaks_today: state.breaks, total_break_minutes: assessment().documented_break_minutes }],
    break_assessment: previous ? { ...assessment(), documented_break_minutes: 0 } : assessment(),
    pause_active_entry: previous ? { ...state.entry, breaks: state.breaks, current_break: currentBreak(), break_assessment: assessment() } : null,
  });

  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    const json = (payload, status = 200) => route.fulfill({ json: payload, status });
    if (url.pathname === '/assets/js/app.js' || url.pathname === '/assets/css/app.css') {
      return route.fulfill({ contentType: url.pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(source, url.pathname)) });
    }
    if (url.pathname.startsWith('/app')) return route.fulfill({ contentType: 'text/html', body: shell });
    if (url.pathname === '/api/v1/auth/session') return json({ authenticated: true, user });
    if (url.pathname === '/api/v1/settings/company') return json({ data: { company_name: 'TimeApp Test' } });
    if (url.pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, devices: [] } });
    if (url.pathname === '/api/v1/app/me/day') {
      state.dayReads += 1;
      return json({ data: day() });
    }
    if (url.pathname === '/api/v1/app/me/timesheets') {
      return json({ data: { items: [{ ...state.entry, breaks: state.breaks, break_assessment: assessment() }] } });
    }
    if (url.pathname !== '/api/v1/app/timesheets/sync') return route.fulfill({ status: 204, body: '' });

    const command = route.request().postDataJSON();
    state.calls.push(command);
    if (state.rejectNext) {
      state.rejectNext = false;
      return json({ message: 'Diese Buchung ist bereits abgeschlossen.' }, 422);
    }
    if (responses.has(command.client_request_id)) return json(responses.get(command.client_request_id));
    if (command.action === 'pause_start') {
      state.breaks.push({ id: state.breaks.length + 1, break_started_at: command.break_started_at, break_ended_at: null });
    } else if (command.action === 'pause_end') {
      const pause = currentBreak();
      pause.break_ended_at = command.break_ended_at;
      pause.duration_minutes = Math.floor((Date.parse(pause.break_ended_at) - Date.parse(pause.break_started_at)) / 60000);
    } else if (command.action === 'pause_record') {
      const duration = command.manual_break_minutes ?? Math.floor((Date.parse(command.break_ended_at) - Date.parse(command.break_started_at)) / 60000);
      state.breaks.push({ id: state.breaks.length + 1, break_started_at: command.break_started_at ?? null,
        break_ended_at: command.break_ended_at ?? null, duration_minutes: duration });
    } else {
      return json({ message: 'Unexpected time action ' + command.action }, 422);
    }
    state.entry.break_minutes = assessment().documented_break_minutes;
    const result = { ok: true, data: { timesheet: state.entry, today_state: todayState(), breaks_today: state.breaks,
      current_break: currentBreak(), break_assessment: assessment() } };
    responses.set(command.client_request_id, JSON.parse(JSON.stringify(result)));
    if (state.loseNext) {
      state.loseNext = false;
      return route.abort('failed');
    }
    return json(result);
  });
  await page.goto('/app/heute');
  await expect(page.getByRole('button', { name: 'Pause starten', exact: true })).toBeVisible();
  await page.waitForLoadState('networkidle');
  return state;
}

const recordDialog = (page) => page.getByRole('dialog', { name: 'Tatsächlich genommene Pause nachtragen' });

test('stamped and manual pauses accumulate, preserve their durations and show assessment on both time screens', async ({ page }) => {
  const state = await pauseApp(page);
  await page.getByRole('button', { name: 'Pause starten', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Pause beenden', exact: true })).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Check-out', exact: true })).toBeDisabled();
  await page.clock.fastForward(15 * 60000);
  await expect(page.locator('[data-live-break-duration]')).toHaveText('00:15');
  await page.getByRole('button', { name: 'Pause beenden', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Pause nachtragen', exact: true })).toBeEnabled();

  const dialog = recordDialog(page);
  await page.getByRole('button', { name: 'Pause nachtragen', exact: true }).click();
  await dialog.getByLabel('Zeitangabe').selectOption('interval');
  await dialog.getByLabel('Beginn (Europe/Berlin)').fill(date + 'T10:00');
  await dialog.getByLabel('Ende (Europe/Berlin)').fill(date + 'T10:30');
  await dialog.getByRole('button', { name: 'Pause speichern', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  expect(state.breaks.map((record) => record.duration_minutes)).toEqual([15, 30]);
  expect(state.calls[1]).toMatchObject({ expected_timesheet_id: 42, expected_break_id: 1, expected_break_started_at: state.calls[0].break_started_at });

  await page.goto('/app/zeiten');
  await page.getByRole('button', { name: 'Pause nachtragen', exact: true }).click();
  await dialog.getByLabel('Dauer in Minuten').fill('7');
  await dialog.getByRole('button', { name: 'Pause speichern', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Pausen heute', exact: true })).toContainText('Werkstattpausen · Version 3');
  await expect(page.locator('.app-break-assessment .app-stat-row').filter({ hasText: 'Dokumentierte Pause' })).toContainText('00:52');
  await page.getByText('Pausen dieses Einsatzes anzeigen', { exact: true }).click();
  await expect(page.getByText('Uhrzeiten unbekannt', { exact: true })).toBeVisible();
  expect(state.breaks[2]).toMatchObject({ break_started_at: null, break_ended_at: null, duration_minutes: 7 });
  expect(state.calls[3]).toMatchObject({ expected_documented_break_minutes: 45 });
  expect(state.calls[3]).not.toHaveProperty('break_started_at');
});

test('pause history distinguishes equal Berlin clock times across the autumn time change', async ({ page }) => {
  const state = await pauseApp(page);
  state.entry.work_date = '2026-10-25';
  state.entry.start_time = '00:00:00';
  state.entry.end_time = '06:00:00';
  state.entry.break_minutes = 60;
  state.breaks.push({
    id: 1,
    break_started_at: '2026-10-25T02:30:00+02:00',
    break_ended_at: '2026-10-25T02:30:00+01:00',
    duration_minutes: 60,
  });

  await page.evaluate(() => localStorage.setItem('app.timesheetFilterMonth', '2026-10'));
  await page.goto('/app/historie');
  await page.getByText('Pausen anzeigen', { exact: true }).click();

  const pause = page.locator('.app-history-breaks > div');
  await expect(pause.locator('strong')).toHaveText('01:00');
  await expect(pause.locator('span')).toContainText(/02:30\s+(?:MESZ|(?:GMT|UTC)\+0?2(?::00)?)/);
  await expect(pause.locator('span')).toContainText(/02:30\s+(?:MEZ|(?:GMT|UTC)\+0?1(?::00)?)/);
});

test('the manual pause dialog retains its fields and focus during refresh and a validation response', async ({ page }) => {
  const state = await pauseApp(page);
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.setViewportSize({ width: 320, height: 640 });
  const trigger = page.getByRole('button', { name: 'Pause nachtragen', exact: true });
  await trigger.click();
  const dialog = recordDialog(page);
  const duration = dialog.getByLabel('Dauer in Minuten');
  await duration.fill('17');
  await duration.focus();
  const reads = state.dayReads;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => state.dayReads).toBeGreaterThan(reads);
  await expect(duration).toHaveValue('17');
  await expect(duration).toBeFocused();

  state.rejectNext = true;
  await dialog.getByRole('button', { name: 'Pause speichern', exact: true }).click();
  await expect(dialog.getByRole('alert')).toContainText('Diese Buchung ist bereits abgeschlossen.');
  await expect(duration).toHaveValue('17');
  await expect(dialog.getByRole('alert')).toBeFocused();
  expect(state.breaks).toHaveLength(0);
  const widths = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    overflowing: [...document.querySelectorAll('body *')].filter((node) => node.getBoundingClientRect().right > 321)
      .slice(0, 12).map((node) => [node.id || node.className || node.tagName, node.getBoundingClientRect().right]),
  }));
  expect(widths.scrollWidth, JSON.stringify(widths.overflowing)).toBeLessThanOrEqual(320);
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
});

test('offline stamps on a previous shift keep their captured identity when a lost response is retried', async ({ page, context }) => {
  const state = await pauseApp(page, { previous: true });
  const carryover = page.getByRole('region', { name: 'Laufender Einsatz aus früherem Tag' });
  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await carryover.getByRole('button', { name: 'Pause starten', exact: true }).click();
  await page.clock.fastForward(5 * 60000);
  await expect(carryover.locator('[data-live-prior-break-duration]')).toHaveText('00:05');
  await carryover.getByRole('button', { name: 'Pause des laufenden Einsatzes beenden', exact: true }).click();
  await expect(carryover.getByRole('button', { name: 'Pause starten', exact: true })).toBeVisible();
  expect(state.calls).toHaveLength(0);

  state.loseNext = true;
  await context.setOffline(false);
  const retry = page.getByRole('button', { name: 'Pausen erneut synchronisieren', exact: true });
  await expect(retry).toBeEnabled();
  expect(state.calls).toHaveLength(1);
  await retry.click();
  await expect(retry).toHaveCount(0);
  expect(state.calls.map((command) => command.action)).toEqual(['pause_start', 'pause_start', 'pause_end']);
  expect(state.calls[1]).toEqual(state.calls[0]);
  expect(state.calls[2]).toMatchObject({ work_date: oldDate, expected_timesheet_id: 42, expected_break_started_at: state.calls[0].break_started_at });
  expect(state.breaks).toHaveLength(1);
  expect(state.breaks[0].duration_minutes).toBe(5);
});

test('an offline pause rejected by the server stays reviewable until explicitly discarded', async ({ page, context }) => {
  const state = await pauseApp(page);
  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await page.getByRole('button', { name: 'Pause nachtragen', exact: true }).click();
  const dialog = recordDialog(page);
  await dialog.getByLabel('Dauer in Minuten').fill('23');
  await dialog.getByRole('button', { name: 'Pause speichern', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  expect(state.calls).toHaveLength(0);

  state.rejectNext = true;
  await context.setOffline(false);
  const conflicts = page.getByRole('region', { name: 'Pausenaktionen zur Prüfung' });
  await expect(conflicts).toContainText('23 Minuten · Uhrzeiten unbekannt');
  await expect(conflicts).toContainText('Diese Buchung ist bereits abgeschlossen.');
  expect(state.calls).toHaveLength(1);
  expect(state.breaks).toHaveLength(0);
  await page.reload();
  await expect(conflicts).toContainText('23 Minuten');
  await expect(page.getByRole('button', { name: 'Pausen erneut synchronisieren' })).toHaveCount(0);

  const reads = state.dayReads;
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect.poll(() => state.dayReads).toBeGreaterThan(reads);
  expect(state.calls).toHaveLength(1);
  page.once('dialog', (confirmation) => confirmation.accept());
  await conflicts.getByRole('button', { name: 'Lokale Aktion verwerfen' }).click();
  await expect(conflicts).toHaveCount(0);
});
