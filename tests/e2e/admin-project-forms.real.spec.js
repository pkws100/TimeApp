const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

test.describe('real admin project master form stability', () => {
  test.describe.configure({ timeout: 60000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  const projectId = () => process.env.UI_TEST_FORM_PROJECT_ID;
  const editPath = () => '/admin/projects/' + projectId() + '/edit';
  const state = () => JSON.parse(execFileSync('php', [path.join(__dirname, '../Support/project-form-ui.php')], { cwd: path.join(__dirname, '../..'), encoding: 'utf8' }));
  const longText = (prefix) => Array.from({ length: 55 }, (_, index) => prefix + ' Schritt ' + (index + 1) + ': Vor Ort pruefen, Ausfuehrung dokumentieren und Rueckfrage halten.').join('\n');

  async function openProject(page) {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await Promise.all([page.waitForURL('**/admin'), page.getByRole('button', { name: 'Anmelden', exact: true }).click()]);
    await page.goto(editPath());
    await expect(page.locator('[data-project-master-form]')).toHaveAttribute('data-project-guard-ready', '1');
  }

  async function saveMaster(page) {
    const response = page.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/projects/' + projectId());
    await page.locator('[data-project-master-form] button[type="submit"]').click();
    return response;
  }

  test('real project form retains a minute of editing, cancels a foreign submit and saves exactly once with editing position', async ({ page }) => {
    await page.addInitScript(() => {
      const nativeSet = Storage.prototype.setItem;
      Storage.prototype.setItem = function (key, value) {
        if (String(key).startsWith('timeapp:project-master-view:')) nativeSet.call(sessionStorage, '__uiProjectViewSaved', value);
        return nativeSet.call(this, key, value);
      };
      const nativeScroll = window.scrollTo;
      window.scrollTo = function (...args) {
        nativeSet.call(sessionStorage, '__uiProjectViewRestored', JSON.stringify(args));
        return nativeScroll.apply(window, args);
      };
    });
    await openProject(page);
    const text = longText('Minutenpruefung');
    const instructions = page.locator('#work_instructions');
    const file = page.locator('form[action$="/files"] input[type="file"]').first();
    await file.setInputFiles({ name: 'nicht-hochgeladen.txt', mimeType: 'text/plain', buffer: Buffer.from('synthetische Dateiauswahl') });
    await instructions.fill(text);
    await instructions.evaluate((element) => {
      window.__projectInstructionNode = element;
      element.focus(); element.setSelectionRange(140, 175, 'forward'); element.scrollTop = 95;
      element.dispatchEvent(new Event('keyup', { bubbles: true }));
    });
    const before = await instructions.evaluate((element) => ({ x: scrollX, y: scrollY, top: element.scrollTop, start: element.selectionStart, end: element.selectionEnd }));
    let navigations = 0;
    page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) navigations += 1; });
    await page.clock.install();
    await page.clock.runFor(61000);
    await page.evaluate(() => {
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new Event('focus'));
    });
    await expect(instructions).toHaveValue(text);
    expect(await instructions.evaluate((element) => element === window.__projectInstructionNode && document.activeElement === element)).toBeTruthy();
    expect(await instructions.evaluate((element) => ({ x: scrollX, y: scrollY, top: element.scrollTop, start: element.selectionStart, end: element.selectionEnd }))).toEqual(before);
    expect(navigations).toBe(0);
    expect(await file.evaluate((element) => element.files[0].name)).toBe('nicht-hochgeladen.txt');

    const membership = page.locator('form[action$="/memberships"]');
    let membershipPosts = 0;
    let masterPosts = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/memberships')) membershipPosts += 1;
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/admin/projects/' + projectId()) masterPosts += 1;
    });
    await membership.getByRole('button', { name: 'Projektfreigaben speichern', exact: true }).click();
    const guard = page.getByRole('dialog', { name: 'Ungespeicherte Projektstammdaten' });
    await expect(guard).toBeVisible();
    expect(membershipPosts).toBe(0);
    await guard.getByRole('button', { name: 'Weiter bearbeiten', exact: true }).click();
    await expect(instructions).toHaveValue(text);
    expect(await instructions.evaluate((element) => document.activeElement === element)).toBeTruthy();
    expect(await file.evaluate((element) => element.files.length)).toBe(1);

    await page.clock.resume();
    const saved = page.waitForURL('**/edit?notice=updated');
    await page.locator('[data-project-master-form]').evaluate((form) => { form.requestSubmit(); form.requestSubmit(); });
    await saved;
    await expect(instructions).toHaveValue(text);
    await expect(instructions).toBeFocused();
    expect(await instructions.evaluate((element) => ({ start: element.selectionStart, end: element.selectionEnd, top: element.scrollTop }))).toEqual({ start: before.start, end: before.end, top: before.top });
    const after = await page.evaluate(() => ({ y: scrollY, saved: sessionStorage.getItem('__uiProjectViewSaved'), restored: sessionStorage.getItem('__uiProjectViewRestored') }));
    expect(Math.abs(after.y - before.y), JSON.stringify({ before, after })).toBeLessThan(3);
    expect(masterPosts).toBe(1);
    expect(membershipPosts).toBe(0);
    expect(state().project.work_instructions).toBe(text);
    await page.reload();
    await expect(instructions).toHaveValue(text);
    expect(state().project.work_instructions).toBe(text);
  });

  test('server validation preserves every field and blocks other actions until a deliberate successful save', async ({ page }) => {
    await openProject(page);
    const original = state().project;
    const text = longText('Validierungsentwurf');
    const form = page.locator('[data-project-master-form]');
    const fields = {
      project_number: 'UI-FORM-COLLISION', name: 'UI Formular mit offener Validierung', customer_name: 'UI Neuer Kunde',
      customer_signature_name: 'UI Kundenkontakt', address_line_1: 'UI Teststrasse 23', postal_code: '12345', city: 'UI Testort',
      starts_on: '2027-01-04', ends_on: '2027-01-08', work_instructions: text
    };
    for (const [name, value] of Object.entries(fields)) await form.locator('[name="' + name + '"]').fill(value);
    await form.locator('[name="status"]').selectOption('paused');
    await form.locator('[name="customer_signature_required"]').check();
    const node = await form.locator('#work_instructions').evaluate((element) => { window.__validationTextarea = element; return true; });
    expect(node).toBeTruthy();
    const instructions = form.locator('#work_instructions');
    const beforeMinute = await instructions.evaluate((element) => {
      element.focus(); element.setSelectionRange(140, 175, 'forward'); element.scrollTop = 95;
      element.dispatchEvent(new Event('keyup', { bubbles: true }));
      return { x: scrollX, y: scrollY, top: element.scrollTop, start: element.selectionStart, end: element.selectionEnd };
    });
    let minuteNavigations = 0;
    page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) minuteNavigations += 1; });
    await page.clock.install();
    await page.clock.runFor(61000);
    await page.evaluate(() => {
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new Event('focus'));
    });
    await expect(instructions).toHaveValue(text);
    await expect(instructions).toBeFocused();
    expect(await instructions.evaluate((element) => element === window.__validationTextarea)).toBeTruthy();
    expect(await instructions.evaluate((element) => ({ x: scrollX, y: scrollY, top: element.scrollTop,
      start: element.selectionStart, end: element.selectionEnd }))).toEqual(beforeMinute);
    expect(minuteNavigations).toBe(0);
    await page.clock.resume();
    const response = await saveMaster(page);
    expect(response.status()).toBe(422);
    await expect(form.locator('#project_number')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#field-error-project_number')).toContainText('bereits vergeben');
    await expect(form.locator('#project_number')).toBeFocused();
    for (const [name, value] of Object.entries(fields)) await expect(form.locator('[name="' + name + '"]')).toHaveValue(value);
    await expect(form.locator('[name="status"]')).toHaveValue('paused');
    await expect(form.locator('[name="customer_signature_required"]')).toBeChecked();
    expect(await form.locator('#work_instructions').evaluate((element) => element === window.__validationTextarea)).toBeTruthy();
    expect(state().project.work_instructions).toBe(original.work_instructions);
    await page.getByRole('link', { name: 'Zur Liste', exact: true }).click();
    const guard = page.getByRole('dialog', { name: 'Ungespeicherte Projektstammdaten' });
    await expect(guard).toBeVisible();
    await guard.getByRole('button', { name: 'Speichern und hier bleiben', exact: true }).click();
    await expect(page.locator('#field-error-project_number')).toContainText('bereits vergeben');
    await expect(page).toHaveURL(new RegExp('/admin/projects/' + projectId() + '/edit$'));
    await form.locator('#project_number').fill(original.project_number);
    await Promise.all([page.waitForURL('**/edit?notice=updated'), saveMaster(page)]);
    expect(state().project.work_instructions).toBe(text);
    await page.reload();
    await expect(form.locator('#work_instructions')).toHaveValue(text);
    await expect(form.locator('#customer_name')).toHaveValue(fields.customer_name);
  });

  test('discard is explicit, foreign submits execute once, and material archive cannot drop unsaved instructions', async ({ page }) => {
    await openProject(page);
    const original = state().project.work_instructions;
    await page.locator('#work_instructions').fill('Nicht speichern: bewusste Verwerfung');
    const membership = page.locator('form[action$="/memberships"]');
    await membership.locator('[name="user_ids[]"][value="' + process.env.UI_TEST_FORM_OTHER_USER_ID + '"]').check();
    let membershipPosts = 0;
    page.on('request', (request) => { if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/memberships')) membershipPosts += 1; });
    await membership.getByRole('button', { name: 'Projektfreigaben speichern', exact: true }).click();
    const guard = page.getByRole('dialog', { name: 'Ungespeicherte Projektstammdaten' });
    await expect(guard).toBeVisible();
    let releaseSavedView;
    const pendingRead = '**/admin/projects/' + projectId() + '/edit';
    await page.route(pendingRead, async (route) => {
      await new Promise((resolve) => { releaseSavedView = resolve; });
      try { await route.continue(); } catch (error) { /* The explicit cancel aborts this synthetic request. */ }
    });
    await guard.getByRole('button', { name: 'Aenderungen verwerfen und fortfahren', exact: true }).click();
    await expect.poll(() => typeof releaseSavedView).toBe('function');
    await expect(guard.getByRole('button', { name: 'Weiter bearbeiten', exact: true })).toBeEnabled();
    await guard.getByRole('button', { name: 'Weiter bearbeiten', exact: true }).click();
    releaseSavedView();
    await page.unroute(pendingRead);
    await expect(guard).toBeHidden();
    await expect(page.locator('[data-project-master-form]')).not.toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('#work_instructions')).toHaveValue('Nicht speichern: bewusste Verwerfung');
    expect(membershipPosts).toBe(0);
    await membership.getByRole('button', { name: 'Projektfreigaben speichern', exact: true }).click();
    await expect(guard).toBeVisible();
    await Promise.all([
      page.waitForURL('**/edit?notice=memberships-updated'),
      guard.getByRole('button', { name: 'Aenderungen verwerfen und fortfahren', exact: true }).click()
    ]);
    expect(membershipPosts).toBe(1);
    expect(state().project.work_instructions).toBe(original);
    expect(state().memberships.map((row) => String(row.user_id))).toContain(process.env.UI_TEST_FORM_OTHER_USER_ID);
    await expect(page.locator('#work_instructions')).toHaveValue(original);

    await page.locator('#work_instructions').fill('Nicht verwerfen: Archivierung abbrechen');
    const archive = page.locator('form[action="/admin/project-materials/' + process.env.UI_TEST_FORM_MATERIAL_ID + '/archive"]');
    let archivePosts = 0;
    page.on('request', (request) => { if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/archive')) archivePosts += 1; });
    await archive.getByRole('button', { name: 'Archivieren', exact: true }).click();
    await expect(guard).toBeVisible();
    await page.keyboard.press('Escape');
    expect(archivePosts).toBe(0);
    await expect(page.locator('#work_instructions')).toHaveValue('Nicht verwerfen: Archivierung abbrechen');
    expect(state().materials[0].is_deleted).toBe(0);
  });

  test('an expired real session and failed transport retain the editable master values without automatic retries', async ({ page }) => {
    await openProject(page);
    const original = state().project.work_instructions;
    const text = longText('Sitzungsablauf');
    await page.locator('#work_instructions').fill(text);
    await page.request.post('/api/v1/auth/logout');
    await saveMaster(page);
    await expect(page.locator('[data-project-master-errors]')).toContainText('Sitzung oder Sicherheitspruefung ist abgelaufen');
    await expect(page.locator('#work_instructions')).toHaveValue(text);
    expect(state().project.work_instructions).toBe(original);
    const login = await page.context().newPage();
    await login.goto('/admin/login');
    await login.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await login.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await Promise.all([login.waitForURL('**/admin'), login.getByRole('button', { name: 'Anmelden', exact: true }).click()]);
    await login.close();
    const csrfResponse = await saveMaster(page);
    expect(csrfResponse.status()).toBe(422);
    await expect(page.locator('#work_instructions')).toHaveValue(text);
    await expect(page.locator('[data-project-master-errors]')).toContainText('Sicherheitspruefung');

    let attempts = 0;
    const pattern = '**/admin/projects/' + projectId();
    await page.route(pattern, async (route) => {
      if (route.request().method() === 'POST') { attempts += 1; await route.abort('internetdisconnected'); }
      else { await route.continue(); }
    });
    await page.locator('[data-project-master-form] button[type="submit"]').click();
    await expect(page.locator('[data-project-master-errors]')).toContainText('Speichern konnte nicht bestaetigt werden');
    expect(attempts).toBe(1);
    await expect(page.locator('#work_instructions')).toHaveValue(text);
    expect(state().project.work_instructions).toBe(original);
    await page.unroute(pattern);
    await Promise.all([page.waitForURL('**/edit?notice=updated'), saveMaster(page)]);
    expect(state().project.work_instructions).toBe(text);
  });

  test('native project creation submits once and the next explicit save updates that created project', async ({ page }) => {
    await openProject(page);
    await page.goto('/admin/projects/create');
    const form = page.locator('[data-project-master-form]');
    await expect(form).toHaveAttribute('data-project-guard-ready', '1');
    await form.locator('#project_number').fill('UI-FORM-CREATE-RACE');
    await form.locator('#name').fill('UI Native Formularanlage');
    await form.locator('#work_instructions').fill('Erste bewusste Anlage');
    const createPosts = [];
    page.on('request', (request) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/admin/projects') createPosts.push(request.isNavigationRequest());
    });
    await Promise.all([
      page.waitForURL(/\/admin\/projects\/\d+\/edit\?notice=created$/),
      form.evaluate((element) => { element.requestSubmit(); element.requestSubmit(); })
    ]);
    expect(createPosts).toEqual([true]);
    const createdId = new URL(page.url()).pathname.split('/')[3];
    await expect(form).toHaveAttribute('action', '/admin/projects/' + createdId);
    await expect(form.locator('[name="_method"]')).toHaveValue('PUT');
    await form.locator('#project_number').fill('UI-FORM-CREATE-UPDATED');
    await form.locator('#work_instructions').fill('Nachgereichte Aenderung am angelegten Projekt');
    await Promise.all([page.waitForURL('**/edit?notice=updated'), form.locator('button[type="submit"]').click()]);
    expect(createPosts).toHaveLength(1);
    await page.reload();
    await expect(form.locator('#project_number')).toHaveValue('UI-FORM-CREATE-UPDATED');
    await expect(form.locator('#work_instructions')).toHaveValue('Nachgereichte Aenderung am angelegten Projekt');
  });

  test('a real mobile material draft survives reload and one explicit save creates one API and database entry', async ({ page }) => {
    await openProject(page);
    await page.setViewportSize({ width: 390, height: 844 });
    const sessionResponse = await page.request.get('/api/v1/auth/session');
    expect(sessionResponse.ok()).toBeTruthy();
    const session = await sessionResponse.json();
    expect(session.authenticated).toBe(true);
    expect(Number(session.user.id)).toBeGreaterThan(0);
    const materialPath = '/api/v1/app/projects/' + projectId() + '/materials';
    const draftKey = 'project_material_draft_v1_user_' + session.user.id + '_project_' + projectId();
    const before = state().materials;
    const posts = [];
    page.on('request', (request) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === materialPath) posts.push(request.postDataJSON());
    });
    const readDraft = () => page.evaluate((key) => new Promise((resolve, reject) => {
      const opened = indexedDB.open('zeiterfassung-app');
      opened.onerror = () => reject(opened.error);
      opened.onsuccess = () => {
        const database = opened.result;
        const request = database.transaction('cache', 'readonly').objectStore('cache').get(key);
        request.onerror = () => { database.close(); reject(request.error); };
        request.onsuccess = () => { database.close(); resolve(request.result || null); };
      };
    }), draftKey);

    await page.goto('/app/projektwahl?project=' + projectId());
    const form = page.locator('#projectMaterialForm');
    await expect(form).toHaveAttribute('data-project-id', projectId());
    await expect(page.locator('#projectMaterialDescription')).toBeEditable();
    await page.waitForLoadState('networkidle');
    const workDate = await page.locator('#projectMaterialDate').getAttribute('max');
    expect(workDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    const fields = {
      description: 'UI E2E Materialentwurf nach echtem Reload', quantity: '2,5', unit: 'm',
      note: 'Im mobilen Echtablauf dokumentiert.\nEntwurf vor dem Speichern pruefen.', work_date: workDate
    };
    expect(before.filter((entry) => entry.description === fields.description)).toHaveLength(0);
    for (const [name, value] of Object.entries(fields)) await form.locator('[name="' + name + '"]').fill(value);
    const note = page.locator('#projectMaterialNote');
    await note.evaluate((element) => { element.focus(); element.setSelectionRange(8, 18); });
    await expect(note).toBeFocused();
    expect(await note.evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([8, 18]);
    await expect.poll(async () => { const record = await readDraft(); return record ? record.value : null; }).toMatchObject({ ...fields, dirty: true });
    expect(posts).toHaveLength(0);
    expect(state().materials).toEqual(before);

    await page.reload();
    await expect(page.locator('#projectMaterialDescription')).toBeEditable();
    for (const [name, value] of Object.entries(fields)) await expect(form.locator('[name="' + name + '"]')).toHaveValue(value);
    await page.waitForLoadState('networkidle');
    expect(posts).toHaveLength(0);
    expect(state().materials).toEqual(before);
    const savedResponse = page.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname === materialPath);
    await form.evaluate((element) => { element.requestSubmit(); element.requestSubmit(); });
    const response = await savedResponse;
    expect(response.status()).toBe(201);
    const saved = await response.json();
    expect(saved.ok).toBe(true);
    expect(saved.replayed).toBe(false);
    expect(posts).toHaveLength(1);
    expect(posts[0]).toMatchObject(fields);
    expect(posts[0].client_request_id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
    await expect.poll(readDraft).toBeNull();
    await expect(form.getByRole('button', { name: 'Material speichern', exact: true })).toBeEnabled();
    const after = state().materials;
    expect(after).toHaveLength(before.length + 1);
    const newRows = after.filter((entry) => !before.some((previous) => Number(previous.id) === Number(entry.id)));
    expect(newRows).toHaveLength(1);
    expect(newRows[0]).toMatchObject({ description: fields.description, is_deleted: 0 });
    expect(Number(newRows[0].id)).toBe(saved.data.id);
    const listResponse = await page.request.get(materialPath);
    expect(listResponse.ok()).toBeTruthy();
    const listed = (await listResponse.json()).data;
    const matching = listed.filter((entry) => entry.description === fields.description);
    expect(matching).toHaveLength(1);
    expect(matching[0]).toMatchObject({ id: saved.data.id, description: fields.description, unit: fields.unit,
      note: fields.note, work_date: workDate, created_by_user_id: Number(session.user.id), is_deleted: false });
    expect(Number(matching[0].quantity)).toBe(2.5);

    await page.reload();
    await expect(page.locator('#projectMaterialDescription')).toBeEditable();
    await expect(page.locator('#projectMaterialDescription')).toHaveValue('');
    await expect(page.locator('#projectMaterialQuantity')).toHaveValue('1');
    await expect(page.locator('#projectMaterialUnit')).toHaveValue('');
    await expect(page.locator('#projectMaterialNote')).toHaveValue('');
    const currentDefaultDate = await page.locator('#projectMaterialDate').getAttribute('max');
    await expect(page.locator('#projectMaterialDate')).toHaveValue(currentDefaultDate);
    await page.waitForLoadState('networkidle');
    expect(await readDraft()).toBeNull();
    expect(posts).toHaveLength(1);
    expect(state().materials).toEqual(after);
    const finalListResponse = await page.request.get(materialPath);
    expect(finalListResponse.ok()).toBeTruthy();
    expect((await finalListResponse.json()).data).toEqual(listed);
  });
});
