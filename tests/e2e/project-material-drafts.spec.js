const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

const projectRoot = path.join(__dirname, '../..');
const appShell = execFileSync('php', ['-r',
  'function base_path(string $path): string { return getcwd() . "/" . $path; } '
  + 'require "src/Presentation/App/AppView.php"; '
  + 'echo (new \\App\\Presentation\\App\\AppView("Materialentwurf-Test"))->render("/app/projektwahl", ["app_name" => "Materialentwurf-Test"]);'
], { cwd: projectRoot, encoding: 'utf8' });

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

async function materialApp(page) {
  const state = {
    userId: 7, authenticated: true, dayReads: 0, dayError: null,
    projects: [1, 2].map((id) => ({ id, project_number: 'P-00' + id, name: 'Baustelle ' + id,
      work_instructions: Array.from({ length: 12 }, (_, line) => 'Arbeitsschritt ' + (line + 1)).join('\n') })),
    materials: { 1: [], 2: [] }, posts: [], entries: new Map(), nextPost: null,
    holdPost: null, holdGet: null, materialError: null
  };
  const flags = { show_project_files: true, show_project_work_instructions: true, show_project_materials: true };
  const user = () => ({ id: state.userId, display_name: state.userId === 7 ? 'Max Beispiel' : 'Erika Beispiel',
    email: state.userId === 7 ? 'max@example.test' : 'erika@example.test',
    permissions: ['timesheets.create', 'timesheets.view_own', 'files.view', 'files.upload'] });
  await page.route('**/*', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') {
      const body = pathname.endsWith('.js') && process.env.PWA_MATERIAL_BASELINE === '1'
        ? execFileSync('git', ['show', '9774a446ffb247d27eb5a40c7d6d9e73d1a7942b:public/assets/js/app.js'], { cwd: projectRoot })
        : fs.readFileSync(path.join(projectRoot, 'public', pathname));
      return route.fulfill({ contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body });
    }
    if (pathname === '/app' || pathname.startsWith('/app/')) return route.fulfill({ contentType: 'text/html', body: appShell });
    if (pathname === '/api/v1/auth/session') return json({ authenticated: state.authenticated, bootstrap_required: false, user: state.authenticated ? user() : null });
    if (pathname === '/api/v1/auth/login') {
      state.authenticated = true;
      return json({ ok: true, session: { authenticated: true, user: user() } });
    }
    if (pathname === '/api/v1/auth/logout') { state.authenticated = false; return json({ ok: true }); }
    if (pathname === '/api/v1/app/me/day') {
      state.dayReads += 1;
      if (state.dayError) return json({ message: state.dayError.message }, state.dayError.status);
      return json({ data: { today: '2026-11-06', server_time: '2026-11-06T12:00:00+01:00',
        user: { ...user(), roles: [], app_ui_settings: flags }, app_ui_settings: flags,
        projects: state.projects, attachments: [], project_day_summaries: [],
        today_state: { status: 'not_started', is_missing: false, work_entry: null, status_entry: null, current_break: null },
        current_break: null, breaks_today: [], company: {}, geo_policy: {}, personnel_events: [], personnel_labels: [] } });
    }
    if (pathname === '/api/v1/settings/company') return json({ data: { company_name: 'Material-Test' } });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, can_subscribe: false, devices: [] } });
    const match = pathname.match(/^\/api\/v1\/app\/projects\/(\d+)\/(files|materials|work-reports)$/);
    if (match) {
      const projectId = Number(match[1]);
      if (match[2] !== 'materials') return json({ ok: true, data: [] });
      if (request.method() !== 'POST') {
        if (state.holdGet && state.holdGet.projectId === projectId) {
          const held = state.holdGet;
          state.holdGet = null;
          held.started.resolve();
          await held.release.promise;
        }
        if (state.materialError) return json({ message: state.materialError.message }, state.materialError.status);
        return json({ ok: true, data: state.materials[projectId] || [] });
      }
      const payload = request.postDataJSON();
      const owner = state.userId;
      state.posts.push({ projectId, owner, payload });
      if (state.holdPost) {
        const held = state.holdPost;
        state.holdPost = null;
        held.started.resolve();
        await held.release.promise;
      }
      const behavior = state.nextPost;
      state.nextPost = null;
      if (behavior && typeof behavior === 'object') return json({ message: behavior.message }, behavior.status);
      const key = owner + ':' + payload.client_request_id;
      const replayed = state.entries.has(key);
      if (!replayed) {
        const entry = { ...payload, id: 100 + state.entries.size, project_id: projectId,
          created_by_user_id: owner, created_by_name: user().display_name, is_deleted: false };
        state.entries.set(key, entry);
        state.materials[projectId].push(entry);
      }
      if (behavior === 'lost') return route.abort('failed');
      return json({ ok: true, replayed, data: state.entries.get(key) }, replayed ? 200 : 201);
    }
    if (pathname.startsWith('/api/')) return json({ message: 'Unexpected mocked endpoint: ' + pathname }, 501);
    return route.fulfill({ status: 204, body: '' });
  });
  await page.goto('/app/projektwahl?project=1');
  await expect(page.locator('#projectMaterialDescription')).toBeEditable();
  await page.waitForLoadState('networkidle');
  return state;
}

async function drafts(page, userId = 7) {
  return page.evaluate((id) => new Promise((resolve, reject) => {
    const opened = indexedDB.open('zeiterfassung-app');
    opened.onerror = () => reject(opened.error);
    opened.onsuccess = () => {
      const database = opened.result;
      const request = database.transaction('cache', 'readonly').objectStore('cache').getAll();
      request.onerror = () => { database.close(); reject(request.error); };
      request.onsuccess = () => {
        const records = request.result.filter((item) => String(item.key).startsWith('project_material_draft_v1_user_' + id + '_project_'));
        database.close(); resolve(records);
      };
    };
  }), userId);
}

async function waitDraft(page, text, userId = 7) {
  await expect.poll(async () => JSON.stringify(await drafts(page, userId)).includes(text)).toBeTruthy();
}

async function fillMaterial(page, description = 'Kupferrohr 28 mm', note = 'Im Keller vor der Montage prüfen.') {
  await page.locator('#projectMaterialDescription').fill(description);
  await page.locator('#projectMaterialQuantity').fill('2,5');
  await page.locator('#projectMaterialUnit').fill('m');
  await page.locator('#projectMaterialNote').fill(note);
  await page.locator('#projectMaterialDate').fill('2026-11-05');
}

async function refresh(page, state) {
  const before = state.dayReads;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => state.dayReads).toBeGreaterThan(before);
  await page.waitForLoadState('networkidle');
}

async function login(page, state, userId = 7) {
  state.userId = userId;
  state.dayError = null;
  await page.locator('#loginForm [name="email"]').fill(userId === 7 ? 'max@example.test' : 'erika@example.test');
  await page.locator('#loginForm [name="password"]').fill('synthetic-password');
  await page.locator('#loginForm button').click();
  await expect(page).toHaveURL(/\/app\/heute$/);
  await page.goto('/app/projektwahl?project=1');
  await expect(page.locator('#projectMaterialDescription')).toBeVisible();
}

test('a material draft survives a real page reload without automatically submitting', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page);
  await page.reload();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Kupferrohr 28 mm');
  await expect(page.locator('#projectMaterialQuantity')).toHaveValue('2,5');
  await expect(page.locator('#projectMaterialUnit')).toHaveValue('m');
  await expect(page.locator('#projectMaterialNote')).toHaveValue('Im Keller vor der Montage prüfen.');
  await expect(page.locator('#projectMaterialDate')).toHaveValue('2026-11-05');
  expect(state.posts).toHaveLength(0);
});

test('a minute refresh with new team material preserves live input nodes, selection, files and visual position then one explicit save clears the draft', async ({ page }) => {
  await page.clock.install();
  const state = await materialApp(page);
  expect(await drafts(page)).toEqual([]);
  await fillMaterial(page);
  await waitDraft(page, 'Im Keller vor der Montage prüfen.');
  await page.locator('#projectAttachmentInput').setInputFiles({ name: 'aufmass.txt', mimeType: 'text/plain', buffer: Buffer.from('Synthetische Aufmaßdatei') });
  const before = await page.locator('#projectMaterialNote').evaluate((element) => {
    element.focus(); element.setSelectionRange(4, 10);
    window.materialNote = element;
    window.materialForm = document.getElementById('projectMaterialForm');
    window.materialFile = document.getElementById('projectAttachmentInput');
    return { top: element.getBoundingClientRect().top, y: window.scrollY };
  });
  state.materials[1].unshift({ id: 99, project_id: 1, description: 'Vom Kollegen ergänzt', quantity: '4', unit: 'm', note: 'Neue Position', work_date: '2026-11-06', created_by_user_id: 8 });
  const reads = state.dayReads;
  await page.clock.fastForward(60000);
  await page.clock.fastForward(200);
  await expect.poll(() => state.dayReads).toBeGreaterThan(reads);
  await expect(page.getByText('Vom Kollegen ergänzt', { exact: true })).toBeVisible();
  const after = await page.locator('#projectMaterialNote').evaluate((element) => ({
    same: element === window.materialNote, form: window.materialForm === document.getElementById('projectMaterialForm'),
    focused: document.activeElement === element, selection: [element.selectionStart, element.selectionEnd],
    files: window.materialFile === document.getElementById('projectAttachmentInput') && window.materialFile.files.length,
    top: element.getBoundingClientRect().top
  }));
  expect(after).toMatchObject({ same: true, form: true, focused: true, selection: [4, 10], files: 1 });
  expect(Math.abs(after.top - before.top)).toBeLessThanOrEqual(1);
  await page.reload();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Kupferrohr 28 mm');
  const held = { started: deferred(), release: deferred() };
  state.holdPost = held;
  await page.locator('#projectMaterialForm').evaluate((form) => { form.requestSubmit(); form.requestSubmit(); });
  await held.started.promise;
  expect(state.posts).toHaveLength(1);
  held.release.resolve();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
  expect(state.entries.size).toBe(1);
  await page.reload();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  expect(state.posts).toHaveLength(1);
});

test('a lost save response keeps a frozen submission across reload and explicit retry creates one server entry', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Verlorene Speicherantwort');
  state.nextPost = 'lost';
  await page.locator('#projectMaterialForm button').click();
  await expect(page.getByRole('button', { name: 'Speichern erneut prüfen', exact: true })).toBeVisible();
  await expect(page.locator('#projectMaterialDescription')).not.toBeEditable();
  await waitDraft(page, 'uncertain');
  const first = state.posts[0].payload;
  expect(first.client_request_id).toMatch(/^[0-9a-f-]{36}$/i);
  await page.reload();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Verlorene Speicherantwort');
  await expect(page.locator('#projectMaterialDescription')).not.toBeEditable();
  await page.getByRole('button', { name: 'Speichern erneut prüfen', exact: true }).click();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  expect(state.posts).toHaveLength(2);
  expect(state.posts[1].payload).toEqual(first);
  expect(state.entries.size).toBe(1);
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
});

test('definitive validation failure retains editable input and the same request key for the correction', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page);
  state.nextPost = { status: 422, message: 'Bitte die Materialangaben prüfen.' };
  await page.locator('#projectMaterialForm button').click();
  await expect(page.locator('#projectMaterialDescription')).toBeEditable();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Kupferrohr 28 mm');
  await waitDraft(page, 'rejected');
  const firstId = state.posts[0].payload.client_request_id;
  await page.locator('#projectMaterialNote').fill('Korrigierter Nachweis');
  await page.locator('#projectMaterialForm button').click();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  expect(state.posts[1].payload.client_request_id).toBe(firstId);
  expect(state.entries.size).toBe(1);
});

test('a delayed save only clears its own project draft after the employee selects another project', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Jetzt Projekt Eins speichern');
  const held = { started: deferred(), release: deferred() };
  state.holdPost = held;
  await page.locator('#projectMaterialForm button').click();
  await held.started.promise;
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectMaterialDescription')).toBeEditable();
  await fillMaterial(page, 'Projekt Zwei bleibt ungespeichert');
  await waitDraft(page, 'Projekt Zwei bleibt ungespeichert');
  held.release.resolve();
  await expect(page.locator('#projectMaterialForm button')).toBeEnabled();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Projekt Zwei bleibt ungespeichert');
  await expect.poll(async () => (await drafts(page)).length).toBe(1);
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Projekt Zwei bleibt ungespeichert');
  expect(state.posts).toHaveLength(1);
});

test('a failed local cleanup after server success retains the original retry identity and never creates a second row', async ({ page }) => {
  await page.addInitScript(() => {
    const original = IDBObjectStore.prototype.delete;
    IDBObjectStore.prototype.delete = function (key) {
      if (this.name === 'cache' && String(key).includes('project_material_draft') && window.failMaterialCleanup) {
        window.failMaterialCleanup = false;
        throw new DOMException('Local cleanup unavailable', 'UnknownError');
      }
      return original.call(this, key);
    };
  });
  const state = await materialApp(page);
  await fillMaterial(page, 'Eintrag bereits angenommen');
  await page.evaluate(() => { window.failMaterialCleanup = true; });
  await page.locator('#projectMaterialForm button').click();
  await expect(page.locator('#projectMaterialDraftStatus [role="alert"]')).toContainText('nicht bereinigt');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Eintrag bereits angenommen');
  const first = state.posts[0].payload;
  await page.getByRole('button', { name: 'Speichern erneut prüfen', exact: true }).click();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  expect(state.entries.size).toBe(1);
  expect(state.posts[1].payload).toEqual(first);
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
});

test('offline and project changes keep separate durable drafts and a late old material response cannot replace another form', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Nur Projekt Eins');
  await waitDraft(page, 'Nur Projekt Eins');
  const held = { projectId: 1, started: deferred(), release: deferred() };
  state.holdGet = held;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await held.started.promise;
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectMaterialForm')).toHaveAttribute('data-project-id', '2');
  await expect(page.locator('#projectMaterialDescription')).toBeEditable();
  await fillMaterial(page, 'Nur Projekt Zwei');
  held.release.resolve();
  await waitDraft(page, 'Nur Projekt Zwei');
  await page.evaluate(() => { Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false }); window.dispatchEvent(new Event('offline')); });
  await page.locator('#projectMaterialForm button').click();
  expect(state.posts).toHaveLength(0);
  await page.evaluate(() => { Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => true }); window.dispatchEvent(new Event('online')); });
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Nur Projekt Zwei');
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Nur Projekt Eins');
  await page.reload();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Nur Projekt Eins');
  expect(state.posts).toHaveLength(0);
});

test('session expiry restores the same employee draft while another employee never sees it', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Vertraulich nur Mitarbeiter Sieben');
  await waitDraft(page, 'Vertraulich nur Mitarbeiter Sieben');
  state.dayError = { status: 401, message: 'Sitzung abgelaufen' };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('#loginForm')).toBeVisible();
  await login(page, state, 8);
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  await expect(page.locator('main')).not.toContainText('Vertraulich nur Mitarbeiter Sieben');
  state.dayError = { status: 401, message: 'Sitzung abgelaufen' };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('#loginForm')).toBeVisible();
  await login(page, state, 7);
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Vertraulich nur Mitarbeiter Sieben');
  expect(state.posts).toHaveLength(0);
});

test('confirmed revocation removes the project draft while a temporary refresh failure retains it', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Nur solange berechtigt');
  await waitDraft(page, 'Nur solange berechtigt');
  state.dayError = { status: 503, message: 'Vorübergehend nicht erreichbar' };
  await refresh(page, state);
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Nur solange berechtigt');
  state.dayError = null;
  state.projects = state.projects.filter((project) => project.id !== 1);
  await refresh(page, state);
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
  await page.reload();
  await expect(page.locator('main')).not.toContainText('Nur solange berechtigt');
});

test('a confirmed material access denial removes the durable draft before a later failed refresh', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Zugriff auf Material entzogen');
  await waitDraft(page, 'Zugriff auf Material entzogen');
  state.materialError = { status: 403, message: 'Projekt nicht freigegeben' };
  await refresh(page, state);
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
  state.dayError = { status: 503, message: 'Vorübergehend nicht erreichbar' };
  await refresh(page, state);
  await page.reload();
  await expect(page.locator('main')).not.toContainText('Zugriff auf Material entzogen');
});

test('logout purges the current employee material drafts and late old responses cannot recreate them', async ({ page }) => {
  const state = await materialApp(page);
  await fillMaterial(page, 'Vor Abmeldung entfernen');
  await waitDraft(page, 'Vor Abmeldung entfernen');
  const held = { projectId: 1, started: deferred(), release: deferred() };
  state.holdGet = held;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await held.started.promise;
  await page.locator('#appMenuToggle').click();
  await page.locator('#logoutButton').click();
  await expect(page.locator('#loginForm')).toBeVisible();
  held.release.resolve();
  await expect.poll(async () => (await drafts(page)).length).toBe(0);
  await login(page, state, 8);
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
  await page.reload();
  await expect(page.locator('main')).not.toContainText('Vor Abmeldung entfernen');
});

test('failed local draft storage warns without exposing the error or replacing focused input', async ({ page }) => {
  const messages = [];
  page.on('console', (message) => messages.push(message.text()));
  await page.addInitScript(() => {
    const original = IDBObjectStore.prototype.put;
    IDBObjectStore.prototype.put = function (record, ...rest) {
      if (this.name === 'cache' && String(record.key).includes('project_material_draft')) {
        throw new DOMException('private-storage-sentinel', 'QuotaExceededError');
      }
      return original.call(this, record, ...rest);
    };
  });
  const state = await materialApp(page);
  await page.locator('#projectMaterialDescription').fill('Bleibt trotz Speicherfehler');
  await page.locator('#projectMaterialDescription').evaluate((element) => { window.originalStorageField = element; element.focus(); element.setSelectionRange(4, 8); });
  await expect(page.locator('#projectMaterialDraftStatus [role="alert"]')).toContainText('nicht gesichert');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Bleibt trotz Speicherfehler');
  expect(await page.locator('#projectMaterialDescription').evaluate((element) => element === window.originalStorageField && document.activeElement === element && element.selectionStart === 4 && element.selectionEnd === 8)).toBeTruthy();
  expect(messages.join('\n')).not.toContain('private-storage-sentinel');
  expect(state.posts).toHaveLength(0);
});
