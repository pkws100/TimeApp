const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

test.describe('real mobile project view and material capture', () => {
  test.describe.configure({ timeout: 60000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  const snapshot = () => JSON.parse(execFileSync('php', [path.join(__dirname, '../Support/project-material-context-ui.php')], { encoding: 'utf8' }));
  const cacheValue = (page, key) => page.evaluate((entryKey) => new Promise((resolve, reject) => {
    const open = indexedDB.open('zeiterfassung-app');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const db = open.result;
      const request = db.transaction('cache').objectStore('cache').get(entryKey);
      request.onerror = () => { db.close(); reject(request.error); };
      request.onsuccess = () => { db.close(); resolve(request.result?.value ?? null); };
    };
  }), key);

  test('check in on A, keep the draft on B across a refresh and reload, and save with unchanged time records', async ({ page, browser, baseURL }) => {
    const workProject = process.env.UI_TEST_MATERIAL_PROJECT_A;
    const materialProject = process.env.UI_TEST_MATERIAL_PROJECT_B;
    const materialPath = '/api/v1/app/projects/' + materialProject + '/materials';
    const accountData = async () => (await (await page.request.get('/api/v1/app/time-account/summary')).json()).data;

    await page.clock.install();
    await page.goto('/app/heute');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_MATERIAL_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await page.locator('#projectSelect').selectOption(workProject);
    const syncPath = '/api/v1/app/timesheets/sync';
    const [checkIn] = await Promise.all([
      page.waitForResponse((response) => new URL(response.url()).pathname === syncPath && response.request().postDataJSON().action === 'check_in'),
      page.locator('[data-action="check_in"]').click(),
    ]);
    expect(checkIn.ok()).toBeTruthy();
    await expect(page.locator('[data-action="check_out"]')).toBeVisible();
    const before = snapshot();
    expect(before.materials).toHaveLength(0);
    expect(new Set(before.time.timesheets.map((entry) => entry.id)).size).toBe(1);
    const runningEntry = before.time.timesheets.at(-1);
    expect(runningEntry).toMatchObject({ project_id: Number(workProject), end_time: null, entry_type: 'work' });
    const balanceBefore = await accountData();
    expect(balanceBefore).toBeTruthy();

    await page.locator('#appMenuToggle').click();
    await page.getByRole('link', { name: 'Projekt', exact: true }).filter({ visible: true }).first().click();
    await page.locator('#projectSelect').selectOption(materialProject);
    const form = page.locator('#projectMaterialForm');
    await expect(form).toHaveAttribute('data-project-id', materialProject);
    await expect(page.getByText('Aktuelle Zeitbuchung', { exact: true })).toBeVisible();
    const today = await form.locator('[name="work_date"]').getAttribute('max');
    const yesterday = new Date(Date.parse(today) - 86400000).toISOString().slice(0, 10);
    const fields = {
      description: 'Kabel NYM-J 3 × 1,5 mm²', quantity: '25', unit: 'm', work_date: yesterday,
      note: 'Nachträgliche Dokumentation der verlegten Leitungen',
    };
    for (const [name, value] of Object.entries(fields)) await form.locator('[name="' + name + '"]').fill(value);
    const note = form.locator('[name="note"]');
    await note.evaluate((element) => { element.focus(); element.setSelectionRange(4, 12); });
    await page.waitForLoadState('networkidle');

    const refresh = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/app/me/day');
    await page.clock.fastForward(61000);
    expect((await refresh).ok()).toBeTruthy();
    await expect(form).toHaveAttribute('data-project-id', materialProject);
    await expect(note).toBeFocused();
    expect(await note.evaluate((element) => [element.selectionStart, element.selectionEnd])).toEqual([4, 12]);

    await page.reload();
    await expect(form).toHaveAttribute('data-project-id', materialProject);
    for (const [name, value] of Object.entries(fields)) await expect(form.locator('[name="' + name + '"]')).toHaveValue(value);
    await expect(form.getByText(/Materialnachtrag.*UI Materialprojekt/)).toBeVisible();
    const [savedResponse] = await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === materialPath),
      form.getByRole('button', { name: 'Material speichern', exact: true }).click(),
    ]);
    expect(savedResponse.status()).toBe(201);
    const saved = (await savedResponse.json()).data;
    expect(saved).toMatchObject({
      project_id: Number(materialProject), captured_work_project_id: Number(workProject),
      capture_type: 'cross_project_backdated', work_date: yesterday,
    });
    expect(saved.created_at.slice(0, 10)).toBe(today);
    await expect(page.getByText('Projektübergreifend erfasst', { exact: true })).toBeVisible();
    await expect(page.getByText('Nachgetragen', { exact: true })).toBeVisible();
    const materialRow = page.locator('.app-material-row').filter({ hasText: fields.description });
    await expect(materialRow).toContainText('Materialdatum ' + yesterday.split('-').reverse().join('.'));
    await expect(materialRow).toContainText(today.split('-').reverse().join('.') + ' ' + saved.created_at.slice(11, 16));
    const workMaterials = await page.request.get('/api/v1/app/projects/' + workProject + '/materials');
    expect(workMaterials.ok()).toBeTruthy();
    expect((await workMaterials.json()).data).toEqual([]);

    const after = snapshot();
    expect(after.time).toEqual(before.time);
    expect(after.materials).toHaveLength(1);
    expect(after.materials[0]).toMatchObject({
      id: saved.id, project_id: Number(materialProject), work_date: yesterday,
      capture_type: 'cross_project_backdated', captured_work_project_id: Number(workProject), captured_timesheet_id: runningEntry.id,
    });
    expect(await accountData()).toEqual(balanceBefore);

    const adminPage = await browser.newPage({ baseURL });
    try {
      await adminPage.goto('/admin/login');
      await adminPage.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
      await adminPage.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
      await Promise.all([adminPage.waitForURL('**/admin'), adminPage.getByRole('button', { name: 'Anmelden', exact: true }).click()]);
      await adminPage.goto('/admin/projects/' + materialProject + '/edit');
      const row = adminPage.locator('#project-materials tr').filter({ hasText: fields.description });
      await expect(row).toContainText('Projektübergreifend erfasst');
      await expect(row).toContainText('Nachgetragen');
      await expect(row).toContainText('UI Materialerfassung');
      await expect(row).toContainText(fields.note);
      await expect(row.getByRole('button', { name: 'Archivieren' })).toBeVisible();
    } finally {
      await adminPage.close();
    }
  });

  test('a stale tab cannot save an old draft under the new browser session', async ({ page, context }) => {
    const project = process.env.UI_TEST_MATERIAL_PROJECT_B;
    const materialPath = '/api/v1/app/projects/' + project + '/materials';
    const description = 'Entwurf des ersten Browserkontos';
    const draftKey = 'project_material_draft_v1_user_' + process.env.UI_TEST_MATERIAL_USER_ID + '_project_' + project;
    await page.clock.install();
    await page.goto('/app/heute');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_MATERIAL_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page.locator('#projectSelect')).toBeVisible();
    await page.goto('/app/projektwahl?project=' + project);
    await page.locator('#projectMaterialDescription').fill(description);
    await expect.poll(() => cacheValue(page, draftKey)).toMatchObject({ description, dirty: true });
    await page.clock.pauseAt(new Date(Date.now() + 1000));

    const other = await context.newPage();
    try {
      expect((await other.request.post('/api/v1/auth/logout')).ok()).toBeTruthy();
      await other.goto('/app/login');
      await other.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
      await other.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
      await other.getByRole('button', { name: 'Anmelden', exact: true }).click();
      await expect.poll(async () => (await cacheValue(other, 'session'))?.user?.email).toBe(process.env.UI_TEST_ADMIN_EMAIL);
      const before = await (await other.request.get(materialPath)).json();
      expect(before.ok).toBeTruthy();

      const [response] = await Promise.all([
        page.waitForResponse((result) => new URL(result.url()).pathname === materialPath && result.request().method() === 'POST'),
        page.locator('#projectMaterialForm').evaluate((form) => form.requestSubmit()),
      ]);
      expect(response.status()).toBe(401);
      expect((await response.json()).code).toBe('session_changed');
      await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
      const after = await (await other.request.get(materialPath)).json();
      expect(after).toEqual(before);
      expect(await cacheValue(other, draftKey)).toMatchObject({ description, dirty: true });
      expect((await cacheValue(other, 'session')).user.email).toBe(process.env.UI_TEST_ADMIN_EMAIL);
    } finally {
      await other.close();
    }
  });

  test('service worker replaces the old app cache and serves the current shell and versioned assets offline', async ({ page, context }) => {
    await page.goto('/admin/login');
    await page.evaluate(async () => {
      const oldCache = await caches.open('zeiterfassung-app-v7');
      await oldCache.put('/assets/js/app.js?v=old', new Response('obsolete app code'));
    });
    await page.goto('/app');
    await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBeTruthy();
    const assets = await page.evaluate(() => [
      document.querySelector('script[src^="/assets/js/app.js?v="]').src,
      document.querySelector('link[href^="/assets/css/app.css?v="]').href,
    ]);
    const worker = await page.request.get('/app/sw.js');
    expect(worker.ok()).toBeTruthy();
    const workerScript = await worker.text();
    for (const asset of assets) expect(workerScript).toContain(new URL(asset).pathname + new URL(asset).search);
    const cacheState = await page.evaluate(async (urls) => ({
      caches: await caches.keys(),
      currentAssets: await Promise.all(urls.map(async (url) => (await caches.match(url))?.ok ?? false)),
    }), assets);
    expect(cacheState.caches).not.toContain('zeiterfassung-app-v7');
    expect(cacheState.currentAssets).toEqual([true, true]);
    await context.setOffline(true);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
  });
});
