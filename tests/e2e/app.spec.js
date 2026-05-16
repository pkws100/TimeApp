const path = require('path');
const { test, expect } = require('@playwright/test');

test('mobile app login screen loads', async ({ page }) => {
  await page.goto('/app/login');

  await expect(page.locator('#loginForm')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
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

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          items: [
            {
              id: projectId === '2' ? 20 : 10,
              project_id: projectId === '2' ? 2 : null,
              project_name: projectId === '2' ? 'Baustelle Mitte' : 'Nicht zugeordnet',
              work_date: '2026-05-14',
              start_time: '07:30:00',
              end_time: '16:00:00',
              break_minutes: 30,
              net_minutes: 480,
              entry_type: 'work',
              note: scope === 'all' ? 'Gesamtansicht' : 'Projektansicht'
            }
          ],
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
  await expect(page.getByText('Gesamtuebersicht ueber alle Projekte')).toBeVisible();
  await expect(page.locator('.app-timesheet-cards')).toContainText('Gesamtansicht');
  await expect.poll(() => timesheetRequests.some((query) => query.includes('scope=all'))).toBe(true);

  await page.getByRole('button', { name: 'Projekt' }).click();
  await expect(page.locator('#timesheetProjectFilter')).toBeVisible();
  await page.locator('#timesheetProjectFilter').selectOption('2');

  await expect(page.locator('.app-timesheet-cards')).toContainText('Projektansicht');
  await expect.poll(() => timesheetRequests.some((query) => query.includes('scope=project') && query.includes('project_id=2'))).toBe(true);

  await page.evaluate(() => {
    window.history.pushState({}, '', '/app/zeiten');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.getByRole('heading', { name: 'Arbeitszeiten' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Zeituebersicht' })).toHaveCount(0);
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
  await expect(page.getByRole('heading', { name: 'Baustelle Mitte' })).toBeVisible();
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

  await expect(page.locator('#appMenuToggle')).toBeVisible();
  await page.locator('#appMenuToggle').click();

  const activeDrawerLink = page.locator('.app-drawer-link.is-active').first();

  await expect(activeDrawerLink).toBeVisible();
  await expect(activeDrawerLink).toHaveCSS('color', 'rgb(27, 27, 27)');

  const backgroundImage = await activeDrawerLink.evaluate((element) => {
    return window.getComputedStyle(element).backgroundImage;
  });

  expect(backgroundImage).toContain('linear-gradient');
});

test('admin table module supports search, sorting, pagination and empty states', async ({ page }) => {
  const rows = Array.from({ length: 30 }, (_, index) => {
    const number = index + 1;
    const minutes = number % 3;

    return '<tr>'
      + '<td>P-' + String(number).padStart(3, '0') + '</td>'
      + '<td>Projekt ' + number + '</td>'
      + '<td>Kunde ' + (number % 5) + '</td>'
      + '<td>active</td>'
      + '<td>Ort ' + number + '</td>'
      + '<td data-sort-value="' + minutes + '">' + (minutes / 2).toFixed(2) + ' h</td>'
      + '<td data-search="false"><a href="/admin/projects/' + number + '/edit">Bearbeiten</a></td>'
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

  const emptyTable = page.locator('[data-admin-table="empty"]');

  await expect(emptyTable.locator('tbody .table-empty')).toContainText('Keine Projekte im aktuellen Filter.');
  await expect(page.locator('.admin-table-summary').nth(1)).toHaveText('0 von 0 Leere Projekte');
  await expect(page.locator('.admin-table-summary').first()).toHaveAttribute('aria-live', 'polite');
  await expect(table.locator('thead th').nth(6)).not.toHaveAttribute('aria-sort', /.+/);
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
