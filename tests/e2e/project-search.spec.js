const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block', locale: 'de-DE' });

const projectRoot = path.join(__dirname, '../..');
const appShell = execFileSync('php', ['-r',
  'function base_path(string $path): string { return getcwd() . "/" . $path; } '
  + 'require "src/Presentation/App/AppView.php"; '
  + 'echo (new \\App\\Presentation\\App\\AppView("Projektsuche"))->render("/app/projektwahl", ["app_name" => "Projektsuche"]);'
], { cwd: projectRoot, encoding: 'utf8' });

async function mockProjectSearchApp(page, routeName, options = {}) {
  const app = {
    dayReads: 0,
    mutations: [],
    unexpected: [],
    entry: null,
    projects: [
      { id: 1, project_number: 'P-001', name: 'Lager Süd' },
      { id: 2, project_number: 'BAU-2026-042', name: 'Brückenstraße Nord' },
      { id: 3, project_number: 'P-003', name: 'Rathaus West – Märkische Straße' },
      ...Array.from({ length: options.additionalProjects || 0 }, (_, index) => ({ id: 100 + index, project_number: 'P-' + (100 + index), name: 'Baustelle ' + (100 + index) }))
    ]
  };
  const user = { id: 7, display_name: 'Max Beispiel', email: 'max@example.test', permissions: ['timesheets.create', 'timesheets.view_own', 'files.view', 'files.upload'] };
  const flags = { show_project_files: !!options.showFiles, show_project_materials: !!options.showMaterials, show_geo_section: false, show_personnel_overview: false };
  const todayState = () => ({ status: app.entry ? 'working' : 'not_started', is_missing: false, booking_required: false, work_entry: app.entry, status_entry: null, current_break: null });
  await page.addInitScript(() => {
    localStorage.setItem('app.projectSelectionId', '1');
    localStorage.setItem('app.preferredProjectId', '1');
  });
  // Catch every request: these browser checks never reach a live API or DB.
  await page.route('**/*', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') return route.fulfill({ contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(projectRoot, 'public', pathname)) });
    if (pathname === '/app' || pathname.startsWith('/app/')) return route.fulfill({ contentType: 'text/html', body: appShell });
    if (pathname === '/api/v1/auth/session') return json({ authenticated: true, bootstrap_required: false, user });
    if (pathname === '/api/v1/app/me/day') {
      app.dayReads += 1;
      return json({ data: {
        today: '2026-11-06', server_time: '2026-11-06T12:00:00+01:00',
        user: { ...user, roles: [], time_tracking_required: false, app_ui_settings: flags }, app_ui_settings: flags,
        projects: app.projects, today_state: todayState(),
        project_day_summaries: [], attachments: [], breaks_today: [], current_break: null,
        company: {}, geo_policy: { enabled: false }, personnel_events: [], personnel_labels: []
      } });
    }
    if (pathname === '/api/v1/settings/company') return json({ data: { company_name: 'Musterbau' } });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, can_subscribe: false, devices: [] } });
    if (/^\/api\/v1\/app\/projects\/\d+\/(files|materials|work-reports)$/.test(pathname)) return json({ ok: true, data: [] });
    if (request.method() !== 'GET') app.mutations.push({ pathname, method: request.method(), payload: request.postDataJSON() });
    if (pathname === '/api/v1/app/timesheets/sync' && request.method() === 'POST') {
      const payload = request.postDataJSON();
      const project = app.projects.find((item) => item.id === Number(payload.project_id));
      app.entry = { id: 500, project_id: project ? project.id : null, project_name: project ? project.name : null, entry_type: 'work', work_date: '2026-11-06', start_time: '2026-11-06 08:00:00', end_time: null, break_minutes: 0, net_minutes: 0, note: payload.note || '', attachments: [] };
      return json({ ok: true, data: { today_state: todayState(), timesheet: app.entry, breaks_today: [], current_break: null } });
    }
    if (pathname.startsWith('/api/')) {
      app.unexpected.push(pathname);
      return json({ message: 'Unexpected mocked endpoint' }, 501);
    }
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto('/app/' + routeName);
  await expect(page.locator('#projectSearch')).toBeVisible();
  await expect(page.locator('#projectSelect')).toHaveValue('1');
  await page.waitForLoadState('networkidle');
  return app;
}

async function visibleProjectIds(page) {
  return page.locator('#projectSelect').evaluate((select) => Array.from(select.options).filter((option) => !option.hidden).map((option) => option.value));
}

async function revalidate(page, app) {
  const reads = app.dayReads;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => app.dayReads).toBeGreaterThan(reads);
  await page.waitForLoadState('networkidle');
}

for (const routeName of ['heute', 'zeiten', 'projektwahl']) {
  test('unique project search selects its real match without booking on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    const search = page.locator('#projectSearch');
    const select = page.locator('#projectSelect');
    await search.fill('rAtHaUs');
    expect(await visibleProjectIds(page)).toEqual(['', '3']);
    await expect(select).toHaveValue('3');
    await expect(page.locator('#projectSearchStatus')).toContainText('1');
    await search.fill('bau-2026-042');
    expect(await visibleProjectIds(page)).toEqual(['', '2']);
    await expect(select).toHaveValue('2');
    await search.fill('bruckenstrasse NORD');
    expect(await visibleProjectIds(page)).toEqual(['', '2']);
    await search.fill('markische STRASSE');
    expect(await visibleProjectIds(page)).toEqual(['', '3']);
    await expect(select).toHaveValue('3');
    await search.fill('2026-042 NORD');
    expect(await visibleProjectIds(page)).toEqual(['', '2']);
    await search.fill('2026-042 WEST');
    expect(await visibleProjectIds(page)).toEqual(['', '2']);
    await search.fill('ohne jeden Treffer');
    expect(await visibleProjectIds(page)).toEqual(['', '2']);
    await expect(select).toHaveValue('2');
    await expect(page.locator('#projectSearchStatus')).toContainText(/0|kein/i);
    await search.fill('');
    expect(await visibleProjectIds(page)).toEqual(['', '1', '2', '3']);
    await expect(select).toHaveValue('2');
    await search.fill('strasse');
    expect(await visibleProjectIds(page)).toEqual(['', '2', '3']);
    await expect(select).toHaveValue('2');
    await expect(search).toBeFocused();
    expect(await search.evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([7, 7]);
    expect(app.mutations).toEqual([]);
    expect(app.unexpected).toEqual([]);
  });

  test('search survives revalidation and native project changes on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    await page.locator('#projectSearch').fill('rathaus');
    await page.locator('#projectSearch').evaluate((element) => element.setSelectionRange(2, 5));
    await revalidate(page, app);
    await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
    expect(await visibleProjectIds(page)).toEqual(['', '3']);
    await expect(page.locator('#projectSearch')).toBeFocused();
    expect(await page.locator('#projectSearch').evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([2, 5]);
    await page.locator('#projectSelect').selectOption('3');
    await expect(page.locator('#projectSelect')).toHaveValue('3');
    await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
    await revalidate(page, app);
    await expect(page.locator('#projectSelect')).toHaveValue('3');
    await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
    await page.locator('#projectSelect').selectOption('');
    await expect(page.locator('#projectSelect')).toHaveValue('');
    await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
    await revalidate(page, app);
    await expect(page.locator('#projectSelect')).toHaveValue('');
    // Repeated same-value search/input events must not undo a manual override.
    await page.locator('#projectSearch').evaluate((element) => {
      element.dispatchEvent(new Event('search', { bubbles: true }));
      element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: null }));
    });
    await expect(page.locator('#projectSelect')).toHaveValue('');
    await page.locator('#projectSearch').fill('rathaus west');
    await expect(page.locator('#projectSelect')).toHaveValue('3');
    expect(app.mutations).toEqual([]);
  });

  test('a refresh never auto-selects a newly unique result on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    await page.locator('#projectSearch').fill('strasse');
    await expect(page.locator('#projectSelect')).toHaveValue('1');
    app.projects = app.projects.filter((project) => project.id !== 3);
    await revalidate(page, app);
    expect(await visibleProjectIds(page)).toEqual(['', '1', '2']);
    await expect(page.locator('#projectSelect')).toHaveValue('1');
    expect(app.mutations).toEqual([]);
  });

  test('IME composition delays auto-selection until compositionend on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    await page.locator('#projectSearch').evaluate((element) => {
      element.focus();
      window.__composingProjectSearch = element;
      element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
      element.value = '2026-042';
      element.setSelectionRange(2, 6);
      element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: '2026-042', isComposing: true }));
    });
    await expect(page.locator('#projectSelect')).toHaveValue('1');
    expect(await page.locator('#projectSearch').evaluate((element) => element === window.__composingProjectSearch && document.activeElement === element)).toBeTruthy();
    await page.locator('#projectSearch').evaluate((element) => element.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '2026-042' })));
    await expect(page.locator('#projectSelect')).toHaveValue('2');
    await expect(page.locator('#projectSearch')).toBeFocused();
    expect(await page.locator('#projectSearch').evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([2, 6]);
    expect(app.mutations).toEqual([]);
  });

  test('revoked projects disappear from filtered options on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    await page.locator('#projectSearch').fill('2026-042');
    await expect(page.locator('#projectSelect option[value="2"]')).toHaveCount(1);
    app.projects = app.projects.filter((project) => project.id === 3);
    await revalidate(page, app);
    await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
    await expect(page.locator('#projectSelect option[value="2"]')).toHaveCount(0);
    expect(await visibleProjectIds(page)).toEqual(['']);
    expect(app.mutations).toEqual([]);
  });
}

test('cancelled IME composition preserves a manual unassigned choice when the query is unchanged', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'heute');
  await page.locator('#projectSearch').fill('rathaus');
  await page.locator('#projectSelect').selectOption('');
  await page.locator('#projectSearch').evaluate(async (element) => {
    element.focus();
    element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    element.value = 'rathau';
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: 'rathau', isComposing: true }));
    element.value = 'rathaus';
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: 'rathaus', isComposing: true }));
    element.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '' }));
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: null }));
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
  await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
  await expect(page.locator('#projectSearch')).toBeFocused();
  await expect(page.locator('#projectSelect')).toHaveValue('');
  expect(app.mutations).toEqual([]);
});

test('a route change without compositionend does not leave the new search stuck in composing mode', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'heute');
  await page.locator('#projectSearch').evaluate((element) => {
    element.focus();
    window.__abandonedCompositionInput = element;
    element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    window.history.pushState({}, '', '/app/zeiten');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
  await expect(page).toHaveURL('/app/zeiten');
  expect(await page.evaluate(() => window.__abandonedCompositionInput.isConnected)).toBeFalsy();
  await page.locator('#projectSearch').fill('2026-042');
  await expect(page.locator('#projectSelect')).toHaveValue('2');
  await expect(page.locator('#projectSearch')).toBeFocused();
  expect(app.mutations).toEqual([]);
});

test('starting a second composition cancels the previous deferred automatic selection', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'projektwahl');
  const intermediateValue = await page.locator('#projectSearch').evaluate(async (element) => {
    element.focus();
    element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    element.value = '2026-042';
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: '2026-042', isComposing: true }));
    element.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '2026-042' }));
    // Stay in the same browser task so the first 0-ms callback cannot run yet.
    element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 0));
    return document.getElementById('projectSelect').value;
  });
  expect(intermediateValue).toBe('1');
  await page.locator('#projectSearch').evaluate((element) => {
    element.value = '2026-042 n';
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: 'n', isComposing: true }));
    element.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '2026-042 n' }));
  });
  await expect(page.locator('#projectSelect')).toHaveValue('2');
  await expect(page.locator('#projectSearch')).toBeFocused();
  expect(app.mutations).toEqual([]);
});

test('search text stays separate for each app route during navigation', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'heute');
  await page.locator('#projectSearch').fill('rathaus');
  await page.locator('#appMenuToggle').click();
  await page.locator('.app-drawer-nav [data-app-link][href="/app/zeiten"]').click();
  await expect(page.locator('#projectSearch')).toHaveValue('');
  await page.locator('#projectSearch').fill('p-001');
  await page.locator('#appMenuToggle').click();
  await page.locator('.app-drawer-nav [data-app-link][href="/app/heute"]').click();
  await expect(page.locator('#projectSearch')).toHaveValue('rathaus');
  await expect(page.locator('#projectSelect')).toHaveValue('1');
  expect(app.mutations).toEqual([]);
});

for (const routeName of ['heute', 'zeiten']) {
  test('a unique search supports direct check-in without opening the dropdown on ' + routeName, async ({ page }) => {
    const app = await mockProjectSearchApp(page, routeName);
    if (routeName === 'heute') {
      await page.locator('#timesheetNote').fill('Notiz vor der Projektsuche');
    } else {
      await page.locator('#manualStartTime').fill('07:45');
      await page.locator('#manualEndTime').fill('16:15');
    }
    await page.locator('#projectSearch').fill('2026-042');
    await expect(page.locator('#projectSelect')).toHaveValue('2');
    if (routeName === 'heute') {
      await expect(page.locator('#timesheetNote')).toHaveValue('Notiz vor der Projektsuche');
    } else {
      await expect(page.locator('#manualStartTime')).toHaveValue('07:45');
      await expect(page.locator('#manualEndTime')).toHaveValue('16:15');
    }
    expect(app.mutations).toEqual([]);
    await page.locator('[data-action="check_in"]').click();
    await expect.poll(() => app.mutations.length).toBe(1);
    expect(app.mutations[0]).toMatchObject({ pathname: '/api/v1/app/timesheets/sync', payload: { action: 'check_in', project_id: '2' } });
    if (routeName === 'heute') expect(app.mutations[0].payload.note).toBe('Notiz vor der Projektsuche');
    else expect(app.mutations[0].payload.start_time).toBe('07:45');
  });
}

test('an already selected attachment prevents an automatic project change', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'projektwahl', { showFiles: true });
  await expect(page.locator('#projectAttachmentInput')).toBeEnabled();
  await page.locator('#projectAttachmentInput').setInputFiles({ name: 'baustelle.jpg', mimeType: 'image/jpeg', buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9]) });
  await page.locator('#projectSearch').fill('2026-042');
  await expect(page.locator('#projectSelect')).toHaveValue('1');
  expect(await page.locator('#projectAttachmentInput').evaluate((element) => element.files.length)).toBe(1);
  await expect(page.locator('#projectSearchStatus')).toContainText(/datei/i);
  expect(app.mutations).toEqual([]);
});

test('auto-selection changes the project context while retaining separate report and material drafts', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'projektwahl', { showMaterials: true });
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await page.locator('#projectWorkReportDescription').fill('Bericht nur für Lager Süd');
  await page.locator('#projectMaterialNote').fill('Materialnotiz nur für Lager Süd');
  await page.locator('#projectSearch').fill('rathaus');
  await expect(page.locator('#projectSelect')).toHaveValue('3');
  await expect(page.locator('#projectWorkReportForm')).toHaveAttribute('data-project-id', '3');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await expect(page.locator('#projectMaterialNote')).toHaveValue('');
  await page.locator('#projectWorkReportDescription').fill('Bericht nur für Rathaus West');
  await page.locator('#projectMaterialNote').fill('Materialnotiz nur für Rathaus West');
  await page.locator('#projectSearch').fill('p-001');
  await expect(page.locator('#projectWorkReportForm')).toHaveAttribute('data-project-id', '1');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Bericht nur für Lager Süd');
  await expect(page.locator('#projectMaterialNote')).toHaveValue('Materialnotiz nur für Lager Süd');
  await page.locator('#projectSearch').fill('rathaus west');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Bericht nur für Rathaus West');
  await expect(page.locator('#projectMaterialNote')).toHaveValue('Materialnotiz nur für Rathaus West');
  expect(app.mutations).toEqual([]);
});

test('a large project list selects the unique match and only an explicit check-in books time', async ({ page }) => {
  const app = await mockProjectSearchApp(page, 'projektwahl', { additionalProjects: 150 });
  expect(await visibleProjectIds(page)).toHaveLength(154);
  await page.locator('#projectSearch').fill('p-184');
  expect(await visibleProjectIds(page)).toEqual(['', '184']);
  await expect(page.locator('#projectSelect')).toHaveValue('184');
  await expect(page.locator('#projectSearch')).toHaveValue('p-184');
  await page.locator('#saveProjectSelection').click();
  await expect(page).toHaveURL('/app/heute');
  await expect(page.locator('#projectSelect')).toHaveValue('184');
  expect(app.mutations).toEqual([]);
  await page.locator('[data-action="check_in"]').click();
  await expect.poll(() => app.mutations.length).toBe(1);
  expect(app.mutations[0]).toMatchObject({ pathname: '/api/v1/app/timesheets/sync', payload: { action: 'check_in', project_id: '184' } });
});

for (const theme of ['light', 'dark']) {
  test('project search is usable on a narrow screen in ' + theme, async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript((mode) => localStorage.setItem('app.theme', mode), theme);
    const app = await mockProjectSearchApp(page, 'projektwahl');
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    await expect(page.locator('#projectSearch')).toHaveAttribute('type', 'search');
    await expect(page.locator('#projectSearchStatus')).toHaveAttribute('role', 'status');
    await expect(page.locator('#projectSearchStatus')).toHaveAttribute('aria-live', 'polite');
    await page.locator('#projectSearch').fill('rathaus');
    await expect(page.locator('#projectSearch')).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    expect(await page.locator('#projectSearch').evaluate((element) => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
    await page.locator('#projectSearch').scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('project-search-' + theme + '.png') });
    expect(app.mutations).toEqual([]);
  });
}
