const path = require('path');
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

async function mockProjectOrderApp(page, options = {}) {
  const projects = options.projects || [
    {
      id: 1,
      project_number: 'P-001',
      name: 'Baustelle Eins',
      customer_name: 'Kunde Eins',
      address_line_1: 'Musterweg 1',
      postal_code: '12345',
      city: 'Berlin',
      work_instructions: 'Leitung pruefen.\nVor Beginn fotografieren.',
      work_instructions_updated_at: '2026-07-22 12:00:00'
    },
    {
      id: 2,
      project_number: 'P-002',
      name: 'Baustelle Zwei',
      customer_name: 'Kunde Zwei',
      address_line_1: 'Bauweg 2',
      postal_code: '54321',
      city: 'Potsdam',
      work_instructions: '<script>nicht ausfuehren</script>\nSicherung abschalten.',
      work_instructions_updated_at: '2026-07-23 08:30:00'
    }
  ];
  const flags = {
    show_project_files: true,
    show_project_work_instructions: true,
    show_project_materials: true,
    ...(options.flags || {})
  };
  const materials = {
    1: [{ id: 11, project_id: 1, created_by_user_id: 7, created_by_name: 'Max Mustermann', work_date: '2026-07-22', description: 'Kupferrohr', quantity: '2.500', unit: 'm', note: 'Heizkreis' }],
    2: [{ id: 21, project_id: 2, created_by_user_id: 8, created_by_name: 'Erika Beispiel', work_date: '2026-07-23', description: 'Sicherung', quantity: '1.000', unit: 'Stueck', note: null }]
  };
  const postedMaterials = [];
  const archivedMaterials = [];

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own', 'files.view']
        }
      })
    });
  });
  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-07-23',
          server_time: '2026-07-23T10:00:00+02:00',
          user: { id: 7, display_name: 'Max Mustermann', email: 'max@example.test', roles: [], app_ui_settings: flags },
          app_ui_settings: flags,
          mandatory_app_widgets: ['day_status', 'start_time', 'end_time', 'breaks', 'current_net_minutes', 'current_project', 'time_actions'],
          projects,
          attachments: [],
          today_state: { status: 'not_started', is_missing: false, work_entry: null, status_entry: null, current_break: null },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [],
          company: {},
          geo_policy: {},
          personnel_events: [],
          personnel_labels: []
        }
      })
    });
  });
  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { company_name: 'Muster Bau' } }) });
  });
  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } }) });
  });
  await page.route('**/api/v1/app/projects/*/files', async (route) => {
    const match = route.request().url().match(/projects\/(\d+)\/files/);
    const projectId = match ? Number(match[1]) : 0;
    const files = projectId === 2 ? [{
      id: 51,
      original_name: 'auftrag.pdf',
      mime_type: 'application/pdf',
      size_bytes: 1234,
      is_image: false,
      download_url: '/api/v1/app/project-files/51/download',
      preview_url: null
    }] : [];

    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: files }) });
  });
  await page.route('**/api/v1/app/projects/*/materials', async (route) => {
    const match = route.request().url().match(/projects\/(\d+)\/materials/);
    const projectId = match ? Number(match[1]) : 0;

    if (route.request().method() === 'POST') {
      const payload = route.request().postDataJSON();
      postedMaterials.push({ projectId, payload });
      materials[projectId] = [
        ...(materials[projectId] || []),
        {
          id: 99,
          project_id: projectId,
          created_by_user_id: 7,
          created_by_name: 'Max Mustermann',
          ...payload
        }
      ];
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ok: true, data: materials[projectId].at(-1) }) });
      return;
    }

    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: materials[projectId] || [] }) });
  });
  await page.route('**/api/v1/app/project-materials/*', async (route) => {
    const match = route.request().url().match(/project-materials\/(\d+)/);
    const materialId = match ? Number(match[1]) : 0;
    archivedMaterials.push(materialId);

    Object.keys(materials).forEach((projectId) => {
      materials[projectId] = materials[projectId].filter((item) => item.id !== materialId);
    });
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: {} }) });
  });

  return { postedMaterials, archivedMaterials, materials };
}

test('mobile project deep link shows order details, materials and isolates project switches', async ({ page }) => {
  const mocked = await mockProjectOrderApp(page);

  await page.goto('/app/projektwahl?project=2');

  await expect(page.locator('#projectSelect')).toHaveValue('2');
  await expect(page.getByRole('heading', { name: 'P-002 – Baustelle Zwei' })).toBeVisible();
  await expect(page.getByText('Kunde Zwei')).toBeVisible();
  await expect(page.getByText('Bauweg 2, 54321 Potsdam')).toBeVisible();
  await expect(page.getByText('<script>nicht ausfuehren</script>')).toBeVisible();
  await expect(page.locator('main script')).toHaveCount(0);
  await expect(page.locator('.app-work-instructions br')).toHaveCount(1);
  await expect(page.locator('.app-attachment-info').filter({ hasText: 'auftrag.pdf' }).getByRole('link', { name: 'Oeffnen' }))
    .toHaveAttribute('href', '/api/v1/app/project-files/51/download');
  await expect(page.getByText('Sicherung', { exact: true }).first()).toBeVisible();
  await expect(page).toHaveURL('/app/projektwahl');

  await page.locator('#projectSelect').selectOption('1');

  await expect(page.getByRole('heading', { name: 'P-001 – Baustelle Eins' })).toBeVisible();
  await expect(page.getByText('Kupferrohr')).toBeVisible();
  await expect(page.getByText('Kunde Zwei')).toHaveCount(0);
  await expect(page.getByText('Sicherung', { exact: true })).toHaveCount(0);

  await page.locator('#projectMaterialDescription').fill('Dichtung');
  await page.locator('#projectMaterialQuantity').fill('3,5');
  await page.locator('#projectMaterialUnit').fill('Stueck');
  await page.locator('#projectMaterialNote').fill('Direkt verbaut');
  await page.locator('#projectMaterialForm').getByRole('button', { name: 'Material speichern' }).click();

  await expect.poll(() => mocked.postedMaterials.length).toBe(1);
  expect(mocked.postedMaterials[0]).toMatchObject({
    projectId: 1,
    payload: {
      description: 'Dichtung',
      quantity: '3,5',
      unit: 'Stueck',
      note: 'Direkt verbaut',
      work_date: '2026-07-23'
    }
  });
  await expect(page.getByText('Dichtung')).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('[data-archive-project-material="99"]').click();
  await expect.poll(() => mocked.archivedMaterials).toContain(99);
  await expect(page.getByText('Dichtung')).toHaveCount(0);
});

test('mobile project material draft survives offline submit and UI flags hide optional sections', async ({ page, context }) => {
  await mockProjectOrderApp(page);
  await page.goto('/app/projektwahl?project=1');
  await expect(page.getByText('Kupferrohr')).toBeVisible();
  await page.locator('#projectMaterialDescription').fill('Offline-Entwurf');
  await page.locator('#projectMaterialQuantity').fill('4');

  await context.setOffline(true);
  await page.locator('#projectMaterialForm').getByRole('button', { name: 'Material speichern' }).click();

  await expect(page.getByText('Material kann nur online gespeichert werden. Ihre Eingaben bleiben erhalten.')).toBeVisible();
  await expect(page.locator('#projectMaterialDescription')).toHaveValue('Offline-Entwurf');
  await expect(page.locator('#projectMaterialQuantity')).toHaveValue('4');
  await expect(page.getByText(/Offline – zuletzt synchronisierter Stand/)).toBeVisible();

  await context.setOffline(false);
  await page.unrouteAll({ behavior: 'wait' });
  await mockProjectOrderApp(page, {
    flags: {
      show_project_files: false,
      show_project_work_instructions: false,
      show_project_materials: false
    }
  });
  await page.reload();

  await expect(page.getByRole('heading', { name: 'Arbeitsanweisung' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Materialdokumentation' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Projektdateien' })).toHaveCount(0);
});

test('mobile project rejects unknown deep links and renders the empty instruction state', async ({ page }) => {
  await mockProjectOrderApp(page, {
    projects: [{
      id: 1,
      project_number: 'P-001',
      name: 'Baustelle ohne Text',
      customer_name: '',
      address_line_1: '',
      postal_code: '',
      city: '',
      work_instructions: null,
      work_instructions_updated_at: null
    }]
  });

  await page.goto('/app/projektwahl?project=999');

  await expect(page.getByText('Das angeforderte Projekt ist nicht verfuegbar oder fuer Sie nicht freigegeben.')).toBeVisible();
  await expect(page.locator('#projectSelect')).toHaveValue('');
  await expect(page).toHaveURL('/app/projektwahl');
  await expect(page.getByRole('heading', { name: 'P-001 – Baustelle ohne Text' })).toHaveCount(0);

  await page.locator('#projectSelect').selectOption('1');
  await expect(page.getByRole('heading', { name: 'P-001 – Baustelle ohne Text' })).toBeVisible();
  await expect(page.getByText('Fuer dieses Projekt wurde noch keine Arbeitsanweisung hinterlegt.')).toBeVisible();
});

test('mobile project never exposes revoked project data from preferred IndexedDB caches', async ({ page }) => {
  await page.goto('/app/login');
  await page.evaluate(async () => {
    localStorage.setItem('app.preferredProjectId', '2');
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open('zeiterfassung-app', 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains('cache')) {
          db.createObjectStore('cache', { keyPath: 'key' });
        }
        if (!db.objectStoreNames.contains('queue')) {
          const store = db.createObjectStore('queue', { keyPath: 'id' });
          store.createIndex('status', 'status', { unique: false });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const cachedAt = '2026-07-22T12:00:00.000Z';
    const records = [
      {
        key: 'project_order_v1_user_7_project_2',
        value: {
          item: {
            id: 2,
            project_number: 'ENTZOGEN',
            name: 'Entzogenes Projekt',
            customer_name: 'Vertraulicher Kunde',
            work_instructions: 'Vertrauliche Arbeitsanweisung'
          },
          cached_at: cachedAt
        },
        updatedAt: Date.now()
      },
      {
        key: 'project_files_user_7_2',
        value: {
          items: [{ id: 51, original_name: 'vertraulich.pdf', mime_type: 'application/pdf' }],
          cached_at: cachedAt
        },
        updatedAt: Date.now()
      },
      {
        key: 'project_materials_v1_user_7_project_2',
        value: {
          items: [{ id: 21, description: 'Vertrauliches Material', quantity: '1.000' }],
          cached_at: cachedAt
        },
        updatedAt: Date.now()
      }
    ];

    await new Promise((resolve, reject) => {
      const tx = database.transaction('cache', 'readwrite');
      records.forEach((record) => tx.objectStore('cache').put(record));
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    database.close();
  });

  await mockProjectOrderApp(page, {
    projects: [{
      id: 1,
      project_number: 'P-001',
      name: 'Weiterhin freigegeben',
      customer_name: 'Aktueller Kunde',
      address_line_1: '',
      postal_code: '',
      city: '',
      work_instructions: 'Aktuelle Arbeitsanweisung',
      work_instructions_updated_at: '2026-07-23 10:00:00'
    }]
  });
  await page.goto('/app/projektwahl');

  await expect(page.locator('#projectSelect')).toHaveValue('');
  await expect(page.getByText('Entzogenes Projekt')).toHaveCount(0);
  await expect(page.getByText('Vertrauliche Arbeitsanweisung')).toHaveCount(0);
  await expect(page.getByText('vertraulich.pdf')).toHaveCount(0);
  await expect(page.getByText('Vertrauliches Material')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('app.preferredProjectId'))).toBeNull();
  await expect.poll(() => page.evaluate(async () => {
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open('zeiterfassung-app', 1);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const keys = [
      'project_order_v1_user_7_project_2',
      'project_files_user_7_2',
      'project_materials_v1_user_7_project_2'
    ];
    const remaining = await new Promise((resolve, reject) => {
      const tx = database.transaction('cache', 'readonly');
      const requests = keys.map((key) => tx.objectStore('cache').get(key));
      tx.oncomplete = () => resolve(requests.filter((request) => request.result !== undefined).length);
      tx.onerror = () => reject(tx.error);
    });
    database.close();
    return remaining;
  })).toBe(0);
});

test('admin project order form counts instructions and blocks dispatch while dirty', async ({ page }) => {
  await page.setContent(
    '<form data-project-master-form>'
      + '<input type="hidden" name="csrf_token" value="token">'
      + '<input name="name" value="Projekt Nord">'
      + '<textarea id="work_instructions" name="work_instructions" maxlength="20000">Erste Zeile</textarea>'
      + '<span id="work-instructions-count"></span>'
      + '</form>'
      + '<form data-project-dispatch-form data-project-label="P-1 – Projekt Nord" data-recipient-count="3">'
      + '<div data-project-unsaved-notice hidden>Aenderungen zuerst speichern</div>'
      + '<button type="submit" data-project-dispatch-button>Auftrag an Mitarbeiter senden</button>'
      + '</form>'
  );
  await page.evaluate(() => {
    window.__dispatchConfirmation = '';
    window.confirm = (message) => {
      window.__dispatchConfirmation = message;
      return false;
    };
  });
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-projects.js') });

  await expect(page.locator('#work-instructions-count')).toHaveText('11');
  await page.locator('#work_instructions').fill('Erste Zeile\nZweite Zeile');
  await expect(page.locator('#work-instructions-count')).toHaveText('24');
  await expect(page.locator('[data-project-dispatch-button]')).toBeDisabled();
  await expect(page.locator('[data-project-unsaved-notice]')).toBeVisible();

  await page.locator('#work_instructions').fill('Erste Zeile');
  await expect(page.locator('[data-project-dispatch-button]')).toBeEnabled();
  await page.locator('[data-project-dispatch-button]').click();
  await expect.poll(() => page.evaluate(() => window.__dispatchConfirmation)).toContain('P-1 – Projekt Nord');
  await expect.poll(() => page.evaluate(() => window.__dispatchConfirmation)).toContain('3 Mitarbeiter');
  await expect.poll(() => page.evaluate(() => window.__dispatchConfirmation)).toContain('gespeicherten Projektstand');
});

test('mobile app login screen loads', async ({ page }) => {
  await page.goto('/app/login');

  await expect(page.locator('#loginForm')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
});

test('mobile app shows personal labels and events when enabled', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-06-26',
          server_time: '2026-06-26T10:00:00+02:00',
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_personnel_overview: true
            }
          },
          app_ui_settings: {
            show_personnel_overview: true
          },
          mandatory_app_widgets: ['day_status', 'start_time', 'end_time', 'breaks', 'current_net_minutes', 'current_project', 'time_actions'],
          projects: [],
          attachments: [],
          today_state: {
            status: 'not_started',
            is_missing: false,
            work_entry: null,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [],
          company: {},
          geo_policy: {},
          personnel_events: [
            {
              id: 1,
              title: 'Modul 1',
              event_type: 'Fuehrerschein',
              due_on: '2026-07-10',
              valid_until: '2027-07-10',
              status: 'due_soon',
              status_label: 'Bald faellig',
              days_until_due: 14
            }
          ],
          personnel_labels: [
            {
              id: 2,
              name: 'LKW-Fahrer',
              color: '#2563eb',
              icon: 'truck',
              description: 'Darf LKW fahren'
            }
          ]
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/personal');

  await expect(page.locator('a[href="/app/personal"]').first()).toHaveText('Personal');
  await expect(page.getByRole('heading', { name: 'Labels und Events' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Naechstes faelliges Event' })).toBeVisible();
  await expect(page.getByText('Modul 1').first()).toBeVisible();
  await expect(page.getByText('Bald faellig').first()).toBeVisible();
  await expect(page.getByText('LKW-Fahrer').first()).toBeVisible();
  await expect(page.getByText('Darf LKW fahren').first()).toBeVisible();

  await page.goto('/app/profil');

  await expect(page.getByRole('heading', { name: 'Einstellungen und Firma' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Meine Termine' })).toHaveCount(0);
});

test('mobile app hides personal navigation when disabled', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-06-26',
          server_time: '2026-06-26T10:00:00+02:00',
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_personnel_overview: false
            }
          },
          app_ui_settings: {
            show_personnel_overview: false
          },
          mandatory_app_widgets: ['day_status', 'start_time', 'end_time', 'breaks', 'current_net_minutes', 'current_project', 'time_actions'],
          projects: [],
          attachments: [],
          today_state: {
            status: 'not_started',
            is_missing: false,
            work_entry: null,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [],
          company: {},
          geo_policy: {},
          personnel_events: [],
          personnel_labels: []
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/personal');

  await expect(page.locator('a[href="/app/personal"]')).toHaveCount(0);
  await expect(page.getByText('Dieser Bereich ist fuer Ihr App-Anzeigeprofil ausgeblendet.')).toBeVisible();
});

test('expired mobile session returns to login and keeps pending queue', async ({ page }) => {
  await page.addInitScript(async () => {
    await new Promise((resolve, reject) => {
      const request = indexedDB.open('zeiterfassung-app', 1);

      request.onupgradeneeded = () => {
        const database = request.result;

        if (!database.objectStoreNames.contains('cache')) {
          database.createObjectStore('cache', { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains('queue')) {
          const store = database.createObjectStore('queue', { keyPath: 'id' });
          store.createIndex('status', 'status', { unique: false });
        }
      };

      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        const tx = database.transaction(['cache', 'queue'], 'readwrite');

        tx.objectStore('cache').put({
          key: 'session',
          value: {
            authenticated: true,
            bootstrap_required: false,
            user: {
              id: 7,
              display_name: 'Max Mustermann',
              permissions: ['timesheets.create', 'timesheets.view_own']
            }
          },
          updatedAt: Date.now()
        });
        tx.objectStore('queue').put({
          id: 'queued-entry',
          client_request_id: 'queued-entry',
          endpoint: '/api/v1/app/timesheets/sync',
          payload: { action: 'check_in', work_date: '2026-05-16' },
          status: 'pending',
          createdAt: Date.now()
        });

        tx.oncomplete = () => {
          database.close();
          resolve();
        };
        tx.onerror = () => reject(tx.error);
      };
    });
  });

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: false,
        bootstrap_required: false,
        user: null
      })
    });
  });

  await page.goto('/app/heute');

  await expect(page.locator('#loginForm')).toBeVisible();
  await expect(page.getByText('Ihre Sitzung ist abgelaufen. Bitte erneut anmelden. Wartende Buchungen bleiben erhalten.')).toBeVisible();

  const queueCount = await page.evaluate(async () => new Promise((resolve, reject) => {
    const request = indexedDB.open('zeiterfassung-app', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const tx = database.transaction('queue', 'readonly');
      const getAll = tx.objectStore('queue').getAll();

      getAll.onsuccess = () => resolve(getAll.result.length);
      getAll.onerror = () => reject(getAll.error);
    };
  }));

  expect(queueCount).toBe(1);
});

test('mobile today screen shows derived missing status', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: []
          },
          today_state: {
            work_entry: null,
            status_entry: null,
            current_break: null,
            status: 'missing',
            is_missing: true,
            status_source: 'derived_missing'
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          attachments: [],
          project_day_summaries: [],
          projects: [],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.getByRole('heading', { name: 'Fehlt' })).toBeVisible();
  await expect(page.locator('main [data-live-status]')).toHaveText('Fehlt / nicht gebucht');
  await expect(page.getByText('Fuer heute liegt noch keine Buchung vor. Diese Meldung ist automatisch abgeleitet.')).toBeVisible();
  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Fehlt / nicht gebucht');
  await expect(page.locator('[data-live-topbar-project]')).toHaveText('automatisch erkannt');

  await page.goto('/app/profil');

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Fehlt / nicht gebucht');
});

test('mobile today screen keeps a scheduled free day neutral', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          time_tracking_required: true,
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-11',
          server_time: '2026-05-11T10:00:00+02:00',
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            time_tracking_required: true,
            roles: []
          },
          today_state: {
            work_entry: null,
            status_entry: null,
            current_break: null,
            status: 'not_started',
            is_missing: false,
            booking_required: false,
            status_source: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          attachments: [],
          project_day_summaries: [],
          projects: [],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.getByRole('heading', { name: 'Noch nicht gebucht' })).toBeVisible();
  await expect(page.locator('main [data-live-status]')).toHaveText('Nicht gestartet');
  await expect(page.getByText('Zeiterfassung ist freiwillig. Sie koennen bei Bedarf einchecken.')).toBeVisible();
  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Noch nicht gebucht');
  await expect(page.locator('[data-live-topbar-project]')).toHaveText('freiwillig');
  await expect(page.locator('[data-live-topbar-detail]')).toHaveText('Keine Buchung erforderlich');
});

test('mobile app ignores legacy today cache without booking requirement', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          time_tracking_required: true,
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Offline' })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');
  await page.evaluate(async () => new Promise((resolve, reject) => {
    const request = indexedDB.open('zeiterfassung-app', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const tx = database.transaction('cache', 'readwrite');
      tx.objectStore('cache').put({
        key: 'today_user_7',
        updatedAt: Date.now(),
        value: {
          today: '2026-05-11',
          user: {
            id: 7,
            time_tracking_required: true
          },
          today_state: {
            work_entry: null,
            status_entry: null,
            status: 'missing',
            is_missing: true,
            status_source: 'derived_missing'
          },
          project_day_summaries: [],
          projects: []
        }
      });
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    };
  }));

  await page.reload();

  await expect(page.getByRole('heading', { name: 'Fehlt' })).toHaveCount(0);
  await expect(page.locator('[data-live-topbar-status]')).not.toHaveText('Fehlt / nicht gebucht');
  await expect(page.getByText('Fuer heute liegt noch keine Buchung vor. Diese Meldung ist automatisch abgeleitet.')).toHaveCount(0);
});

test('mobile topbar shows active work status on every app page', async ({ page }) => {
  const projectName = 'Neubau Erweiterung Nordfluegel mit sehr langem Projektnamen';
  const workEntry = {
    id: 12,
    project_id: 2,
    project_name: projectName,
    work_date: '2026-05-15',
    start_time: '07:15:00',
    end_time: null,
    break_minutes: 0,
    net_minutes: 0,
    note: null,
    attachments: []
  };
  const liveBasis = {
    work_started_at: '2026-05-15T07:15:00+02:00',
    work_ended_at: null,
    completed_break_minutes: 0,
    current_break_started_at: null,
    is_running: true,
    is_paused: false
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          projects: [
            { id: 2, project_number: 'P-002', name: projectName, city: 'Berlin' }
          ],
          attachments: [],
          today_state: {
            status: 'working',
            is_missing: false,
            status_source: null,
            work_entry: workEntry,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: liveBasis,
          project_day_summaries: [
            {
              project_id: 2,
              project_name: projectName,
              status: 'working',
              start_time: '07:15:00',
              end_time: null,
              total_break_minutes: 0,
              total_net_minutes: 0,
              current_break: null,
              tracked_minutes_live_basis: liveBasis,
              work_entry: workEntry,
              breaks_today: [],
              attachments: []
            }
          ],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Eingecheckt');
  await expect(page.locator('[data-live-topbar-project]')).toContainText(projectName);
  await expect(page.locator('[data-live-topbar-detail]')).toHaveText('Start 07:15');

  await page.goto('/app/zeiten');

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Eingecheckt');
  await expect(page.locator('[data-live-topbar-project]')).toContainText(projectName);

  await page.goto('/app/profil');

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Eingecheckt');
  await expect(page.locator('[data-live-topbar-project]')).toContainText(projectName);
});

test('mobile today screen separates total day time from selected project time', async ({ page }) => {
  const projects = [
    { id: 1, project_number: 'P-001', name: 'Baustelle A', city: 'Berlin' },
    { id: 2, project_number: 'P-002', name: 'Baustelle B', city: 'Potsdam' }
  ];
  const latestEntry = {
    id: 22,
    project_id: 2,
    project_name: 'Baustelle B',
    work_date: '2026-05-15',
    start_time: '12:30:00',
    end_time: '14:00:00',
    break_minutes: 0,
    net_minutes: 90,
    note: null,
    attachments: []
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T14:05:00+02:00',
          projects,
          attachments: [],
          today_state: {
            status: 'completed',
            is_missing: false,
            status_source: null,
            work_entry: latestEntry,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [
            {
              project_id: 2,
              project_name: 'Baustelle B',
              status: 'completed',
              start_time: '12:30:00',
              end_time: '14:00:00',
              total_break_minutes: 0,
              total_net_minutes: 90,
              historical_total_net_minutes: 7200,
              current_break: null,
              tracked_minutes_live_basis: null,
              work_entry: latestEntry,
              breaks_today: [],
              attachments: []
            },
            {
              project_id: 1,
              project_name: 'Baustelle A',
              status: 'completed',
              start_time: '08:00:00',
              end_time: '10:00:00',
              total_break_minutes: 0,
              total_net_minutes: 120,
              historical_total_net_minutes: 7200,
              current_break: null,
              tracked_minutes_live_basis: null,
              work_entry: {
                id: 21,
                project_id: 1,
                project_name: 'Baustelle A',
                work_date: '2026-05-15',
                start_time: '08:00:00',
                end_time: '10:00:00',
                break_minutes: 0,
                net_minutes: 120,
                note: null,
                attachments: []
              },
              breaks_today: [],
              attachments: []
            }
          ],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.locator('main [data-live-today-duration]')).toHaveText('03:30');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('01:30');
  await expect(page.getByText('120:00')).toHaveCount(0);

  await page.locator('#projectSelect').selectOption('1');

  await expect(page.locator('main [data-live-today-duration]')).toHaveText('03:30');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('02:00');
  await expect(page.locator('main strong[data-live-project-name]')).toHaveText('Baustelle A');
});

test('mobile app display settings hide optional day widgets but keep mandatory status values', async ({ page }) => {
  const latestEntry = {
    id: 22,
    project_id: 2,
    project_name: 'Baustelle B',
    work_date: '2026-05-15',
    start_time: '12:30:00',
    end_time: '14:00:00',
    break_minutes: 0,
    net_minutes: 90,
    note: null,
    attachments: []
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T14:05:00+02:00',
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_today_total_minutes: false,
              show_project_today_minutes: false,
              show_history: false
            }
          },
          app_ui_settings: {
            show_today_total_minutes: false,
            show_project_today_minutes: false,
            show_history: false
          },
          mandatory_app_widgets: ['day_status', 'start_time', 'end_time', 'breaks', 'current_net_minutes', 'current_project', 'time_actions'],
          projects: [
            { id: 2, project_number: 'P-002', name: 'Baustelle B', city: 'Potsdam' }
          ],
          attachments: [],
          today_state: {
            status: 'completed',
            is_missing: false,
            status_source: null,
            work_entry: latestEntry,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [
            {
              project_id: 2,
              project_name: 'Baustelle B',
              status: 'completed',
              start_time: '12:30:00',
              end_time: '14:00:00',
              total_break_minutes: 0,
              total_net_minutes: 90,
              current_break: null,
              tracked_minutes_live_basis: null,
              work_entry: latestEntry,
              breaks_today: [],
              attachments: []
            }
          ],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.getByText('Heute gesamt')).toHaveCount(0);
  await expect(page.getByText('Heute aktuelles Projekt')).toHaveCount(0);
  await expect(page.locator('main [data-live-start-time]')).toHaveText('12:30');
  await expect(page.locator('main [data-live-end-time]')).toHaveText('14:00');
  await expect(page.locator('main [data-live-work-duration]')).toHaveText('01:30');
  await expect(page.locator('main strong[data-live-project-name]')).toHaveText('Baustelle B');
  await expect(page.getByRole('button', { name: 'Check-in' })).toBeVisible();
  await expect(page.locator('nav.app-nav a[href="/app/historie"]')).toHaveCount(0);
});

test('mobile history hides timesheet file counters when timesheet files are disabled', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('app.timesheetFilterMonth', '2026-05');
  });

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          app_ui_settings: {
            show_history: true,
            show_timesheet_files: false
          },
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_history: true,
              show_timesheet_files: false
            }
          },
          projects: [],
          attachments: [],
          today_state: {
            status: 'not_started',
            work_entry: null,
            active_break: null
          },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/me/timesheets**', async (route) => {
    const item = {
      id: 10,
      project_id: null,
      project_name: 'Nicht zugeordnet',
      work_date: '2026-05-14',
      date_label: '14.05.2026',
      weekday: 'Donnerstag',
      start_time: '07:30:00',
      end_time: '16:00:00',
      break_minutes: 30,
      net_minutes: 480,
      entry_type: 'work',
      note: 'Gesamtansicht',
      breaks: [],
      attachments: [
        {
          id: 5,
          original_name: 'stundenzettel.pdf',
          mime_type: 'application/pdf',
          size_bytes: 12000,
          is_image: false,
          download_url: '/api/v1/app/timesheet-files/5/download',
          preview_url: null
        }
      ],
      attachment_count: 1,
      geo_records: [],
      geo_count: 0
    };

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          items: [item],
          summary: {
            total_net_minutes: 480,
            total_break_minutes: 30,
            entry_count: 1,
            work_entry_count: 1,
            absence_entry_count: 0,
            attachment_count: 1,
            project_count: 1
          },
          days: [
            {
              date: '2026-05-14',
              date_label: '14.05.2026',
              weekday: 'Donnerstag',
              total_net_minutes: 480,
              total_break_minutes: 30,
              entry_count: 1,
              status_counts: { work: 1, sick: 0, vacation: 0, holiday: 0, absent: 0 },
              attachment_count: 1,
              items: [item]
            }
          ],
          projects: [],
          filters: {
            scope: 'all',
            project_id: null,
            month: '2026-05',
            entry_type: 'all'
          },
          scope: 'all',
          project_id: null,
          cached_at: '2026-05-15 10:00:00'
        }
      })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/historie');

  await expect(page.getByRole('heading', { name: 'Monatshistorie' })).toBeVisible();
  await expect(page.locator('.app-history-summary')).not.toContainText('Dateien');
  await expect(page.locator('.app-history-day > summary').first()).not.toContainText('Dateien');
  await expect(page.getByText('Anhaenge anzeigen')).toHaveCount(0);
  await expect(page.getByText('stundenzettel.pdf')).toHaveCount(0);
});

test('mobile today totals count unassigned running work live', async ({ page, context }) => {
  await page.addInitScript(() => {
    const RealDate = Date;
    let mockedNow = new RealDate('2026-05-15T10:00:00+02:00').getTime();

    class MockDate extends RealDate {
      constructor(...args) {
        if (args.length === 0) {
          super(mockedNow);
          return;
        }

        super(...args);
      }

      static now() {
        return mockedNow;
      }

      static parse(value) {
        return RealDate.parse(value);
      }

      static UTC(...args) {
        return RealDate.UTC(...args);
      }
    }

    window.Date = MockDate;
    window.__setMockNow = (value) => {
      mockedNow = new RealDate(value).getTime();
    };
  });

  const workEntry = {
    id: 31,
    project_id: null,
    project_name: 'Nicht zugeordnet',
    work_date: '2026-05-15',
    start_time: '09:45:00',
    end_time: null,
    break_minutes: 0,
    net_minutes: 0,
    note: null,
    attachments: []
  };
  const liveBasis = {
    work_started_at: '2026-05-15T09:45:00+02:00',
    work_ended_at: null,
    completed_break_minutes: 0,
    current_break_started_at: null,
    is_running: true,
    is_paused: false
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          projects: [
            { id: 1, project_number: 'P-001', name: 'Baustelle A', city: 'Berlin' }
          ],
          attachments: [],
          today_state: {
            status: 'working',
            is_missing: false,
            status_source: null,
            work_entry: workEntry,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: liveBasis,
          project_day_summaries: [
            {
              project_id: null,
              project_name: 'Nicht zugeordnet',
              status: 'working',
              start_time: '09:45:00',
              end_time: null,
              total_break_minutes: 0,
              total_net_minutes: 0,
              current_break: null,
              tracked_minutes_live_basis: liveBasis,
              work_entry: workEntry,
              breaks_today: [],
              attachments: []
            }
          ],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main strong[data-live-project-name]')).toHaveText('Nicht zugeordnet');

  await page.evaluate(() => window.__setMockNow('2026-05-15T10:02:00+02:00'));

  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:17');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:17');

  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await page.evaluate(() => {
    window.history.pushState({}, '', '/app/projektwahl');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.getByRole('heading', { name: 'Baustelle waehlen' })).toBeVisible();
  await page.locator('#projectSelect').selectOption('1');
  await page.getByRole('button', { name: 'Laufenden Einsatz zuordnen' }).click();

  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:17');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:17');
  await expect(page.locator('main strong[data-live-project-name]')).toHaveText('Baustelle A');
});

test('mobile today totals keep manual pause and offline checkout duration', async ({ page, context }) => {
  await page.addInitScript(() => {
    const RealDate = Date;
    const mockedNow = new RealDate('2026-05-15T10:00:00+02:00').getTime();

    class MockDate extends RealDate {
      constructor(...args) {
        if (args.length === 0) {
          super(mockedNow);
          return;
        }

        super(...args);
      }

      static now() {
        return mockedNow;
      }

      static parse(value) {
        return RealDate.parse(value);
      }

      static UTC(...args) {
        return RealDate.UTC(...args);
      }
    }

    window.Date = MockDate;
  });

  const project = { id: 1, project_number: 'P-001', name: 'Baustelle A', city: 'Berlin' };
  const workEntry = {
    id: 41,
    project_id: 1,
    project_name: project.name,
    work_date: '2026-05-15',
    start_time: '09:00:00',
    end_time: null,
    break_minutes: 0,
    net_minutes: 0,
    note: null,
    attachments: []
  };
  const liveBasis = {
    work_started_at: '2026-05-15T09:00:00+02:00',
    work_ended_at: null,
    completed_break_minutes: 0,
    current_break_started_at: null,
    is_running: true,
    is_paused: false
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          projects: [project],
          attachments: [],
          today_state: {
            status: 'working',
            is_missing: false,
            status_source: null,
            work_entry: workEntry,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: liveBasis,
          project_day_summaries: [
            {
              project_id: 1,
              project_name: project.name,
              status: 'working',
              start_time: '09:00:00',
              end_time: null,
              total_break_minutes: workEntry.break_minutes,
              total_net_minutes: workEntry.net_minutes,
              current_break: null,
              tracked_minutes_live_basis: liveBasis,
              work_entry: workEntry,
              breaks_today: [],
              attachments: []
            }
          ],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');
  await expect(page.getByRole('button', { name: 'Pause buchen' })).toBeVisible();
  await expect(page.locator('main [data-live-today-duration]')).toHaveText('01:00');
  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));

  await page.getByRole('button', { name: 'Pause buchen' }).click();
  await page.getByRole('button', { name: '45 Minuten' }).click();

  const queuedPausePayload = await page.evaluate(async () => new Promise((resolve, reject) => {
    const request = indexedDB.open('zeiterfassung-app', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const transaction = database.transaction('queue', 'readonly');
      const getAll = transaction.objectStore('queue').getAll();

      getAll.onerror = () => reject(getAll.error);
      getAll.onsuccess = () => {
        const pauseEntry = getAll.result.find((entry) => entry.payload && entry.payload.action === 'pause');

        database.close();
        resolve(pauseEntry ? pauseEntry.payload : null);
      };
    };
  }));

  expect(queuedPausePayload).toMatchObject({
    action: 'pause',
    manual_break_minutes: 45
  });

  await expect(page.locator('main [data-live-work-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-today-break-total]')).toHaveText('00:45');

  await page.getByRole('button', { name: 'Check-out' }).click();

  await expect(page.locator('main [data-live-end-time]')).toHaveText('10:00');
  await expect(page.locator('main [data-live-work-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:15');

  await page.evaluate(() => {
    window.history.pushState({}, '', '/app/zeiten');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await page.locator('#manualStartTime').fill('10:00');
  await page.locator('#manualEndTime').fill('10:00');
  await page.getByRole('button', { name: 'Zeiten speichern' }).click();

  await expect(page.locator('main [data-live-work-duration]').first()).toHaveText('00:00');
  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:00');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:00');

  await page.evaluate(async () => new Promise((resolve, reject) => {
    const request = indexedDB.open('zeiterfassung-app', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const transaction = database.transaction('queue', 'readwrite');

      transaction.objectStore('queue').clear();
      transaction.oncomplete = () => {
        database.close();
        resolve();
      };
      transaction.onerror = () => reject(transaction.error);
    };
  }));

  workEntry.start_time = '09:00:00';
  workEntry.end_time = null;
  workEntry.break_minutes = 45;
  workEntry.net_minutes = 0;
  liveBasis.completed_break_minutes = 45;
  await context.setOffline(false);
  await page.goto('/app/heute');

  await expect(page.locator('main [data-live-work-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-project-today-duration]')).toHaveText('00:15');
  await expect(page.locator('main [data-live-today-break-total]')).toHaveText('00:45');
});

test('mobile app does not send geo when geo section is hidden', async ({ page }) => {
  const syncPayloads = [];

  await page.addInitScript(() => {
    window.localStorage.setItem('app.geoAck', '1');

    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: {
        getCurrentPosition(success) {
          success({
            coords: {
              latitude: 52.520008,
              longitude: 13.404954,
              accuracy: 12
            },
            timestamp: Date.parse('2026-05-15T10:00:00+02:00')
          });
        }
      }
    });
  });

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          app_ui_settings: {
            show_geo_section: false
          },
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_geo_section: false
            }
          },
          projects: [],
          attachments: [],
          today_state: {
            status: 'not_started',
            is_missing: false,
            status_source: null,
            work_entry: null,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: true,
            notice_text: 'GEO ist aktiv.',
            requires_acknowledgement: true
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.route('**/api/v1/app/timesheets/sync', async (route) => {
    syncPayloads.push(route.request().postDataJSON());
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        message: 'Gespeichert.',
        data: {
          today_state: {
            status: 'working',
            work_entry: {
              id: 50,
              project_id: null,
              project_name: 'Nicht zugeordnet',
              work_date: '2026-05-15',
              start_time: '10:00:00',
              end_time: null,
              break_minutes: 0,
              net_minutes: 0,
              note: null,
              attachments: []
            },
            current_break: null
          },
          breaks_today: [],
          current_break: null,
          tracked_minutes_live_basis: {
            work_started_at: '2026-05-15T10:00:00+02:00',
            work_ended_at: null,
            completed_break_minutes: 0,
            current_break_started_at: null,
            is_running: true,
            is_paused: false
          }
        }
      })
    });
  });

  await page.goto('/app/heute');

  await expect(page.getByText('GEO ist aktiv.')).toHaveCount(0);
  await page.getByRole('button', { name: 'Check-in' }).click();
  await page.locator('#projectlessDialogNote').fill('Kurztest ohne Projekt');
  await page.getByRole('button', { name: 'Ohne Projekt starten' }).click();

  await expect.poll(() => syncPayloads.length).toBe(1);
  expect(syncPayloads[0]).not.toHaveProperty('geo');
  expect(syncPayloads[0]).not.toHaveProperty('geo_acknowledged');
});

test('mobile app strips queued geo after geo section is disabled before reconnect sync', async ({ page }) => {
  const syncPayloads = [];

  await page.addInitScript(async () => {
    await new Promise((resolve, reject) => {
      const request = indexedDB.open('zeiterfassung-app', 1);

      request.onupgradeneeded = () => {
        const database = request.result;

        if (!database.objectStoreNames.contains('cache')) {
          database.createObjectStore('cache', { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains('queue')) {
          const store = database.createObjectStore('queue', { keyPath: 'id' });
          store.createIndex('status', 'status', { unique: false });
        }
      };

      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        const tx = database.transaction(['cache', 'queue'], 'readwrite');

        tx.objectStore('cache').put({
          key: 'session',
          value: {
            authenticated: true,
            bootstrap_required: false,
            user: {
              id: 7,
              display_name: 'Max Mustermann',
              permissions: ['timesheets.create', 'timesheets.view_own']
            }
          },
          updatedAt: Date.now()
        });
        tx.objectStore('queue').put({
          id: 'queued-geo-entry',
          client_request_id: 'queued-geo-entry',
          endpoint: '/api/v1/app/timesheets/sync',
          payload: {
            action: 'check_in',
            work_date: '2026-05-15',
            start_time: '10:00',
            project_id: null,
            note: 'queued geo',
            geo: {
              latitude: 52.520008,
              longitude: 13.404954,
              accuracy_meters: 12,
              recorded_at: '2026-05-15T10:00:00+02:00'
            },
            geo_acknowledged: true
          },
          status: 'pending',
          createdAt: Date.now()
        });

        tx.oncomplete = () => {
          database.close();
          resolve();
        };
        tx.onerror = () => reject(tx.error);
      };
    });
  });

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          server_time: '2026-05-15T10:00:00+02:00',
          app_ui_settings: {
            show_geo_section: false
          },
          user: {
            id: 7,
            display_name: 'Max Mustermann',
            email: 'max@example.test',
            roles: [],
            app_ui_settings: {
              show_geo_section: false
            }
          },
          projects: [],
          attachments: [],
          today_state: {
            status: 'not_started',
            is_missing: false,
            status_source: null,
            work_entry: null,
            status_entry: null,
            current_break: null
          },
          current_break: null,
          breaks_today: [],
          tracked_minutes_live_basis: null,
          project_day_summaries: [],
          sync: { server_pending_count: 0 },
          geo_policy: {
            enabled: true,
            notice_text: 'GEO ist aktiv.',
            requires_acknowledgement: true
          },
          company: {
            app_display_name: 'TimeApp',
            company_name: 'Muster Bau'
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.route('**/api/v1/app/timesheets/sync', async (route) => {
    syncPayloads.push(route.request().postDataJSON());
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        message: 'Gespeichert.',
        data: {
          today_state: {
            status: 'working',
            work_entry: {
              id: 51,
              project_id: null,
              project_name: 'Nicht zugeordnet',
              work_date: '2026-05-15',
              start_time: '10:00:00',
              end_time: null,
              break_minutes: 0,
              net_minutes: 0,
              note: 'queued geo',
              attachments: []
            },
            current_break: null
          },
          breaks_today: [],
          current_break: null
        }
      })
    });
  });

  await page.goto('/app/heute');
  await page.evaluate(() => window.dispatchEvent(new Event('online')));

  await expect.poll(() => syncPayloads.length).toBe(1);
  expect(syncPayloads[0]).not.toHaveProperty('geo');
  expect(syncPayloads[0]).not.toHaveProperty('geo_acknowledged');
});

test('mobile topbar updates after check-in without a page reload', async ({ page }) => {
  let checkedIn = false;
  const project = { id: 2, project_number: 'P-002', name: 'Baustelle Mitte', city: 'Berlin' };

  const dayPayload = () => {
    const workEntry = checkedIn ? {
      id: 99,
      project_id: 2,
      project_name: project.name,
      work_date: '2026-05-15',
      start_time: '10:00:00',
      end_time: null,
      break_minutes: 0,
      net_minutes: 0,
      note: '',
      attachments: []
    } : null;
    const liveBasis = checkedIn ? {
      work_started_at: '2026-05-15T10:00:00+02:00',
      work_ended_at: null,
      completed_break_minutes: 0,
      current_break_started_at: null,
      is_running: true,
      is_paused: false
    } : null;

    return {
      today: '2026-05-15',
      server_time: '2026-05-15T10:01:00+02:00',
      projects: [project],
      attachments: [],
      today_state: {
        status: checkedIn ? 'working' : 'missing',
        is_missing: !checkedIn,
        status_source: checkedIn ? null : 'derived_missing',
        work_entry: workEntry,
        status_entry: null,
        current_break: null
      },
      current_break: null,
      breaks_today: [],
      tracked_minutes_live_basis: liveBasis,
      project_day_summaries: checkedIn ? [
        {
          project_id: 2,
          project_name: project.name,
          status: 'working',
          start_time: '10:00:00',
          end_time: null,
          total_break_minutes: 0,
          total_net_minutes: 0,
          current_break: null,
          tracked_minutes_live_basis: liveBasis,
          work_entry: workEntry,
          breaks_today: [],
          attachments: []
        }
      ] : [],
      sync: { server_pending_count: 0 },
      geo_policy: {
        enabled: false,
        notice_text: '',
        requires_acknowledgement: false
      },
      company: {
        app_display_name: 'TimeApp',
        company_name: 'Muster Bau'
      }
    };
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['timesheets.create', 'timesheets.view_own']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: dayPayload() })
    });
  });

  await page.route('**/api/v1/app/timesheets/sync', async (route) => {
    checkedIn = true;
    const data = dayPayload();

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        message: 'Aenderung gespeichert.',
        data: {
          today_state: data.today_state,
          timesheet: data.today_state.work_entry,
          breaks_today: [],
          current_break: null,
          tracked_minutes_live_basis: data.tracked_minutes_live_basis,
          server_time: data.server_time
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { enabled: false, can_subscribe: false, devices: [] } })
    });
  });

  await page.goto('/app/heute');

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Fehlt / nicht gebucht');
  await page.locator('#projectSelect').selectOption('2');
  await page.getByRole('button', { name: 'Check-in' }).click();

  await expect(page.locator('[data-live-topbar-status]')).toHaveText('Eingecheckt');
  await expect(page.locator('[data-live-topbar-project]')).toHaveText('Baustelle Mitte');
  await expect(page.locator('[data-live-topbar-detail]')).toHaveText('Start 10:00');
});

test('mobile profile shows company, legal texts and geo policy', async ({ page }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['projects.view', 'files.view', 'files.upload']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          projects: [],
          nfc_tags: [
            { uid_masked: '04:AA:...:99', label: 'Buerotag Max', status: 'active' },
            { uid_masked: '04:BB:...:77', label: '', status: 'pending' }
          ],
          attachments: [],
          today_state: {
            status: 'not_started',
            work_entry: null,
            active_break: null
          },
          geo_policy: null
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          app_display_name: 'Baustellen App',
          company_name: 'Muster Bau',
          legal_form: 'GmbH',
          street: 'Musterstrasse',
          house_number: '12',
          postal_code: '12345',
          city: 'Berlin',
          country: 'Deutschland',
          email: 'info@example.test',
          phone: '+49 30 123456',
          website: 'https://example.test',
          managing_director: 'Maria Muster',
          register_court: 'Amtsgericht Berlin',
          commercial_register: 'HRB 123',
          vat_id: 'DE123456789',
          tax_number: '12/345/67890',
          agb_text: 'AGB Zeile 1\nAGB Zeile 2',
          datenschutz_text: 'Datenschutz Zeile 1\nDatenschutz Zeile 2',
          geo_capture_enabled: true,
          geo_notice_text: 'GEO Hinweis aus den Firmeneinstellungen.',
          geo_requires_acknowledgement: true
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/timesheets**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          items: [],
          scope: 'project',
          project_id: null,
          cached_at: '2026-05-15 10:00:00'
        }
      })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          enabled: true,
          can_subscribe: true,
          permission_required: false,
          vapid_configured: true,
          vapid_public_key: 'BMockPublicKey',
          reminder_time: '09:00',
          notice_text: 'Bitte buchen Sie rechtzeitig.',
          devices: []
        }
      })
    });
  });

  await page.goto('/app/profil');

  await expect(page.getByRole('heading', { name: 'Einstellungen und Firma' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Ihre zugeordneten Tags' })).toBeVisible();
  await expect(page.getByText('Buerotag Max')).toBeVisible();
  await expect(page.getByText('04:AA:...:99 · Aktiv')).toBeVisible();
  await expect(page.getByText('04:BB:...:77 · Konfiguration erforderlich')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Muster Bau GmbH' })).toBeVisible();
  await expect(page.locator('.app-info-list').first()).toContainText('Musterstrasse 12');
  await expect(page.locator('.app-info-list').first()).toContainText('info@example.test');
  await expect(page.getByRole('heading', { name: 'AGB' })).toBeVisible();
  await page.getByText('AGB anzeigen').click();
  await expect(page.locator('.app-legal-text').first()).toContainText('AGB Zeile 2');
  await expect(page.getByRole('heading', { name: 'Datenschutz' })).toBeVisible();
  await page.getByText('Datenschutz anzeigen').click();
  await expect(page.locator('.app-legal-text').nth(1)).toContainText('Datenschutz Zeile 2');
  await expect(page.getByText('GEO Hinweis aus den Firmeneinstellungen.')).toBeVisible();
  await expect(page.locator('#geoAckSelect')).toBeVisible();
  await page.locator('#geoAckSelect').selectOption('1');
  await expect(page.getByText('GEO-Zustimmung wurde lokal gespeichert.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Benachrichtigungen' })).toBeVisible();
  await expect(page.getByText('Bitte buchen Sie rechtzeitig.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Installation' })).toBeVisible();
});

test('mobile history is its own area with project filter', async ({ page }) => {
  const timesheetRequests = [];

  await page.addInitScript(() => {
    window.localStorage.setItem('app.timesheetFilterMonth', '2026-05');
  });

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test',
          permissions: ['projects.view', 'files.view', 'files.upload']
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          projects: [
            { id: 2, project_number: 'P-002', name: 'Baustelle Mitte', city: 'Berlin' }
          ],
          attachments: [],
          today_state: {
            status: 'not_started',
            work_entry: null,
            active_break: null
          },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/me/timesheets**', async (route) => {
    const url = new URL(route.request().url());
    timesheetRequests.push(url.search);
    const scope = url.searchParams.get('scope') || 'all';
    const projectId = url.searchParams.get('project_id');
    const entryType = url.searchParams.get('entry_type') || 'all';
    const note = entryType === 'vacation'
      ? 'Urlaubsansicht'
      : (scope === 'all' ? 'Gesamtansicht' : 'Projektansicht');
    const item = {
      id: projectId === '2' ? 20 : 10,
      project_id: projectId === '2' ? 2 : null,
      project_name: projectId === '2' ? 'Baustelle Mitte' : 'Nicht zugeordnet',
      work_date: '2026-05-14',
      date_label: '14.05.2026',
      weekday: 'Donnerstag',
      start_time: entryType === 'vacation' ? null : '07:30:00',
      end_time: entryType === 'vacation' ? null : '16:00:00',
      break_minutes: entryType === 'vacation' ? 0 : 30,
      net_minutes: entryType === 'vacation' ? 0 : 480,
      entry_type: entryType === 'vacation' ? 'vacation' : 'work',
      entry_type_label: entryType === 'vacation' ? 'Urlaub' : 'Arbeit',
      note,
      breaks: entryType === 'vacation' ? [] : [
        {
          id: 1,
          break_started_at: '2026-05-14T12:00:00+02:00',
          break_ended_at: '2026-05-14T12:30:00+02:00',
          source: 'app',
          note: 'Mittag'
        }
      ],
      attachments: entryType === 'vacation' ? [] : [
        {
          id: 5,
          original_name: 'stundenzettel.pdf',
          mime_type: 'application/pdf',
          size_bytes: 12000,
          is_image: false,
          download_url: '/api/v1/app/timesheet-files/5/download',
          preview_url: null
        }
      ],
      attachment_count: entryType === 'vacation' ? 0 : 1,
      geo_records: entryType === 'vacation' ? [] : [
        {
          id: 3,
          latitude: 52.520008,
          longitude: 13.404954,
          accuracy_meters: 24,
          recorded_at: '2026-05-14T07:30:00+02:00',
          map_url: 'https://www.openstreetmap.org/?mlat=52.5200080&mlon=13.4049540#map=18/52.5200080/13.4049540'
        }
      ],
      geo_count: entryType === 'vacation' ? 0 : 1
    };

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          items: [item],
          summary: {
            total_net_minutes: item.net_minutes,
            total_break_minutes: item.break_minutes,
            entry_count: 1,
            work_entry_count: item.entry_type === 'work' ? 1 : 0,
            absence_entry_count: item.entry_type === 'work' ? 0 : 1,
            attachment_count: item.attachment_count,
            project_count: 1
          },
          days: [
            {
              date: '2026-05-14',
              date_label: '14.05.2026',
              weekday: 'Donnerstag',
              total_net_minutes: item.net_minutes,
              total_break_minutes: item.break_minutes,
              entry_count: 1,
              status_counts: {
                work: item.entry_type === 'work' ? 1 : 0,
                sick: 0,
                vacation: item.entry_type === 'vacation' ? 1 : 0,
                holiday: 0,
                absent: 0
              },
              attachment_count: item.attachment_count,
              items: [item]
            }
          ],
          projects: [
            { id: 2, project_number: 'P-002', name: 'Baustelle Mitte' }
          ],
          filters: {
            scope,
            project_id: projectId ? Number(projectId) : null,
            month: url.searchParams.get('month'),
            entry_type: entryType
          },
          scope,
          project_id: projectId ? Number(projectId) : null,
          cached_at: '2026-05-15 10:00:00'
        }
      })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          enabled: false,
          can_subscribe: false,
          permission_required: false,
          vapid_configured: false,
          vapid_public_key: '',
          reminder_time: '09:00',
          notice_text: '',
          devices: []
        }
      })
    });
  });

  await page.goto('/app/historie');

  await expect(page.getByRole('heading', { name: 'Meine Zeiten' })).toBeVisible();
  await expect(page.locator('a[href="/app/historie"]').first()).toHaveText('Historie');
  await expect(page.getByRole('heading', { name: 'Monatshistorie' })).toBeVisible();
  await expect(page.getByText('Gesamtuebersicht ueber alle Projekte')).toBeVisible();
  await expect(page.getByText('Mai 2026')).toBeVisible();
  await expect(page.locator('.app-history-day > summary').first()).toContainText('14.05.2026');
  await expect(page.locator('.app-timesheet-cards')).toContainText('Gesamtansicht');
  await expect(page.locator('.app-history-summary')).toContainText('Arbeitszeit');
  await page.getByText('Anhaenge anzeigen').click();
  await expect(page.getByText('stundenzettel.pdf')).toBeVisible();
  await page.getByText('Pausen anzeigen').click();
  await expect(page.getByText('Mittag')).toBeVisible();
  await page.getByText('Standort anzeigen').click();
  await expect(page.getByText('52.5200080, 13.4049540')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Karte öffnen' })).toBeVisible();
  await expect.poll(() => timesheetRequests.some((query) => query.includes('scope=all'))).toBe(true);
  await expect.poll(() => timesheetRequests.some((query) => query.includes('month=2026-05'))).toBe(true);

  await page.getByRole('button', { name: 'Projekt' }).click();
  await expect(page.locator('#timesheetProjectFilter')).toBeVisible();
  await page.locator('#timesheetProjectFilter').selectOption('2');

  await expect(page.locator('.app-timesheet-cards')).toContainText('Projektansicht');
  await expect.poll(() => timesheetRequests.some((query) => query.includes('scope=project') && query.includes('project_id=2'))).toBe(true);

  await page.locator('#timesheetEntryTypeFilter').selectOption('vacation');

  await expect(page.locator('.app-timesheet-cards')).toContainText('Urlaubsansicht');
  await expect.poll(() => timesheetRequests.some((query) => query.includes('entry_type=vacation'))).toBe(true);

  await page.evaluate(() => {
    window.history.pushState({}, '', '/app/zeiten');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.getByRole('heading', { name: 'Arbeitszeiten' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Monatshistorie' })).toHaveCount(0);
});

test('mobile project view exposes protected upload controls and disables them offline', async ({ page, context }) => {
  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        bootstrap_required: false,
        user: {
          id: 7,
          display_name: 'Max Mustermann',
          email: 'max@example.test'
        }
      })
    });
  });

  await page.route('**/api/v1/app/me/day', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          today: '2026-05-15',
          projects: [
            { id: 2, project_number: 'P-002', name: 'Baustelle Mitte', city: 'Berlin' }
          ],
          attachments: [],
          today_state: {
            status: 'not_started',
            work_entry: null,
            active_break: null
          },
          geo_policy: {
            enabled: false,
            notice_text: '',
            requires_acknowledgement: false
          }
        }
      })
    });
  });

  await page.route('**/api/v1/settings/company', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ data: { company_name: 'Muster Bau' } })
    });
  });

  await page.route('**/api/v1/app/projects/2/files', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        data: [
          {
            id: 5,
            original_name: 'baustelle.jpg',
            mime_type: 'image/jpeg',
            size_bytes: 120000,
            is_image: true,
            download_url: '/api/v1/app/project-files/5/download',
            preview_url: '/api/v1/app/project-files/5/download'
          }
        ]
      })
    });
  });

  await page.route('**/api/v1/app/projects/2/materials', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, data: [] })
    });
  });

  await page.route('**/api/v1/app/push/status', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          enabled: false,
          can_subscribe: false,
          permission_required: false,
          vapid_configured: false,
          vapid_public_key: '',
          reminder_time: '09:00',
          notice_text: '',
          devices: []
        }
      })
    });
  });

  await page.goto('/app/projektwahl');

  await expect(page.getByRole('heading', { name: 'Baustelle waehlen' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Baustelle Mitte', exact: true })).toBeVisible();
  await expect(page.locator('#projectCameraInput')).toHaveAttribute('capture', 'environment');
  await expect(page.locator('#projectAttachmentInput')).toHaveAttribute('accept', /image\/\*/);
  await expect(page.locator('.app-attachment-info')).toContainText('baustelle.jpg');

  await page.locator('#projectAttachmentInput').setInputFiles({
    name: 'aufnahme.jpg',
    mimeType: 'image/jpeg',
    buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9])
  });
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await page.waitForTimeout(300);
  await expect.poll(async () => {
    return page.locator('#projectAttachmentInput').evaluate((input) => input.files ? input.files.length : 0);
  }).toBe(1);

  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));

  await expect(page.locator('#projectCameraInput')).toBeDisabled();
  await expect(page.locator('#projectAttachmentInput')).toBeDisabled();
  await expect(page.getByText('Projektdateien koennen nur mit aktiver Verbindung hochgeladen werden.')).toBeVisible();
});

test('dark drawer keeps active navigation link readable after login', async ({ page }) => {
  const email = process.env.UI_TEST_EMAIL || '';
  const password = process.env.UI_TEST_PASSWORD || '';

  test.skip(email === '' || password === '', 'Set UI_TEST_EMAIL and UI_TEST_PASSWORD to run the authenticated drawer check.');

  await page.addInitScript(() => {
    window.localStorage.setItem('app.theme', 'dark');
  });

  await page.goto('/app/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Anmelden' }).click();

  await page.waitForURL('**/app/heute');
  await expect(page.locator('#appMenuToggle')).toBeVisible();
  await page.locator('#appMenuToggle').click();

  if (await page.locator('.app-drawer-layer').count() === 0) {
    await page.locator('#appMenuToggle').click();
  }

  const activeDrawerLink = page.locator('.app-drawer-link.is-active').first();

  await expect(page.locator('.app-drawer-layer')).toBeVisible();
  await expect(activeDrawerLink).toBeVisible();
  await expect(activeDrawerLink).toHaveCSS('color', 'rgb(27, 27, 27)');

  const backgroundImage = await activeDrawerLink.evaluate((element) => {
    return window.getComputedStyle(element).backgroundImage;
  });

  expect(backgroundImage).toContain('linear-gradient');
});

test('terminal settings modal fills, saves and restores focus', async ({ page }) => {
  await page.setContent(
    '<button type="button" data-terminal-settings-open aria-expanded="false" data-terminal-settings=\'{"id":7,"name":"Terminal Nord","ready_lines":["Willkommen Nord","Tag vorhalten","Bereit"],"check_in_lines":["Hallo {vorname}","Arbeitsbeginn","{zeit}","Soll {sollzeit}"],"check_out_lines":["Hallo {vorname}","Feierabend","{zeit}","Soll {sollzeit}"],"hold_ms":{"success":5000,"error":7000,"learning":9000}}\'>Terminal-Einstellungen</button>'
      + '<div class="admin-modal" data-terminal-settings-modal hidden aria-hidden="true">'
      + '<button type="button" data-terminal-settings-modal-close>Schliessen</button><button type="button" data-terminal-settings-modal-close>Overlay schliessen</button>'
      + '<p data-terminal-settings-name></p>'
      + '<form method="post" action="" data-terminal-settings-form>'
      + '<input name="ready_line_1"><input name="ready_line_2"><input name="ready_line_3">'
      + '<input name="check_in_line_1"><input name="check_in_line_2"><input name="check_in_line_3"><input name="check_in_line_4">'
      + '<input name="check_out_line_1"><input name="check_out_line_2"><input name="check_out_line_3"><input name="check_out_line_4">'
      + '<input name="hold_success_ms"><input name="hold_error_ms"><input name="hold_learning_ms">'
      + '<button type="submit">Einstellungen speichern</button>'
      + '</form></div>'
  );
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-terminals.js') });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  await page.getByRole('button', { name: 'Terminal-Einstellungen' }).click();
  const modal = page.locator('[data-terminal-settings-modal]');
  const form = modal.locator('[data-terminal-settings-form]');

  await expect(modal).toBeVisible();
  await expect(page.getByRole('button', { name: 'Terminal-Einstellungen' })).toHaveAttribute('aria-expanded', 'true');
  await expect(modal.locator('[data-terminal-settings-name]')).toHaveText('Terminal Nord');
  await expect(form).toHaveAttribute('action', '/admin/terminals/7/settings');
  await expect(form.locator('[name="ready_line_1"]')).toHaveValue('Willkommen Nord');
  await expect(form.locator('[name="check_in_line_1"]')).toHaveValue('Hallo {vorname}');
  await expect(form.locator('[name="check_out_line_2"]')).toHaveValue('Feierabend');
  await expect(form.locator('[name="hold_success_ms"]')).toHaveValue('5000');
  await expect(form.locator('[name="hold_error_ms"]')).toHaveValue('7000');
  await expect(form.locator('[name="hold_learning_ms"]')).toHaveValue('9000');

  await form.locator('[name="hold_success_ms"]').fill('6000');
  await page.evaluate(() => {
    const form = document.querySelector('[data-terminal-settings-form]');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      window.__terminalSettingsSaved = Object.fromEntries(new FormData(form));
    }, { once: true });
  });
  await form.getByRole('button', { name: 'Einstellungen speichern' }).click();
  await expect.poll(() => page.evaluate(() => window.__terminalSettingsSaved.hold_success_ms)).toBe('6000');

  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
  await expect(page.getByRole('button', { name: 'Terminal-Einstellungen' })).toBeFocused();
  await expect(page.getByRole('button', { name: 'Terminal-Einstellungen' })).toHaveAttribute('aria-expanded', 'false');
  await page.getByRole('button', { name: 'Terminal-Einstellungen' }).click();
  await expect(form.locator('[name="ready_line_1"]')).toHaveValue('Willkommen Nord');
});

test('admin table module supports search, sorting, pagination and empty states', async ({ page }) => {
  const rows = Array.from({ length: 30 }, (_, index) => {
    const number = index + 1;
    const minutes = number % 3;

    return '<tr data-row-selectable="true" data-edit-url="#project-edit-' + number + '">'
      + '<td>P-' + String(number).padStart(3, '0') + '</td>'
      + '<td>Projekt ' + number + '</td>'
      + '<td>Kunde ' + (number % 5) + '</td>'
      + '<td>active</td>'
      + '<td>Ort ' + number + '</td>'
      + '<td data-sort-value="' + minutes + '">' + (minutes / 2).toFixed(2) + ' h</td>'
      + '<td data-search="false"><a href="#project-link-' + number + '">Bearbeiten</a></td>'
      + '</tr>';
  }).join('');

  await page.setContent(
    '<div class="table-scroll">'
      + '<table data-admin-table="projects" data-table-label="Projekte">'
      + '<thead><tr><th>Nummer</th><th>Name</th><th>Kunde</th><th>Status</th><th>Ort</th><th data-sort-type="number">Stunden</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table>'
      + '</div>'
      + '<table data-admin-table="empty" data-table-label="Leere Projekte">'
      + '<thead><tr><th>Name</th></tr></thead>'
      + '<tbody><tr><td colspan="1" class="table-empty">Keine Projekte im aktuellen Filter.</td></tr></tbody>'
      + '</table>'
  );
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-tables.js') });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  const table = page.locator('[data-admin-table="projects"]');
  const search = page.getByRole('searchbox', { name: 'Projekte durchsuchen', exact: true });
  const rowsLocator = table.locator('tbody tr:not(.table-empty)');

  await expect(search).toBeVisible();
  await expect(rowsLocator).toHaveCount(25);

  await expect(rowsLocator.first()).toHaveAttribute('tabindex', '0');
  await rowsLocator.nth(1).click();
  await expect(rowsLocator.nth(1)).toHaveClass(/is-selected/);
  await expect(rowsLocator.nth(1)).toHaveAttribute('tabindex', '0');
  await expect(table.locator('tbody tr.is-selected')).toHaveCount(1);

  await rowsLocator.nth(2).locator('a').click();
  await expect(rowsLocator.nth(1)).toHaveClass(/is-selected/);
  await expect(rowsLocator.nth(2)).not.toHaveClass(/is-selected/);
  await expect(page).toHaveURL(/#project-link-3$/);

  await search.fill(' kunde 0 projekt 30 ');
  await expect(rowsLocator).toHaveCount(1);
  await expect(rowsLocator.first()).toContainText('Projekt 30');

  await search.fill('');
  await page.getByRole('button', { name: 'Stunden sortieren' }).click();

  const values = await table.locator('tbody tr:not(.table-empty) td:nth-child(6)').evaluateAll((cells) => {
    return cells.map((cell) => Number(cell.getAttribute('data-sort-value') || '0'));
  });

  expect(values).toEqual([...values].sort((left, right) => left - right));

  await page.getByRole('button', { name: 'Stunden sortieren' }).click();
  await expect(rowsLocator.first()).toContainText('Projekt 2');
  await expect(rowsLocator.nth(1)).toContainText('Projekt 5');

  await page.getByLabel('Zeilen').first().selectOption('10');
  await expect(rowsLocator).toHaveCount(10);
  await page.getByRole('button', { name: 'Weiter' }).first().click();
  await expect(page.locator('.admin-table-page-info').first()).toHaveText('Seite 2 / 3');

  await rowsLocator.first().dblclick();
  await expect(page).toHaveURL(/#project-edit-\d+$/);

  const emptyTable = page.locator('[data-admin-table="empty"]');

  await expect(emptyTable.locator('tbody .table-empty')).toContainText('Keine Projekte im aktuellen Filter.');
  await expect(page.locator('.admin-table-summary').nth(1)).toHaveText('0 von 0 Leere Projekte');
  await expect(page.locator('.admin-table-summary').first()).toHaveAttribute('aria-live', 'polite');
  await expect(table.locator('thead th').nth(6)).not.toHaveAttribute('aria-sort', /.+/);
});

test('admin table module supports opt-in row selection and double-click editing', async ({ page }) => {
  await page.setContent(
    '<div class="table-scroll">'
      + '<table data-admin-table="users" data-table-label="User">'
      + '<thead><tr><th>Mitarbeiternummer</th><th>Name</th><th>E-Mail</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead>'
      + '<tbody>'
      + '<tr data-row-selectable="true" data-edit-url="#edit-3"><td>MA-003</td><td>Clara Check</td><td>clara@example.test</td><td data-search="false"><a href="#link-3">Bearbeiten</a></td></tr>'
      + '<tr data-row-selectable="true" data-edit-url="#edit-1"><td>MA-001</td><td>Anna Aktiv</td><td>anna@example.test</td><td data-search="false"><a href="#link-1">Bearbeiten</a></td></tr>'
      + '<tr data-row-selectable="true" data-edit-url="#edit-2"><td>MA-002</td><td>Ben Bau</td><td>ben@example.test</td><td data-search="false"><a href="#link-2">Bearbeiten</a></td></tr>'
      + '</tbody>'
      + '</table>'
      + '</div>'
  );
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-tables.js') });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  const table = page.locator('[data-admin-table="users"]');
  const rows = table.locator('tbody tr:not(.table-empty)');

  await expect(rows.first()).toHaveAttribute('tabindex', '0');
  await expect(rows.nth(1)).toHaveAttribute('tabindex', '-1');

  await rows.nth(1).click();
  await expect(rows.nth(1)).toHaveClass(/is-selected/);
  await expect(rows.nth(1)).toHaveAttribute('tabindex', '0');
  await expect(table.locator('tbody tr.is-selected')).toHaveCount(1);

  await rows.nth(2).locator('a').click();
  await expect(rows.nth(1)).toHaveClass(/is-selected/);
  await expect(rows.nth(2)).not.toHaveClass(/is-selected/);

  await rows.nth(2).focus();
  await page.keyboard.press('Space');
  await expect(rows.nth(2)).toHaveClass(/is-selected/);
  await expect(table.locator('tbody tr.is-selected')).toHaveCount(1);

  await page.keyboard.press('ArrowUp');
  await expect(rows.nth(1)).toBeFocused();

  await page.getByRole('button', { name: 'Mitarbeiternummer sortieren' }).click();
  await expect(rows.first().locator('td').first()).toHaveText('MA-001');

  await rows.first().dblclick();
  await expect(page).toHaveURL(/#edit-1$/);
});

test('dashboard attendance chart renders locally and retains a fallback without Chart.js', async ({ page }) => {
  const markup = '<div class="card chart-card"><div class="attendance-status-chart"><canvas id="attendanceStatusChart"></canvas></div></div>'
    + '<p id="attendanceStatusChartFallback">Das Kreisdiagramm wird mit JavaScript geladen.</p>'
    + '<script id="attendanceStatusChartData" type="application/json">{"labels":["Noch da","Krank"],"data":[3,1]}</script>';
  let legacyDashboardChartRequests = 0;

  await page.route('**/api/v1/dashboard/charts', async (route) => {
    legacyDashboardChartRequests++;
    await route.abort();
  });

  await page.setContent(markup);
  await page.addStyleTag({ path: path.join(__dirname, '../../public/assets/css/admin.css') });
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-dashboard.js') });
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/vendor/chart.umd.js') });
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-attendance.js') });

  await expect(page.locator('#attendanceStatusChart')).toBeVisible();
  await expect(page.locator('#attendanceStatusChartFallback')).toBeHidden();
  expect(legacyDashboardChartRequests).toBe(0);

  const chartHeight = await page.locator('.attendance-status-chart').evaluate((element) => element.getBoundingClientRect().height);
  const initialDocumentHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.waitForTimeout(800);

  expect(await page.locator('.attendance-status-chart').evaluate((element) => element.getBoundingClientRect().height)).toBe(chartHeight);
  expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBe(initialDocumentHeight);

  await page.setContent(markup);
  await page.addStyleTag({ path: path.join(__dirname, '../../public/assets/css/admin.css') });
  await page.evaluate(() => { delete window.Chart; });
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-attendance.js') });

  await expect(page.locator('.attendance-status-chart')).toBeHidden();
  await expect(page.locator('#attendanceStatusChart')).toBeHidden();
  await expect(page.locator('#attendanceStatusChartFallback')).toHaveText('Chart.js konnte nicht lokal geladen werden.');
});

test('dashboard attendance action is separated from the status copy', async ({ page }) => {
  await page.setContent(
    '<article class="card status-card">'
      + '<p id="dashboardAttendanceCopy">Krank, Urlaub, Feiertag oder fehlt. Bereits ausgecheckte Mitarbeiter sind als eigener Status im Diagramm enthalten.</p>'
      + '<div class="status-card__actions"><a class="button button-secondary" href="/admin/attendance">Anwesenheit öffnen</a></div>'
      + '</article>'
  );
  await page.addStyleTag({ path: path.join(__dirname, '../../public/assets/css/admin.css') });

  const copyBox = await page.locator('#dashboardAttendanceCopy').boundingBox();
  const actionsBox = await page.locator('.status-card__actions').boundingBox();

  expect(copyBox).not.toBeNull();
  expect(actionsBox).not.toBeNull();
  expect(actionsBox.y).toBeGreaterThanOrEqual(copyBox.y + copyBox.height + 16);
  await expect(page.locator('.status-card__actions .button')).toBeVisible();
});

test('admin projects table can be searched and sorted', async ({ page }) => {
  const email = process.env.UI_ADMIN_EMAIL || '';
  const password = process.env.UI_ADMIN_PASSWORD || '';

  test.skip(email === '' || password === '', 'Set UI_ADMIN_EMAIL and UI_ADMIN_PASSWORD to run the authenticated admin projects check.');

  await page.goto('/admin/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await page.goto('/admin/projects');

  const table = page.locator('[data-admin-table="projects"]');
  const search = page.getByLabel('Projekte durchsuchen');
  const projectRows = table.locator('tbody tr:not(.table-empty)');

  await expect(table).toBeVisible();
  await expect(search).toBeVisible();

  expect(await projectRows.count()).toBeLessThanOrEqual(25);

  await search.fill('__keine_passenden_projekte__');
  await expect(table.locator('tbody .table-empty')).toContainText('Keine passenden Eintraege gefunden.');
  await expect(page.locator('.admin-table-summary')).toContainText(/^0 von \d+ Projekte$/);

  await search.fill('');

  if (await projectRows.count() > 1) {
    await page.getByRole('button', { name: 'Stunden sortieren' }).click();

    const values = await table.locator('tbody tr:not(.table-empty) td:nth-child(6)').evaluateAll((cells) => {
      return cells.map((cell) => Number(cell.getAttribute('data-sort-value') || '0'));
    });
    const sorted = [...values].sort((left, right) => left - right);

    expect(values).toEqual(sorted);
  }
});
