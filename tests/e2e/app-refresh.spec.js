const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

// Serve the actual application shell and assets without contacting a database
// or a live API. Even mutation requests are fulfilled entirely in this fixture.
const projectRoot = path.join(__dirname, '../..');
const appShell = execFileSync('php', ['-r',
  'function base_path(string $path): string { return getcwd() . "/" . $path; } '
  + 'require "src/Presentation/App/AppView.php"; '
  + 'echo (new \\App\\Presentation\\App\\AppView("Refresh-Test"))->render("/app/projektwahl", ["app_name" => "Refresh-Test"]);'
], { cwd: projectRoot, encoding: 'utf8' });

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

async function mockRefreshApp(page) {
  const state = {
    dayRequests: 0,
    expireSession: false,
    postedMaterials: [],
    postedReports: [],
    holdMaterial: null,
    holdMaterialPost: null,
    holdReportPost: null,
    reportPostError: null,
    projects: [1, 2].map((id) => ({
      id, project_number: 'P-00' + id, name: 'Baustelle ' + id,
      customer_name: 'Kunde ' + id, city: 'Berlin',
      work_instructions: Array.from({ length: 18 }, (_, index) => 'Arbeitsschritt ' + (index + 1) + ': Leitung prüfen.').join('\n')
    })),
    materials: {
      1: Array.from({ length: 10 }, (_, index) => ({
        id: index + 1, project_id: 1, description: 'Material ' + (index + 1),
        quantity: '1.000', unit: 'm', work_date: '2026-11-05',
        created_by_user_id: 7, created_by_name: 'Max Beispiel'
      })),
      2: [{ id: 81, project_id: 2, description: 'Material für Projekt Zwei', quantity: '2.000', work_date: '2026-11-05', created_by_user_id: 8 }]
    },
    reports: { 1: [], 2: [] }
  };
  const flags = { show_project_files: true, show_project_work_instructions: true, show_project_materials: true };
  const user = { id: 7, display_name: 'Max Beispiel', email: 'max@example.test', permissions: ['timesheets.create', 'timesheets.view_own', 'files.view', 'files.upload'] };
  await page.route('**/*', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') {
      return route.fulfill({ contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(projectRoot, 'public', pathname)) });
    }
    if (pathname === '/app' || pathname.startsWith('/app/')) return route.fulfill({ contentType: 'text/html', body: appShell });
    if (pathname === '/api/v1/auth/session') return json({ authenticated: true, bootstrap_required: false, user });
    if (pathname === '/api/v1/auth/logout') return json({ ok: true });
    if (pathname === '/api/v1/app/me/day') {
      state.dayRequests += 1;
      if (state.expireSession) return json({ message: 'Sitzung abgelaufen' }, 401);
      return json({ data: {
        today: '2026-11-06', server_time: '2026-11-06T12:00:00+01:00',
        user: { ...user, roles: [], app_ui_settings: flags }, app_ui_settings: flags,
        projects: state.projects, attachments: [],
        today_state: { status: 'not_started', is_missing: false, work_entry: null, status_entry: null, current_break: null },
        current_break: null, breaks_today: [], project_day_summaries: [],
        company: {}, geo_policy: {}, personnel_events: [], personnel_labels: []
      } });
    }
    if (pathname === '/api/v1/settings/company') return json({ data: { company_name: 'Refresh-Test' } });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, can_subscribe: false, devices: [] } });
    const projectMatch = pathname.match(/^\/api\/v1\/app\/projects\/(\d+)\/(files|materials|work-reports)$/);
    if (projectMatch) {
      const id = Number(projectMatch[1]);
      const type = projectMatch[2];
      if (type === 'files') return json({ ok: true, data: [] });
      if (type === 'work-reports') {
        if (request.method() === 'POST') {
          const payload = request.postDataJSON();
          state.postedReports.push({ projectId: id, payload });
          if (state.holdReportPost) await state.holdReportPost.promise;
          if (state.reportPostError) return json({ message: state.reportPostError }, 422);
          const item = { id: 300 + state.postedReports.length, ...payload, created_by_user_id: 7, created_by_name: 'Max Beispiel', created_at: '2026-11-06 12:00:00' };
          state.reports[id].push(item);
          return json({ ok: true, data: item }, 201);
        }
        return json({ ok: true, data: state.reports[id] || [] });
      }
      if (request.method() === 'POST') {
        const payload = request.postDataJSON();
        state.postedMaterials.push({ projectId: id, payload });
        if (state.holdMaterialPost) await state.holdMaterialPost.promise;
        const item = { id: 200 + state.postedMaterials.length, project_id: id, ...payload, created_by_user_id: 7, created_by_name: 'Max Beispiel' };
        state.materials[id].push(item);
        return json({ ok: true, data: item }, 201);
      }
      const held = state.holdMaterial;
      if (held && held.projectId === id) {
        state.holdMaterial = null;
        held.started.resolve();
        await held.release.promise;
      }
      return json({ ok: true, data: state.materials[id] || [] });
    }
    if (pathname.startsWith('/api/')) return json({ message: 'Unexpected mocked endpoint: ' + pathname }, 404);
    return route.fulfill({ status: 204, body: '' });
  });
  state.holdNextMaterial = (projectId = 1) => {
    const held = { projectId, started: deferred(), release: deferred() };
    state.holdMaterial = held;
    return held;
  };
  await page.goto('/app/projektwahl?project=1');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await page.waitForLoadState('networkidle');
  return state;
}

async function refresh(page, state) {
  const before = state.dayRequests;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => state.dayRequests).toBeGreaterThan(before);
  await page.waitForLoadState('networkidle');
}

test('project refresh preserves the attached input, composition, selection and scroll anchor', async ({ page }) => {
  const state = await mockRefreshApp(page);
  const note = page.locator('#projectMaterialNote');
  await note.fill('Dieser Text wird mit der Handy-Tastatur diktiert.');
  const before = await note.evaluate((element) => {
    element.focus();
    element.setSelectionRange(7, 12);
    element.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true, data: 'diktiert' }));
    window.__originalMaterialNote = element;
    return { y: window.scrollY, top: element.getBoundingClientRect().top };
  });
  await refresh(page, state);
  const after = await note.evaluate((element) => ({
    sameNode: element === window.__originalMaterialNote,
    connected: window.__originalMaterialNote.isConnected,
    focused: document.activeElement === element,
    selection: [element.selectionStart, element.selectionEnd],
    y: window.scrollY, top: element.getBoundingClientRect().top
  }));
  expect(after).toMatchObject({ sameNode: true, connected: true, focused: true, selection: [7, 12] });
  expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(2);
  expect(Math.abs(after.top - before.top)).toBeLessThanOrEqual(2);
  await note.evaluate((element) => {
    element.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: 'fortgesetzt' }));
    element.setRangeText('fortgesetzt', element.selectionStart, element.selectionEnd, 'end');
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: 'fortgesetzt' }));
  });
  await refresh(page, state);
  await expect(note).toHaveValue(/fortgesetzt/);
});

test('a response started before typing keeps the live report node and new material rows keep its visual position', async ({ page }) => {
  const state = await mockRefreshApp(page);
  const held = state.holdNextMaterial();
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await held.started.promise;
  const report = page.locator('#projectWorkReportDescription');
  await report.fill('Diktat während einer bereits laufenden Aktualisierung.');
  const top = await report.evaluate((element) => {
    element.focus();
    element.setSelectionRange(5, 9);
    window.__originalReport = element;
    return element.getBoundingClientRect().top;
  });
  state.materials[1].unshift({ id: 99, project_id: 1, description: 'Zusätzlich vom Team erfasst', quantity: '2', work_date: '2026-11-06', created_by_user_id: 8 });
  held.release.resolve();
  await page.waitForLoadState('networkidle');
  expect(await report.evaluate((element) => element === window.__originalReport && document.activeElement === element)).toBeTruthy();
  expect(await report.evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([5, 9]);
  expect(Math.abs(await report.evaluate((element) => element.getBoundingClientRect().top) - top)).toBeLessThanOrEqual(2);
});

test('periodic refresh and online/offline transitions preserve the report and selected file nodes', async ({ page }) => {
  await page.clock.install();
  const state = await mockRefreshApp(page);
  const report = page.locator('#projectWorkReportDescription');
  await report.fill('Vor Ort geprüft.');
  await report.evaluate((element) => { window.__originalReport = element; element.focus(); });
  const requests = state.dayRequests;
  await page.clock.fastForward(60000);
  await page.clock.fastForward(200);
  await expect.poll(() => state.dayRequests).toBeGreaterThan(requests);
  await page.waitForLoadState('networkidle');
  expect(await report.evaluate((element) => element === window.__originalReport && document.activeElement === element)).toBeTruthy();
  await page.locator('#projectAttachmentInput').setInputFiles({ name: 'aufnahme.jpg', mimeType: 'image/jpeg', buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9]) });
  await page.locator('#projectAttachmentInput').evaluate((element) => { window.__originalFileInput = element; });
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await expect(page.locator('#projectAttachmentInput')).toBeDisabled();
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.waitForLoadState('networkidle');
  expect(await page.locator('#projectAttachmentInput').evaluate((element) => element === window.__originalFileInput && element.files.length === 1)).toBeTruthy();
  await expect(report).toHaveValue('Vor Ort geprüft.');
});

test('persistent material bindings submit once, lock inputs during the request and reset only on success', async ({ page }) => {
  const state = await mockRefreshApp(page);
  await refresh(page, state);
  await refresh(page, state);
  await page.locator('#projectMaterialDescription').fill('Neues Rohr');
  await page.locator('#projectMaterialNote').fill('Keller');
  await page.locator('#projectMaterialNote').evaluate((element) => { window.__originalMaterialNote = element; });
  state.holdMaterialPost = deferred();
  await page.locator('#projectMaterialForm button[type="submit"]').click();
  await expect.poll(() => state.postedMaterials.length).toBe(1);
  await expect(page.locator('#projectMaterialNote')).toHaveAttribute('readonly', '');
  state.holdMaterialPost.resolve();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  await expect(page.locator('#projectMaterialNote')).toHaveValue('');
  expect(state.postedMaterials).toHaveLength(1);
  expect(await page.locator('#projectMaterialNote').evaluate((element) => element === window.__originalMaterialNote)).toBeTruthy();
});

test('switching project replaces the old form and ignores a late response for the previous project', async ({ page }) => {
  const state = await mockRefreshApp(page);
  const held = state.holdNextMaterial();
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await held.started.promise;
  await page.locator('#projectWorkReportDescription').fill('Entwurf nur für Projekt Eins');
  await page.locator('#projectWorkReportDescription').evaluate((element) => { window.__projectOneReport = element; });
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportForm')).toHaveAttribute('data-project-id', '2');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  held.release.resolve();
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Material für Projekt Zwei', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => window.__projectOneReport.isConnected)).toBeFalsy();
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Entwurf nur für Projekt Eins');
});

test('session expiry replaces protected project inputs immediately during editing', async ({ page }) => {
  const state = await mockRefreshApp(page);
  await page.locator('#projectWorkReportDescription').fill('Nicht weiter sichtbar nach Sessionende');
  await page.locator('#projectWorkReportDescription').evaluate((element) => { window.__expiredReport = element; element.focus(); });
  state.expireSession = true;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('#loginForm')).toBeVisible();
  await expect(page.locator('#projectWorkReportDescription')).toHaveCount(0);
  expect(await page.evaluate(() => window.__expiredReport.isConnected)).toBeFalsy();
});

test('a partly visible resized report textarea keeps its size and visual anchor when rows above change', async ({ page }) => {
  const state = await mockRefreshApp(page);
  const report = page.locator('#projectWorkReportDescription');
  await report.fill(Array.from({ length: 20 }, (_, index) => 'Diktatzeile ' + index).join('\n'));
  const before = await report.evaluate((element) => {
    element.style.height = '700px';
    element.focus();
    element.setSelectionRange(7, 12);
    window.scrollTo(0, window.scrollY + element.getBoundingClientRect().top + 180);
    return { top: element.getBoundingClientRect().top, height: element.getBoundingClientRect().height };
  });
  expect(before.top).toBeLessThan(0);
  state.materials[1].unshift({ id: 99, project_id: 1, description: 'Neue Teamposition oberhalb des Berichts', quantity: '2', work_date: '2026-11-06', created_by_user_id: 8 });
  await refresh(page, state);
  const after = await report.evaluate((element) => ({ top: element.getBoundingClientRect().top, height: element.getBoundingClientRect().height, focused: document.activeElement === element }));
  expect(after.focused).toBeTruthy();
  expect(after.height).toBe(before.height);
  expect(Math.abs(after.top - before.top)).toBeLessThanOrEqual(2);
});

for (const theme of ['light', 'dark']) {
  for (const width of [390, 1180]) {
    test('project reports remain readable with busy and error feedback in ' + theme + ' at ' + width + 'px', async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 844 });
      await page.addInitScript((mode) => localStorage.setItem('app.theme', mode), theme);
      const state = await mockRefreshApp(page);
      state.reports[1].push({
        id: 90, project_id: 1, created_by_user_id: 8, created_by_name: 'Erika Beispiel',
        work_date: '2026-11-05', created_at: '2026-11-05 16:20:00',
        description: 'Heizungsleitung angeschlossen.\nDichtheitsprüfung erfolgreich. ' + 'LangeMaterialbeschreibung'.repeat(10)
      });
      await refresh(page, state);
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
      await expect(page.locator('#projectWorkReportList')).toContainText('Erika Beispiel');
      await expect(page.locator('[data-archive-project-work-report="90"]')).toHaveCount(0);
      const report = page.locator('#projectWorkReportDescription');
      await report.fill('Leitung geprüft.\nMaterial dokumentiert, Rückfrage im Büro.');
      state.holdReportPost = deferred();
      state.reportPostError = 'Bitte die Beschreibung prüfen. Ihr Entwurf bleibt erhalten.';
      await page.locator('#projectWorkReportForm button[type="submit"]').click();
      await expect.poll(() => state.postedReports.length).toBe(1);
      await expect(report).toHaveAttribute('readonly', '');
      await expect(page.locator('#projectWorkReportForm button[type="submit"]')).toHaveText('Wird gespeichert …');
      await expect(page.locator('#projectWorkReportForm button[type="submit"]')).toBeDisabled();
      state.holdReportPost.resolve();
      await expect(page.locator('.app-feedback-error')).toContainText(state.reportPostError);
      await expect(page.locator('.app-feedback-error')).toBeInViewport();
      expect(await page.locator('.app-feedback-error').evaluate((element) => getComputedStyle(element).backgroundColor)).not.toContain('rgba');
      const statusContrast = await page.locator('.app-topbar-status').evaluate((element, mode) => {
        const channels = (color) => color.match(/[\d.]+/g).map(Number);
        const foreground = channels(getComputedStyle(element.querySelector('.app-topbar-status__label')).color);
        const background = channels(getComputedStyle(element).backgroundColor);
        const base = mode === 'dark' ? [14, 24, 42] : [255, 255, 255];
        const flattened = background.slice(0, 3).map((channel, index) => channel * (background[3] ?? 1) + base[index] * (1 - (background[3] ?? 1)));
        const luminance = (rgb) => rgb.map((channel) => {
          const value = channel / 255;
          return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const values = [luminance(foreground), luminance(flattened)].sort((left, right) => right - left);
        return (values[0] + 0.05) / (values[1] + 0.05);
      }, theme);
      expect(statusContrast).toBeGreaterThanOrEqual(4.5);
      await expect(report).toBeEditable();
      await expect(report).toHaveValue('Leitung geprüft.\nMaterial dokumentiert, Rückfrage im Büro.');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
      await page.screenshot({ path: testInfo.outputPath('project-report-' + theme + '-' + width + '.png') });
      await page.locator('#projectSelect').selectOption('2');
      await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
      await page.locator('#projectSelect').selectOption('1');
      await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Leitung geprüft.\nMaterial dokumentiert, Rückfrage im Büro.');
    });
  }
}
