const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

async function mockWorkReportApp(page, options = {}) {
  const app = {
    authenticated: true,
    userId: 7,
    dayError: null,
    dayReads: 0,
    reportReads: 0,
    posts: [],
    archives: [],
    nextPost: null,
    heldGet: null,
    heldPost: null,
    heldLogout: null,
    projects: [
      { id: 1, project_number: 'P-001', name: 'Baustelle Eins', customer_name: 'Kunde Eins', work_instructions: 'Leitung prüfen.\nVor Beginn fotografieren.' },
      { id: 2, project_number: 'P-002', name: 'Baustelle Zwei', customer_name: 'Kunde Zwei', work_instructions: 'Sicherung abschalten.' }
    ],
    flags: {
      show_project_files: true,
      show_project_work_instructions: true,
      show_project_materials: true,
      show_personnel_overview: false,
      ...(options.flags || {})
    },
    reports: {
      1: [
        { id: 11, project_id: 1, created_by_user_id: 7, created_by_name: 'Max Mustermann', work_date: '2026-07-22', description: 'Eigener früherer Bericht', created_at: '2026-07-22 09:00:00' },
        { id: 12, project_id: 1, created_by_user_id: 8, created_by_name: 'Erika Beispiel', work_date: '2026-07-22', description: 'Bericht aus dem Team', created_at: '2026-07-22 10:00:00' }
      ],
      2: [{ id: 21, project_id: 2, created_by_user_id: 8, created_by_name: 'Erika Beispiel', work_date: '2026-07-22', description: 'Zweiter Projektbericht', created_at: '2026-07-22 11:00:00' }]
    },
    deferGet(projectId) {
      const started = deferred();
      const response = deferred();
      app.heldGet = { projectId, started, response };
      return { started: started.promise, release: response.resolve };
    },
    deferPost() {
      const started = deferred();
      const response = deferred();
      app.heldPost = { started, response };
      return { started: started.promise, release: response.resolve };
    },
    deferLogout() {
      const started = deferred();
      const response = deferred();
      app.heldLogout = { started, response };
      return { started: started.promise, release: response.resolve };
    }
  };
  const submissions = new Map();
  const json = (route, data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
  const user = () => ({
    id: app.userId,
    display_name: app.userId === 7 ? 'Max Mustermann' : 'Erika Beispiel',
    email: app.userId === 7 ? 'max@example.test' : 'erika@example.test',
    permissions: ['timesheets.create', 'timesheets.view_own', 'files.view', 'files.upload']
  });

  // Every API write in this suite is intercepted, including session actions.
  await page.route('**/api/v1/**', (route) => json(route, { ok: false, error: 'Unexpected test API request' }, 501));
  await page.route('**/api/v1/auth/session', (route) => json(route, {
    authenticated: app.authenticated, bootstrap_required: false, user: app.authenticated ? user() : null
  }));
  await page.route('**/api/v1/auth/login', (route) => {
    app.authenticated = true;
    return json(route, { authenticated: true, user: user(), session: { authenticated: true, user: user() } });
  });
  await page.route('**/api/v1/auth/logout', async (route) => {
    if (app.heldLogout) {
      const held = app.heldLogout;
      app.heldLogout = null;
      held.started.resolve();
      await held.response.promise;
    }
    app.authenticated = false;
    return json(route, { ok: true });
  });
  await page.route('**/api/v1/app/me/day', (route) => {
    app.dayReads += 1;
    if (app.dayError) {
      return json(route, { ok: false, error: app.dayError.message }, app.dayError.status);
    }
    return json(route, { data: {
      today: '2026-07-23', server_time: '2026-07-23T10:00:00+02:00',
      user: { ...user(), roles: [], app_ui_settings: app.flags },
      app_ui_settings: app.flags,
      mandatory_app_widgets: ['day_status', 'start_time', 'end_time', 'breaks', 'current_net_minutes', 'current_project', 'time_actions'],
      projects: app.projects,
      today_state: { status: 'not_started', is_missing: false, work_entry: null, status_entry: null, current_break: null },
      current_break: null, breaks_today: [], attachments: [], project_day_summaries: [],
      tracked_minutes_live_basis: null, company: {}, geo_policy: {}, personnel_events: [], personnel_labels: []
    } });
  });
  await page.route('**/api/v1/settings/company', (route) => json(route, { data: { company_name: 'Muster Bau' } }));
  await page.route('**/api/v1/app/push/status', (route) => json(route, { data: { enabled: false, can_subscribe: false, devices: [] } }));
  await page.route('**/api/v1/app/projects/*/files', (route) => json(route, { ok: true, data: [] }));
  await page.route('**/api/v1/app/projects/*/materials', (route) => json(route, { ok: true, data: [
    { id: 31, project_id: 1, created_by_user_id: 7, created_by_name: 'Max Mustermann', description: 'Kupferrohr', quantity: '2.500', unit: 'm', work_date: '2026-07-22' }
  ] }));
  await page.route('**/api/v1/app/projects/*/work-reports', async (route) => {
    const projectId = Number(route.request().url().match(/projects\/(\d+)\/work-reports/)[1]);
    if (route.request().method() !== 'POST') {
      app.reportReads += 1;
      if (app.heldGet && app.heldGet.projectId === projectId) {
        const held = app.heldGet;
        app.heldGet = null;
        held.started.resolve();
        const response = await held.response.promise;
        return json(route, { ok: true, data: response || app.reports[projectId] || [] });
      }
      return json(route, { ok: true, data: app.reports[projectId] || [] });
    }

    const payload = route.request().postDataJSON();
    app.posts.push({ projectId, payload });
    if (app.heldPost) {
      const held = app.heldPost;
      app.heldPost = null;
      held.started.resolve();
      await held.response.promise;
    }
    const behavior = app.nextPost;
    app.nextPost = null;
    if (behavior && typeof behavior === 'object' && behavior.status) {
      return json(route, { ok: false, error: behavior.message, message: behavior.message }, behavior.status);
    }
    const key = app.userId + ':' + payload.request_id;
    let entry = submissions.get(key);
    if (!entry) {
      entry = {
        ...payload, id: 100 + submissions.size, project_id: projectId,
        created_by_user_id: app.userId, created_by_name: user().display_name,
        created_at: '2026-07-23 12:00:00', is_deleted: false
      };
      submissions.set(key, entry);
      app.reports[projectId] = [entry, ...(app.reports[projectId] || [])];
    }
    if (behavior === 'lost') {
      await route.abort('failed');
      return;
    }
    return json(route, { ok: true, data: entry, message: 'Arbeitsbericht wurde gespeichert.' }, 201);
  });
  await page.route('**/api/v1/app/project-work-reports/*', (route) => {
    const id = Number(route.request().url().match(/project-work-reports\/(\d+)/)[1]);
    app.archives.push(id);
    Object.keys(app.reports).forEach((projectId) => {
      app.reports[projectId] = app.reports[projectId].filter((item) => item.id !== id);
    });
    return json(route, { ok: true, data: { id, project_id: 1 } });
  });
  return app;
}

async function openProject(page, projectId = 1) {
  await page.goto('/app/projektwahl?project=' + projectId);
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDate')).toBeEnabled();
}

async function cachedDraftContains(page, text) {
  return page.evaluate((needle) => new Promise((resolve, reject) => {
    const opened = indexedDB.open('zeiterfassung-app');
    opened.onerror = () => reject(opened.error);
    opened.onsuccess = () => {
      const database = opened.result;
      const request = database.transaction('cache', 'readonly').objectStore('cache').getAll();
      request.onerror = () => { database.close(); reject(request.error); };
      request.onsuccess = () => {
        const values = request.result.filter((item) => String(item.key).includes('work_report_draft'));
        database.close();
        resolve(JSON.stringify(values).includes(needle));
      };
    };
  }), text);
}

async function waitForStoredDraft(page, text) {
  await expect.poll(() => cachedDraftContains(page, text)).toBe(true);
}

async function refresh(page) {
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
}

test('work reports are independent of material visibility and display team text safely', async ({ page }) => {
  const app = await mockWorkReportApp(page, { flags: { show_project_materials: false } });
  const unsafeText = '<img src=x onerror="window.reportXss=true">\nRohr verlegt & geprüft.';
  app.reports[1][1].description = unsafeText;
  await openProject(page);
  await expect(page.getByRole('heading', { name: 'Ausgeführte Arbeiten', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Materialdokumentation' })).toHaveCount(0);
  await expect(page.locator('#projectWorkReportList')).toContainText(unsafeText);
  await expect(page.locator('#projectWorkReportList')).toContainText('Erika Beispiel');
  await expect(page.locator('#projectWorkReportList img, #projectWorkReportList script')).toHaveCount(0);
  expect(await page.evaluate(() => window.reportXss)).toBeUndefined();
  await expect(page.locator('[data-archive-project-work-report="11"]')).toHaveCount(1);
  await expect(page.locator('[data-archive-project-work-report="12"]')).toHaveCount(0);
  expect(await page.locator('#projectWorkReportForm').evaluate((form) =>
    !!(form.compareDocumentPosition(document.getElementById('projectWorkReportList')) & Node.DOCUMENT_POSITION_FOLLOWING)
  )).toBe(true);
});

test('work report save and own archive affect one report and clear only the saved draft', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectMaterialDescription').fill('Unverändertes Material');
  await page.locator('#projectWorkReportDate').fill('2026-07-22');
  await page.locator('#projectWorkReportDescription').fill('Heizkreis montiert.\nDruckprobe bestanden.');
  await waitForStoredDraft(page, 'Druckprobe bestanden.');
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect(page.locator('#projectWorkReportList')).toContainText('Druckprobe bestanden.');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Unverändertes Material');
  expect(app.posts).toHaveLength(1);
  expect(app.posts[0]).toMatchObject({ projectId: 1, payload: { work_date: '2026-07-22', description: 'Heizkreis montiert.\nDruckprobe bestanden.' } });
  expect(app.posts[0].payload.request_id).toEqual(expect.any(String));
  expect(app.posts[0].payload.request_id.length).toBeGreaterThan(10);

  await page.reload();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('[data-archive-project-work-report="100"]').click();
  await expect.poll(() => app.archives).toEqual([100]);
  await expect(page.locator('#projectWorkReportList')).not.toContainText('Druckprobe bestanden.');
  await expect(page.locator('#projectWorkReportList')).toContainText('Bericht aus dem Team');
});

test('offline report drafts survive reload and project switches without automatic submission', async ({ page }) => {
  // Keep the test shell available while exercising the app's offline boot path.
  // This isolates draft recovery from service-worker asset caching.
  await page.addInitScript(() => Object.defineProperty(navigator, 'onLine', {
    configurable: true, get: () => sessionStorage.getItem('ui.test.offline') !== '1'
  }));
  const app = await mockWorkReportApp(page);
  await openProject(page);
  const berlinToday = await page.evaluate(() => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Berlin' }).format(new Date()));
  await expect(page.locator('#projectWorkReportDate')).toHaveValue(berlinToday);
  await expect(page.locator('#projectWorkReportDate')).toHaveAttribute('max', berlinToday);
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportList')).toContainText('Zweiter Projektbericht');
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await page.evaluate(() => {
    sessionStorage.setItem('ui.test.offline', '1');
    window.dispatchEvent(new Event('offline'));
  });
  await page.locator('#projectWorkReportDate').fill('2026-07-21');
  await page.locator('#projectWorkReportDescription').fill('Offline in Projekt Eins eingegeben');
  await waitForStoredDraft(page, 'Offline in Projekt Eins eingegeben');
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  expect(app.posts).toHaveLength(0);
  await page.reload();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Offline in Projekt Eins eingegeben');
  await expect(page.locator('#projectWorkReportDate')).toHaveValue('2026-07-21');

  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await page.locator('#projectWorkReportDescription').fill('Eigenständiger Entwurf Projekt Zwei');
  await waitForStoredDraft(page, 'Eigenständiger Entwurf Projekt Zwei');
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Offline in Projekt Eins eingegeben');
  await page.evaluate(() => {
    sessionStorage.removeItem('ui.test.offline');
    window.dispatchEvent(new Event('online'));
  });
  await expect(page.locator('#projectWorkReportStatus')).not.toContainText('Offline');
  expect(app.posts).toHaveLength(0);
});

test('a pending report save locks its project while another project draft remains editable', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDescription').fill('Gespeicherter Bericht in Projekt Eins');
  const held = app.deferPost();
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await held.started;
  await expect(page.locator('#projectWorkReportDescription')).toHaveJSProperty('readOnly', true);
  await expect(page.locator('#projectWorkReportDate')).toBeDisabled();
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDate')).toBeEnabled();
  await page.locator('#projectWorkReportDescription').fill('Anderer Entwurf während laufendem Speichern');
  await waitForStoredDraft(page, 'Anderer Entwurf während laufendem Speichern');
  await page.locator('#projectSelect').selectOption('1');
  await expect(page.locator('#projectWorkReportDescription')).toHaveJSProperty('readOnly', true);
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Gespeicherter Bericht in Projekt Eins');
  held.release();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await expect(page.locator('#projectWorkReportList')).toContainText('Gespeicherter Bericht in Projekt Eins');
  expect(app.posts).toHaveLength(1);
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Anderer Entwurf während laufendem Speichern');
  await page.reload();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Anderer Entwurf während laufendem Speichern');
});

test('lost save response retries the persisted submission key after reload', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDescription').fill('Einmalig trotz verlorener Antwort');
  app.nextPost = 'lost';
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect.poll(() => app.posts.length).toBe(1);
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Einmalig trotz verlorener Antwort');
  await page.reload();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Einmalig trotz verlorener Antwort');
  expect(app.posts).toHaveLength(1);
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  expect(app.posts).toHaveLength(2);
  expect(app.posts[1]).toEqual(app.posts[0]);
  expect(app.reports[1].filter((entry) => entry.description === 'Einmalig trotz verlorener Antwort')).toHaveLength(1);
});

test('validation and conflict errors keep the draft and changed text gets a new submission key', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDescription').fill('Erster Entwurf mit Fehler');
  app.nextPost = { status: 422, message: 'Bitte ein gültiges Berichtsdatum wählen.' };
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect(page.getByText('Bitte ein gültiges Berichtsdatum wählen.', { exact: true })).toBeVisible();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Erster Entwurf mit Fehler');
  await page.locator('#projectWorkReportDescription').fill('Korrigierter Bericht');
  app.nextPost = { status: 409, message: 'Dieser Anfrageschlüssel gehört zu einem anderen Bericht.' };
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect(page.getByText('Dieser Anfrageschlüssel gehört zu einem anderen Bericht.', { exact: true })).toBeVisible();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Korrigierter Bericht');
  await waitForStoredDraft(page, 'Korrigierter Bericht');
  expect(app.posts).toHaveLength(2);
  expect(app.posts[1].payload.request_id).not.toBe(app.posts[0].payload.request_id);
});

test('minute refresh retains original form nodes, focus, selection and scroll position', async ({ page }) => {
  await page.clock.install();
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await expect(page.locator('#projectWorkReportList')).toContainText('Bericht aus dem Team');
  await page.locator('#projectWorkReportDescription').fill('Rohr verlegt und sorgfältig geprüft');
  await page.locator('#projectWorkReportDescription').focus();
  await page.evaluate(() => {
    const field = document.getElementById('projectWorkReportDescription');
    field.setSelectionRange(5, 12);
    window.originalReportField = field;
    window.originalReportForm = document.getElementById('projectWorkReportForm');
    window.originalFileInput = document.getElementById('projectAttachmentInput');
    window.originalMaterialInput = document.getElementById('projectMaterialDescription');
    const top = field.getBoundingClientRect().top + window.scrollY;
    window.scrollTo(0, top - 160);
    window.originalReportScroll = window.scrollY;
    window.originalReportTop = field.getBoundingClientRect().top;
  });
  app.reports[1].unshift({ id: 40, project_id: 1, created_by_user_id: 8, created_by_name: 'Erika Beispiel', work_date: '2026-07-22', description: 'Während der Minute hinzugefügter Teambericht' });
  await page.clock.fastForward(60000);
  await page.clock.runFor(250);
  await expect(page.locator('#projectWorkReportList')).toContainText('Während der Minute hinzugefügter Teambericht');
  expect(await page.evaluate(() => ({
    field: window.originalReportField === document.getElementById('projectWorkReportDescription'),
    form: window.originalReportForm === document.getElementById('projectWorkReportForm'),
    file: window.originalFileInput === document.getElementById('projectAttachmentInput'),
    material: window.originalMaterialInput === document.getElementById('projectMaterialDescription'),
    focus: document.activeElement === window.originalReportField,
    start: window.originalReportField.selectionStart,
    end: window.originalReportField.selectionEnd,
    scrollDelta: Math.abs(window.scrollY - window.originalReportScroll),
    topDelta: Math.abs(window.originalReportField.getBoundingClientRect().top - window.originalReportTop)
  }))).toEqual({ field: true, form: true, file: true, material: true, focus: true, start: 5, end: 12, scrollDelta: 0, topDelta: 0 });
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Rohr verlegt und sorgfältig geprüft');
});

test('a response started before composition preserves dictated text and original textarea', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await expect(page.locator('#projectWorkReportList')).toContainText('Bericht aus dem Team');
  const held = app.deferGet(1);
  await refresh(page);
  await held.started;
  await page.locator('#projectWorkReportDescription').focus();
  await page.locator('#projectWorkReportDescription').evaluate((field) => {
    window.composingReportField = field;
    field.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    field.value = 'Per Diktat während der Aktualisierung';
    field.setSelectionRange(12, 12);
    field.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', isComposing: true, data: field.value }));
  });
  held.release([{ id: 77, project_id: 1, created_by_user_id: 8, created_by_name: 'Erika Beispiel', work_date: '2026-07-22', description: 'Antwort während Diktat' }]);
  await expect(page.locator('#projectWorkReportList')).toContainText('Antwort während Diktat');
  expect(await page.evaluate(() => window.composingReportField === document.getElementById('projectWorkReportDescription'))).toBe(true);
  await expect(page.locator('#projectWorkReportDescription')).toBeFocused();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Per Diktat während der Aktualisierung');
  await page.locator('#projectWorkReportDescription').evaluate((field) => {
    field.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: field.value }));
    field.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: field.value }));
  });
  await waitForStoredDraft(page, 'Per Diktat während der Aktualisierung');
});

test('late project responses do not replace another project draft or reveal old report text', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await expect(page.locator('#projectWorkReportList')).toContainText('Bericht aus dem Team');
  const held = app.deferGet(1);
  await refresh(page);
  await held.started;
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportList')).toContainText('Zweiter Projektbericht');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await page.locator('#projectWorkReportDescription').fill('Aktueller Entwurf in Projekt Zwei');
  held.release([{ id: 89, project_id: 1, description: 'Veraltete Antwort von Projekt Eins', work_date: '2026-07-22', created_by_user_id: 7 }]);
  await waitForStoredDraft(page, 'Aktueller Entwurf in Projekt Zwei');
  await expect(page.locator('#projectSelect')).toHaveValue('2');
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Aktueller Entwurf in Projekt Zwei');
  await expect(page.locator('#projectWorkReportList')).not.toContainText('Veraltete Antwort von Projekt Eins');
});

test('history loads immediately while a previous project refresh is still waiting for reports', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  let historyReads = 0;
  await page.route('**/api/v1/app/me/timesheets**', (route) => {
    historyReads += 1;
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, data: {
        items: [{
          id: 72, project_id: 1, project_name: 'Baustelle Eins', work_date: '2026-07-22',
          start_time: '07:00:00', end_time: '15:30:00', break_minutes: 30, net_minutes: 480,
          entry_type: 'work', note: 'Historie trotz ausstehendem Projektrequest', breaks: [], attachments: []
        }],
        projects: app.projects, scope: 'all', cached_at: '2026-07-23T12:00:00+02:00'
      } })
    });
  });
  await openProject(page);
  await expect(page.locator('#projectWorkReportList')).toContainText('Bericht aus dem Team');
  const held = app.deferGet(1);
  await refresh(page);
  await held.started;
  try {
    await page.locator('#appMenuToggle').click();
    await page.locator('.app-drawer [data-app-link][href="/app/historie"]').click();
    await expect(page).toHaveURL('/app/historie');
    await expect(page.getByText('Historie trotz ausstehendem Projektrequest', { exact: true })).toBeVisible();
    expect(historyReads).toBeGreaterThan(0);
  } finally {
    held.release();
  }
  await expect(page.locator('#projectWorkReportForm')).toHaveCount(0);
});

test('confirmed revocation removes persisted project drafts while temporary refresh errors keep them', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  const text = 'Privater Entwurf vor Rechteentzug';
  await page.locator('#projectWorkReportDescription').fill(text);
  await waitForStoredDraft(page, text);
  app.dayError = { status: 503, message: 'Verbindung vorübergehend nicht möglich.' };
  const before = app.dayReads;
  await refresh(page);
  await expect.poll(() => app.dayReads).toBeGreaterThan(before);
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue(text);
  expect(await cachedDraftContains(page, text)).toBe(true);
  app.dayError = null;
  app.projects = app.projects.filter((project) => project.id !== 1);
  await refresh(page);
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect(page.locator('#projectWorkReportDescription')).toHaveCount(0);
  await expect(page.locator('#projectWorkReportSection')).toContainText('Bitte zuerst ein Projekt auswählen.');
  await expect.poll(() => cachedDraftContains(page, text)).toBe(false);
  await page.reload();
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect(page.locator('#projectWorkReportDescription')).toHaveCount(0);
});

test('a failed today cache update cannot restore revoked access on the next failed refresh', async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'onLine', {
    configurable: true, get: () => sessionStorage.getItem('ui.test.offline') !== '1'
  }));
  const app = await mockWorkReportApp(page);
  await openProject(page);
  const text = 'Entwurf vor bestätigtem Entzug mit altem Cache';
  await page.locator('#projectWorkReportDescription').fill(text);
  await waitForStoredDraft(page, text);
  await page.evaluate(() => {
    const originalPut = IDBObjectStore.prototype.put;
    window.failedTodayCacheWrites = 0;
    IDBObjectStore.prototype.put = function (record, ...args) {
      if (this.name === 'cache' && String(record.key).startsWith('today_')) {
        window.failedTodayCacheWrites += 1;
        throw new DOMException('Test cache update failed', 'QuotaExceededError');
      }
      return originalPut.call(this, record, ...args);
    };
  });
  app.projects = app.projects.filter((project) => project.id !== 1);
  await refresh(page);
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.failedTodayCacheWrites)).toBeGreaterThan(0);
  await expect.poll(() => cachedDraftContains(page, text)).toBe(false);
  app.dayError = { status: 503, message: 'Nächste Aktualisierung vorübergehend fehlgeschlagen.' };
  const reads = app.dayReads;
  await refresh(page);
  await expect.poll(() => app.dayReads).toBeGreaterThan(reads);
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect(page.locator('#projectWorkReportDescription')).toHaveCount(0);
  await expect(page.getByText('Bericht aus dem Team', { exact: true })).toHaveCount(0);
  await page.evaluate(() => sessionStorage.setItem('ui.test.offline', '1'));
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Baustelle waehlen', exact: true })).toBeVisible();
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect(page.getByText('Bericht aus dem Team', { exact: true })).toHaveCount(0);
  await expect(page.locator('#projectWorkReportDescription')).toHaveCount(0);
});

test('a report save rejected after revocation clears its draft and allows another explicit project selection', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  const text = 'Nicht mehr erlaubter Entwurf beim Speichern';
  await page.locator('#projectWorkReportDescription').fill(text);
  await waitForStoredDraft(page, text);
  app.projects = app.projects.filter((project) => project.id !== 1);
  app.nextPost = { status: 403, message: 'Keine Berechtigung für dieses Projekt.' };
  await page.getByRole('button', { name: 'Arbeitsbericht speichern', exact: true }).click();
  await expect(page.locator('#projectSelect option[value="1"]')).toHaveCount(0);
  await expect.poll(() => cachedDraftContains(page, text)).toBe(false);
  await expect(page.locator('#projectSelect')).toHaveValue('');
  await expect(page.locator('#projectWorkReportSection')).toContainText('Bitte zuerst ein Projekt auswählen.');
  await page.locator('#projectSelect').selectOption('2');
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await expect(page.locator('#projectWorkReportList')).toContainText('Zweiter Projektbericht');
  expect(app.posts).toHaveLength(1);
});

test('a slow logout hides project data before its HTTP response returns', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDescription').fill('Privater Entwurf vor langsamem Logout');
  await waitForStoredDraft(page, 'Privater Entwurf vor langsamem Logout');
  const held = app.deferLogout();
  await page.locator('#appMenuToggle').click();
  await page.locator('#logoutButton').click();
  await held.started;
  try {
    await expect(page.locator('#loginForm')).toBeVisible();
    await expect(page.locator('#projectWorkReportForm, #projectWorkReportList')).toHaveCount(0);
    await expect(page.getByText('Privater Entwurf vor langsamem Logout', { exact: true })).toHaveCount(0);
    const dayReads = app.dayReads;
    await refresh(page);
    expect(app.dayReads).toBe(dayReads);
  } finally {
    held.release();
  }
  await expect.poll(() => cachedDraftContains(page, 'Privater Entwurf vor langsamem Logout')).toBe(false);
});

test('session expiry keeps the durable draft for the same employee after login', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDate').fill('2026-07-21');
  await page.locator('#projectWorkReportDescription').fill('Entwurf vor abgelaufener Sitzung');
  await waitForStoredDraft(page, 'Entwurf vor abgelaufener Sitzung');
  app.dayError = { status: 401, message: 'Die Sitzung ist abgelaufen.' };
  await refresh(page);
  await expect(page.locator('#loginForm')).toBeVisible();
  expect(await cachedDraftContains(page, 'Entwurf vor abgelaufener Sitzung')).toBe(true);
  app.dayError = null;
  await page.locator('#loginForm [name="email"]').fill('max@example.test');
  await page.locator('#loginForm [name="password"]').fill('test-password');
  await page.locator('#loginForm').getByRole('button', { name: 'Anmelden', exact: true }).click();
  await expect(page).toHaveURL('/app/heute');
  await page.locator('#appMenuToggle').click();
  await page.locator('.app-drawer [data-app-link][href="/app/projektwahl"]').click();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Entwurf vor abgelaufener Sitzung');
  await expect(page.locator('#projectWorkReportDate')).toHaveValue('2026-07-21');
  expect(app.posts).toHaveLength(0);
});

test('logout discards old drafts and late responses across a different employee login', async ({ page }) => {
  const app = await mockWorkReportApp(page);
  await openProject(page);
  await page.locator('#projectWorkReportDescription').fill('Entwurf ausschließlich für Max');
  await waitForStoredDraft(page, 'Entwurf ausschließlich für Max');
  const held = app.deferGet(1);
  await refresh(page);
  await held.started;
  await page.locator('#appMenuToggle').click();
  await page.locator('#logoutButton').click();
  await expect(page.locator('#loginForm')).toBeVisible();
  held.release([{ id: 91, project_id: 1, description: 'Verspätete private Antwort für Max', work_date: '2026-07-22', created_by_user_id: 7 }]);
  await expect.poll(() => cachedDraftContains(page, 'Entwurf ausschließlich für Max')).toBe(false);
  app.userId = 8;
  await page.locator('#loginForm [name="email"]').fill('erika@example.test');
  await page.locator('#loginForm [name="password"]').fill('test-password');
  await page.locator('#loginForm').getByRole('button', { name: 'Anmelden', exact: true }).click();
  await expect(page).toHaveURL('/app/heute');
  await page.locator('#appMenuToggle').click();
  await page.locator('.app-drawer [data-app-link][href="/app/projektwahl"]').click();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('');
  await expect(page.locator('#projectWorkReportList')).not.toContainText('Verspätete private Antwort für Max');
  await page.locator('#projectWorkReportDescription').fill('Erikas eigener Entwurf');
  await waitForStoredDraft(page, 'Erikas eigener Entwurf');
  await page.reload();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Erikas eigener Entwurf');
});

test('draft storage failure warns visibly and retains the editable text', async ({ page }) => {
  await mockWorkReportApp(page);
  await openProject(page);
  await page.evaluate(() => {
    const originalPut = IDBObjectStore.prototype.put;
    IDBObjectStore.prototype.put = function (record, ...args) {
      if (this.name === 'cache' && String(record.key).includes('work_report_draft')) {
        throw new DOMException('Test quota exceeded', 'QuotaExceededError');
      }
      return originalPut.call(this, record, ...args);
    };
  });
  await page.locator('#projectWorkReportDescription').fill('Text bei vollem Gerätespeicher');
  await expect(page.locator('#projectWorkReportStatus [role="alert"]')).toBeVisible();
  await expect(page.locator('#projectWorkReportDescription')).toBeEditable();
  await expect(page.locator('#projectWorkReportDescription')).toHaveValue('Text bei vollem Gerätespeicher');
});
