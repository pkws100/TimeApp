const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block', locale: 'de-DE', timezoneId: 'Europe/Berlin' });

const projectRoot = path.join(__dirname, '../..');
const appShell = execFileSync('php', ['-r',
  'require "bootstrap/autoload.php"; echo (new \\App\\Presentation\\App\\AppView("Arbeitszeit Test"))->render("/app/heute", ["app_name"=>"Arbeitszeit Test"]);'
], { cwd: projectRoot, encoding: 'utf8' });
const adminForm = execFileSync('php', ['-r',
  'require "bootstrap/autoload.php"; $r=new ReflectionClass(\\App\\Http\\Controllers\\AdminWorkTimeController::class); $c=$r->newInstanceWithoutConstructor(); '
  + 'echo "<form data-worktime-form><input name=csrf_token value=test-token><input name=preview_token>"; '
  + 'echo $r->getMethod("ruleFields")->invoke($c), $r->getMethod("exampleFields")->invoke($c), "</form>";'
], { cwd: projectRoot, encoding: 'utf8' });

async function mockWorkTimeApp(page, options = {}) {
  const app = {
    enabled: options.enabled !== false,
    reads: 0,
    statusReads: 0,
    unexpected: [],
    mutations: [],
    entry: options.entry || null,
    pendingMutation: null,
    pendingStatus: null,
    workTime: {
      enabled: true,
      csrf_token: 'work-time-csrf',
      policy: options.noPolicy ? null : {
        early_check_in_mode: options.presence ? 'presence_only' : 'work_started',
        windows: { 1: [{ start: '08:00', end: '16:00' }] }
      },
      preview: { actual_work_minutes: 503, accountable_work_minutes: 450, outside_pending_minutes: 53, rows: [{ timesheet_id: 9 }] },
      presence_session: options.presenceSession || null,
      active_work_entry: options.activeWorkEntry || null,
      active_work_entry_conflict: !!options.activeWorkConflict,
      checkout_proposals: options.proposals || []
    }
  };
  const user = { id: 7, display_name: 'Max Beispiel', email: 'max@example.test', permissions: ['timesheets.create', 'timesheets.view_own'] };
  const flags = { show_geo_section: false, show_project_files: false, show_timesheet_files: false, show_personnel_overview: false };
  const todayState = () => ({ status: app.entry && !app.entry.end_time ? 'working' : 'not_started', is_missing: false, booking_required: false, work_entry: app.entry, status_entry: null, current_break: null });
  await page.addInitScript(() => {
    localStorage.setItem('app.projectSelectionId', '1');
    localStorage.setItem('app.preferredProjectId', '1');
  });
  // All network requests are intercepted; these tests never touch a live API or DB.
  await page.route('**/*', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') return route.fulfill({ contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(projectRoot, 'public', pathname)) });
    if (pathname === '/app' || pathname.startsWith('/app/')) return route.fulfill({ contentType: 'text/html', body: appShell });
    if (pathname === '/api/v1/auth/session') return json({ authenticated: true, bootstrap_required: false, user });
    if (pathname === '/api/v1/app/me/day') {
      app.reads += 1;
      return json({ data: {
        today: '2026-11-16', server_time: '2026-11-16T17:00:00+01:00', work_time_windows_enabled: app.enabled,
        user: { ...user, roles: [], time_tracking_required: false, app_ui_settings: flags }, app_ui_settings: flags,
        projects: [{ id: 1, project_number: 'TEST-1', name: 'Testbaustelle' }], today_state: todayState(),
        project_day_summaries: app.entry ? [{ project_id: 1, project_name: 'Testbaustelle', status: app.entry.end_time ? 'completed' : 'working', work_entry: app.entry, start_time: app.entry.start_time, end_time: app.entry.end_time, total_break_minutes: 0, total_net_minutes: app.entry.net_minutes || 0, breaks_today: [] }] : [], attachments: [], breaks_today: [], current_break: null,
        company: {}, geo_policy: { enabled: false }, personnel_events: [], personnel_labels: []
      } });
    }
    if (pathname === '/api/v1/app/me/work-time') {
      app.statusReads += 1;
      const body = JSON.stringify({ ok: true, data: app.workTime });
      const pending = app.pendingStatus;
      app.pendingStatus = null;
      if (pending) await pending;
      return route.fulfill({ contentType: 'application/json', body });
    }
    if (pathname === '/api/v1/settings/company') return json({ data: { company_name: 'Testfirma' } });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, can_subscribe: false, devices: [] } });
    if (request.method() === 'POST' && (pathname === '/api/v1/app/timesheets/sync' || pathname.includes('/work-time/proposals/'))) {
      const payload = request.postDataJSON();
      app.mutations.push({ pathname, payload });
      if (app.pendingMutation) await app.pendingMutation;
      if (options.mutate) return options.mutate({ app, pathname, payload, json });
      return json({ ok: true, data: { message: 'Gespeichert' } });
    }
    if (pathname.startsWith('/api/')) {
      app.unexpected.push(pathname);
      return json({ ok: false, message: 'Unexpected mocked endpoint' }, 501);
    }
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto('/app/heute');
  await expect(page.getByRole('heading', { name: 'Tagesstatus', exact: true })).toBeVisible();
  await page.waitForLoadState('networkidle');
  return app;
}

const workingEntry = () => ({ id: 9, user_id: 7, project_id: 1, project_name: 'Testbaustelle', entry_type: 'work', work_date: '2026-11-16', start_time: '08:00:00', end_time: null, gross_minutes: 0, break_minutes: 0, net_minutes: 0, attachments: [] });
const proposal = () => ({ id: 5, timesheet_id: 9, proposed_end_at: '2026-11-16 16:00:00', status: 'pending' });

test('disabled feature leaves the app unchanged and makes no work-time API requests', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { enabled: false });
  await expect(page.getByRole('heading', { name: 'Kernarbeitszeit', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_in"]')).toBeVisible();
  expect(app.statusReads).toBe(0);
  expect(app.unexpected).toEqual([]);
});

test('employees without an assignment get no new workflow or provisional labels', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { noPolicy: true });
  await expect(page.getByRole('heading', { name: 'Kernarbeitszeit', exact: true })).toHaveCount(0);
  await expect(page.getByText('Fensteranrechnung (unverbindliche Vorschau)', { exact: true })).toHaveCount(0);
  expect(app.mutations).toEqual([]);
});

test('presence check-in never displays invented work while the request is pending', async ({ page }) => {
  let release;
  const app = await mockWorkTimeApp(page, { presence: true, mutate: async ({ app, json }) => {
    app.workTime.presence_session = { id: 2, work_date: '2026-11-16', started_at: '2026-11-16 07:30:00', project_id: 1 };
    return json({ ok: true, data: { status: 'presence_only', timesheet: null } });
  } });
  app.pendingMutation = new Promise((resolve) => { release = resolve; });
  await page.locator('[data-action="check_in"]').click();
  await expect.poll(() => app.mutations.length).toBe(1);
  await expect(page.locator('[data-live-start-time]')).toHaveText('--:--');
  await expect(page.locator('[data-action="check_out"]')).toHaveCount(0);
  release();
  await expect(page.getByText('Außerhalb der Kernarbeitszeit anwesend', { exact: true })).toBeVisible();
  await expect(page.locator('[data-action="check_out"]')).toHaveCount(0);
  expect(app.mutations[0].payload.csrf_token).toBe('work-time-csrf');
  expect(app.unexpected).toEqual([]);
});

test('confirmed presence work-start sends the actual calendar date across midnight', async ({ page }) => {
  const app = await mockWorkTimeApp(page, {
    presence: true,
    presenceSession: { id: 2, work_date: '2026-11-15', started_at: '2026-11-15 21:00:00', project_id: 1 },
    mutate: async ({ app, payload, json }) => {
      app.workTime.presence_session = null;
      app.entry = { ...workingEntry(), work_date: payload.work_date, start_time: payload.start_time + ':00' };
      return json({ ok: true, data: { status: 'work_started', timesheet: app.entry } });
    }
  });
  await page.locator('#workTimeActualStart').fill('2026-11-16T00:10');
  await page.getByRole('button', { name: 'Ich beginne zu arbeiten', exact: true }).click();
  await expect.poll(() => app.mutations.length).toBe(1);
  expect(app.mutations[0].payload).toMatchObject({ action: 'work_start', work_date: '2026-11-16', start_time: '00:10', project_id: 1 });
  await expect(page.locator('#workTimeActualStart')).toHaveCount(0);
  await expect(page.locator('[data-action="check_out"]')).toBeVisible();
});

test('pure attendance has its own status and blocks ordinary time writes on today and times', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { presence: true,
    presenceSession: { id: 2, work_date: '2026-11-16', started_at: '2026-11-16 07:30:00', project_id: 1 }
  });
  for (const routeName of ['heute', 'zeiten']) {
    if (routeName === 'zeiten') {
      await page.evaluate(() => {
        window.history.pushState({}, '', '/app/zeiten');
        window.dispatchEvent(new PopStateEvent('popstate'));
      });
      await expect(page.getByRole('heading', { name: 'Arbeitszeiten', exact: true })).toBeVisible();
    }
    await expect(page.locator('[data-live-topbar-status]')).toHaveText('Anwesend');
    await expect(page.locator('[data-live-status]')).toHaveText('Anwesend (Arbeitsbeginn offen)');
    await expect(page.locator('#saveUpsert')).toBeDisabled();
    await expect(page.locator('[data-action="check_in"]')).toHaveCount(0);
    await expect(page.locator('[data-action="check_out"]')).toHaveCount(0);
    await expect(page.locator('[data-live-work-duration]').first()).toHaveText('00:00');
    await expect(page.getByRole('button', { name: 'Ich beginne zu arbeiten', exact: true })).toBeVisible();
  }
  expect(app.mutations).toEqual([]);
});

test('an old presence without a current assignment is explicitly shown as historical', async ({ page }) => {
  await mockWorkTimeApp(page, { noPolicy: true,
    presenceSession: { id: 2, work_date: '2026-11-15', started_at: '2026-11-15 21:00:00', project_id: 1 }
  });
  await expect(page.locator('.app-worktime')).toContainText('Heute keine aktive Kernarbeitszeitregel.');
  await expect(page.locator('.app-worktime')).toContainText('früher gültigen Regel');
  await expect(page.locator('.app-worktime')).not.toContainText('Nur für Sie aktivierte Regel.');
  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Anwesend');
});

test('an assigned overnight session can be explicitly ended before any proposal exists', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-11-16T02:30:00+01:00') });
  const app = await mockWorkTimeApp(page, { noPolicy: true,
    activeWorkEntry: { id: 42, user_id: 7, work_date: '2026-11-15', start_time: '22:00:00', project_id: 1, current_break: null },
    mutate: async ({ app, payload, json }) => {
      app.workTime.active_work_entry = null;
      return json({ ok: true, data: { message: 'Tatsächliches Ende gespeichert' } });
    }
  });
  await expect(page.getByRole('heading', { name: 'Laufender Einsatz vom 15.11.2026', exact: true })).toBeVisible();
  await expect(page.locator('[data-action="check_in"]')).toHaveCount(0);
  await expect(page.locator('#saveUpsert')).toBeDisabled();
  expect(app.mutations).toEqual([]);
  await page.getByRole('button', { name: 'Jetzt beenden', exact: true }).click();
  await expect.poll(() => app.mutations.length).toBe(1);
  expect(app.mutations[0].payload).toMatchObject({ action: 'check_out', work_date: '2026-11-15', expected_timesheet_id: 42, expected_start_time: '22:00:00', end_time: '02:30', actual_end_at: '2026-11-16T02:30' });
  await expect(page.getByRole('region', { name: 'Laufender Einsatz aus früherem Tag', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_in"]')).toBeVisible();
});

test('an overnight pause requires its own confirmation before closing the session', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-11-16T02:30:00+01:00') });
  const app = await mockWorkTimeApp(page, {
    activeWorkEntry: { id: 42, user_id: 7, work_date: '2026-11-15', start_time: '22:00:00', project_id: 1, current_break: { id: 12, break_started_at: '2026-11-16 02:00:00', break_ended_at: null } },
    mutate: async ({ app, json }) => {
      app.workTime.active_work_entry.current_break = null;
      return json({ ok: true, data: { message: 'Tatsächliches Pausenende gespeichert' } });
    }
  });
  await expect(page.getByRole('button', { name: 'Jetzt beenden', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Pause des laufenden Einsatzes beenden', exact: true }).click();
  await expect.poll(() => app.mutations.length).toBe(1);
  expect(app.mutations[0].payload).toMatchObject({ action: 'pause_end', work_date: '2026-11-15', expected_timesheet_id: 42, break_ended_at: '2026-11-16T02:30:00' });
  await expect(page.getByRole('button', { name: 'Jetzt beenden', exact: true })).toBeEnabled();
});

test('overnight controls cannot appear when the feature is disabled', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { enabled: false,
    activeWorkEntry: { id: 42, user_id: 7, work_date: '2026-11-15', start_time: '22:00:00', project_id: 1 }
  });
  await expect(page.getByRole('button', { name: 'Jetzt beenden', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_in"]')).toBeVisible();
  await expect(page.locator('#saveUpsert')).toBeEnabled();
  expect(app.statusReads).toBe(0);
});

test('an overlong session cannot be closed with an ambiguous legacy end date', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-11-16T17:00:00+01:00') });
  const app = await mockWorkTimeApp(page, {
    activeWorkEntry: { id: 42, user_id: 7, work_date: '2026-11-15', start_time: '08:00:00', project_id: 1, current_break: null }
  });
  await expect(page.getByRole('button', { name: 'Jetzt beenden', exact: true })).toBeDisabled();
  await expect(page.locator('.app-worktime [role="alert"]')).toContainText('Bitte im Büro');
  await expect(page.locator('[data-action="check_in"]')).toHaveCount(0);
  expect(app.mutations).toEqual([]);
});

test('multiple earlier work sessions require clarification instead of selecting one', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { noPolicy: true, activeWorkConflict: true });
  await expect(page.locator('.app-worktime [role="alert"]')).toContainText('Mehrere offene Einsätze');
  await expect(page.getByRole('button', { name: 'Jetzt beenden', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_in"]')).toHaveCount(0);
  await expect(page.locator('#saveUpsert')).toBeDisabled();
  expect(app.mutations).toEqual([]);
});

test('unplaced pauses are clearly unresolved and never rendered as zero accountable time', async ({ page }) => {
  const app = await mockWorkTimeApp(page);
  app.workTime.preview = { actual_work_minutes: 503, accountable_work_minutes: null, outside_pending_minutes: null, rows: [{ timesheet_id: 9 }], calculation_status: 'needs_clarification' };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('.app-worktime .app-stat-row').filter({ hasText: 'Fensteranrechnung (unverbindliche Vorschau)' }).locator('strong')).toHaveText('klärungsbedürftig');
  await expect(page.locator('.app-worktime')).toContainText('keine genaue Zuordnung möglich');
  expect(app.mutations).toEqual([]);
});

test('a running session is kept separate from completed work in the window preview', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry() });
  app.workTime.preview = { actual_work_minutes: 20, accountable_work_minutes: null, outside_pending_minutes: null, rows: [
    { timesheet_id: 9, calculation: { calculation_status: 'open', actual_work_minutes: 0, accountable_work_minutes: null, outside_pending_minutes: null } },
    { timesheet_id: 8, calculation: { calculation_status: 'pending', actual_work_minutes: 20, accountable_work_minutes: 10, outside_pending_minutes: 10 } }
  ] };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('.app-worktime')).toContainText('Laufender Einsatz: siehe Timer.');
  await expect(page.locator('.app-worktime .app-stat-row').filter({ hasText: 'Tatsächliche Arbeit (abgeschlossen)' }).locator('strong')).toHaveText('00:20');
  await expect(page.locator('.app-worktime .app-stat-row').filter({ hasText: 'Fensteranrechnung (unverbindliche Vorschau)' }).locator('strong')).toHaveText('00:10');
  await expect(page.locator('[data-action="check_out"]')).toBeVisible();
});

test('continuing a checkout proposal preserves the live session and does not queue a checkout', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry(), proposals: [proposal()], mutate: async ({ app, payload, json }) => {
    expect(payload.action).toBe('continue');
    app.workTime.checkout_proposals = [];
    return json({ ok: true, data: { message: 'Weiterarbeit dokumentiert' } });
  } });
  await page.getByRole('button', { name: 'Ich arbeite weiter', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_out"]')).toBeVisible();
  expect(app.entry.end_time).toBeNull();
  expect(app.mutations).toHaveLength(1);
  expect(app.mutations[0].pathname).toBe('/api/v1/app/work-time/proposals/5/respond');
});

for (const enabled of [true, false]) {
  test('normal checkout ' + (enabled ? 'is bound to its assigned work session' : 'retains the disabled-feature payload'), async ({ page }) => {
    const app = await mockWorkTimeApp(page, { enabled, entry: workingEntry(), mutate: async ({ app, payload, json }) => {
      app.entry.end_time = payload.end_time;
      return json({ ok: true, data: { timesheet: app.entry } });
    } });
    await page.locator('[data-action="check_out"]').click();
    await expect.poll(() => app.mutations.length).toBe(1);
    expect(app.mutations[0].payload.action).toBe('check_out');
    if (enabled) {
      expect(app.mutations[0].payload).toMatchObject({ expected_timesheet_id: 9, expected_start_time: '08:00:00' });
    } else {
      expect(app.mutations[0].payload.expected_timesheet_id).toBeUndefined();
      expect(app.mutations[0].payload.expected_start_time).toBeUndefined();
    }
    expect(app.unexpected).toEqual([]);
  });
}

test('a confirmed proposal permits a new independent check-in on the same day', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry(), proposals: [proposal()], mutate: async ({ app, payload, json }) => {
    if (payload.action === 'confirm') {
      app.workTime.checkout_proposals = [];
      app.entry.end_time = '16:00:00';
    } else {
      expect(payload.action).toBe('check_in');
      app.entry = { ...workingEntry(), id: 10, start_time: payload.start_time + ':00' };
    }
    return json({ ok: true, data: { timesheet: app.entry } });
  } });
  await page.getByRole('button', { name: 'Ich habe um 16:00 Uhr aufgehört', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  await page.locator('[data-action="check_in"]').click();
  await expect.poll(() => app.mutations.length).toBe(2);
  await expect(page.locator('[data-action="check_out"]')).toBeVisible();
  expect(app.entry.id).toBe(10);
  expect(app.mutations[0].payload.action).toBe('confirm');
  expect(app.mutations[1].payload.action).toBe('check_in');
});

test('corrected proposal keeps the draft through refresh and sends an explicit actual end', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry(), proposals: [proposal()], mutate: async ({ app, payload, json }) => {
    app.workTime.checkout_proposals = [];
    app.entry.end_time = '17:20:00';
    return json({ ok: true, data: { message: 'Tatsächliches Arbeitsende dokumentiert' } });
  } });
  await page.locator('[data-worktime-end="5"]').fill('2026-11-16T17:20');
  const previousReads = app.statusReads;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => app.statusReads).toBeGreaterThan(previousReads);
  await expect(page.locator('[data-worktime-end="5"]')).toHaveValue('2026-11-16T17:20');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: test.info().outputPath('work-time-mobile.png'), fullPage: true });
  await page.getByRole('button', { name: 'Ich habe länger gearbeitet', exact: true }).click();
  await expect.poll(() => app.mutations.length).toBe(1);
  expect(app.mutations[0].payload).toMatchObject({ action: 'correct', actual_end_at: '2026-11-16T17:20', csrf_token: 'work-time-csrf' });
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-action="check_in"]')).toBeVisible();
});

test('offline proposals cannot produce an end and are revalidated after reconnect', async ({ page, context }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry(), proposals: [proposal()] });
  await context.setOffline(true);
  await expect(page.getByRole('button', { name: 'Ich habe um 16:00 Uhr aufgehört', exact: true })).toBeDisabled();
  await expect(page.locator('.app-worktime')).toContainText('Offline:');
  app.workTime.checkout_proposals = [];
  const reads = app.statusReads;
  await context.setOffline(false);
  await expect.poll(() => app.statusReads).toBeGreaterThan(reads);
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  expect(app.mutations).toEqual([]);
  expect(app.entry.end_time).toBeNull();
});

test('a superseded status response cannot resurrect a proposal after navigation', async ({ page }) => {
  const app = await mockWorkTimeApp(page, { entry: workingEntry(), proposals: [proposal()] });
  let release;
  app.pendingStatus = new Promise((resolve) => { release = resolve; });
  const initialReads = app.statusReads;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => app.statusReads).toBeGreaterThan(initialReads);
  app.workTime.checkout_proposals = [];
  await page.evaluate(() => {
    window.history.pushState({}, '', '/app/zeiten');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
  await expect.poll(() => app.statusReads).toBeGreaterThan(initialReads + 1);
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  release();
  await page.waitForLoadState('networkidle');
  await page.locator('#appMenuToggle').click();
  await expect(page.getByRole('region', { name: 'Arbeitsende bestätigen', exact: true })).toHaveCount(0);
  expect(app.mutations).toEqual([]);
});

test('admin preview ignores a superseded response and invalidates approval after edits', async ({ page }) => {
  let release;
  let requested = 0;
  const pending = new Promise((resolve) => { release = resolve; });
  await page.route('**/*', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/admin/work-time') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<meta charset="utf-8">' + adminForm + '<script src="/assets/js/admin-work-time.js"></script>' });
    if (pathname === '/assets/js/admin-work-time.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(projectRoot, 'public/assets/js/admin-work-time.js')) });
    if (pathname === '/admin/work-time/preview') {
      requested += 1;
      if (requested === 1) await pending;
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: { actual_work_minutes: 503, inside_window_minutes: 450, outside_before_minutes: 18, outside_after_minutes: 35, outside_pending_minutes: 53, accountable_work_minutes: 450, calculation_status: 'pending' }, preview_token: 'preview-' + requested }) });
    }
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto('/admin/work-time');
  await page.locator('[data-worktime-preview]').click();
  await expect.poll(() => requested).toBe(1);
  await page.locator('[name="window_1"]').fill('09:00-16:00');
  release();
  await expect(page.locator('[data-worktime-save]')).toBeDisabled();
  await expect(page.locator('[name="preview_token"]')).toHaveValue('');
  await page.locator('[data-worktime-preview]').click();
  await expect(page.locator('[data-worktime-save]')).toBeEnabled();
  await expect(page.locator('[data-worktime-result]')).toContainText('Offene Außenzeit: 53 Min.');
  await page.locator('[name="checkout_proposals_enabled"]').check();
  await expect(page.locator('[data-worktime-save]')).toBeDisabled();
  await expect(page.locator('[name="preview_token"]')).toHaveValue('');
});
