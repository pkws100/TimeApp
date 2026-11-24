const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

const root = path.join(__dirname, '../..');
const shell = execFileSync('php', ['-r',
  'require "bootstrap/autoload.php"; echo (new App\\Presentation\\App\\AppView("Termin-Test"))->render("/app/termine", []);'
], { cwd: root, encoding: 'utf8' });
const appointmentPath = '/app/termine';
const api = '/api/v1/app/appointments';

test.use({ serviceWorkers: 'block' });

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { resolve, promise };
}

function item(id, title, status = 'planned') {
  return {
    id, title, status, description: 'Fiktive Einsatzbeschreibung',
    starts_at: '2026-11-27T10:00', ends_at: '2026-11-27T11:30', timezone: 'Europe/Berlin',
    project_number: 'TEST-01', project_name: 'Testprojekt', location: 'Werkhalle', version: 1,
    acknowledged: false, acknowledged_at_utc: null,
  };
}

async function appointmentApp(page, initiallySignedIn = true) {
  const privateTitle = '<b data-appointment-xss>Eigener Termin</b>';
  const records = [item(21, privateTitle), item(22, 'Abgesagter Einsatz', 'cancelled')];
  const state = { records, privateTitle, signedIn: initiallySignedIn, userId: 7, reads: [], writes: [], holdDetail: null, beforeWrite: null, storageFetches: [], timeActions: [] };
  const user = () => ({ id: state.userId, display_name: 'Testmitarbeiter ' + state.userId, permissions: ['timesheets.create'] });
  const session = () => ({ authenticated: state.signedIn, user: state.signedIn ? user() : null, bootstrap_required: false });

  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;
    const json = (value, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(value) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') {
      return route.fulfill({ path: path.join(root, 'public', pathname) });
    }
    if (pathname.startsWith('/app/') || pathname === '/app') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: shell });
    }
    if (pathname === '/api/v1/auth/session') return json(session());
    if (pathname === '/api/v1/auth/login') {
      state.signedIn = true;
      return json({ session: session() });
    }
    if (pathname === '/api/v1/settings/company') return json({ data: { company_name: 'Termin-Test' } });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, devices: [] } });
    if (!pathname.startsWith('/api/')) return route.fulfill({ status: 204, body: '' });
    const expectedId = request.headers()['x-timeapp-user-id'];
    if (!state.signedIn || expectedId !== String(state.userId)) return json({ code: 'session_changed' }, 401);
    if (pathname === '/api/v1/app/me/day') {
      return json({ data: { today: '2026-11-27', user: user(), projects: [], today_state: { status: 'not_started' } } });
    }
    if (pathname === '/api/v1/app/timesheets/sync') {
      state.timeActions.push(request.postDataJSON());
      return json({ data: {} });
    }
    if (pathname === api) {
      const filter = url.searchParams.get('filter');
      const page = Number(url.searchParams.get('page'));
      state.reads.push({ filter, page, expectedId });
      const scoped = state.userId === 7 ? records : [item(31, 'Termin des neuen Kontos')];
      return json({ data: scoped.filter(record => filter === 'past' ? record.status === 'cancelled' : record.status === 'planned'), page, has_more: filter === 'upcoming' && page === 1 });
    }
    const match = /^\/api\/v1\/app\/appointments\/(\d+)(\/acknowledge)?$/.exec(pathname);
    if (match) {
      const candidate = state.userId === 7
        ? records.find(record => record.id === Number(match[1]))
        : (Number(match[1]) === 31 ? item(31, 'Termin des neuen Kontos') : null);
      if (!candidate) return json({ error: 'Nicht verfügbar' }, 404);
      if (match[2]) {
        const payload = request.postDataJSON();
        state.writes.push({ id: candidate.id, expectedId, payload });
        if (state.beforeWrite) {
          state.beforeWrite(candidate);
          state.beforeWrite = null;
        }
        if (payload.version !== candidate.version) return json({ error: 'Konflikt' }, 409);
        expect(payload.csrf_token).toBe('fixture-token');
        candidate.acknowledged = true;
        candidate.acknowledged_at_utc = '2026-11-26 13:00:00';
        return json({ data: candidate });
      }
      const data = JSON.parse(JSON.stringify(candidate));
      const hold = state.holdDetail;
      state.holdDetail = null;
      if (hold) {
        hold.started.resolve();
        await hold.continue.promise;
      }
      return json({ data, csrf_token: 'fixture-token' });
    }
    state.storageFetches.push(pathname);
    return json({ error: 'unexpected request' }, 501);
  });
  return state;
}

test('appointments open from mobile navigation, filter and paginate, and acknowledge by explicit action', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 740 });
  const mock = await appointmentApp(page);
  await page.goto('/app/heute');
  await page.getByRole('button', { name: 'Menue oeffnen' }).click();
  await page.getByRole('link', { name: 'Termine', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Meine Termine' })).toBeVisible();
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toBeVisible();
  await expect(page.locator('[data-appointment-xss]')).toHaveCount(0);
  await expect(page.getByText('27.11.2026, 10:00 Uhr', { exact: true })).toBeVisible();
  await expect(page.getByText('TEST-01 – Testprojekt', { exact: true })).toBeVisible();
  await expect(page.getByText('Werkhalle', { exact: true })).toBeVisible();
  expect(await page.locator('.app-layout').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(1);
  await page.getByRole('link', { name: 'Nächste Seite' }).click();
  await expect.poll(() => mock.reads.some(entry => entry.filter === 'upcoming' && entry.page === 2)).toBeTruthy();
  await page.getByRole('link', { name: 'Heute', exact: true }).click();
  await expect.poll(() => mock.reads.some(entry => entry.filter === 'today' && entry.page === 1)).toBeTruthy();
  await page.getByRole('link', { name: 'Vergangene und abgesagte Termine' }).click();
  await expect(page.getByRole('heading', { name: 'Abgesagter Einsatz' })).toBeVisible();
  await page.getByRole('link', { name: 'Termin öffnen: Abgesagter Einsatz' }).click();
  await expect(page).toHaveURL(/\/app\/termine\?id=22&filter=past$/);
  await expect(page.locator('[data-app-appointment-detail]')).toContainText('Fiktive Einsatzbeschreibung');
  expect(mock.writes).toEqual([]);
  await page.getByRole('button', { name: 'Termin zur Kenntnis genommen' }).click();
  await expect(page.getByText('Bestätigt', { exact: true })).toBeVisible();
  expect(mock.writes).toEqual([{ id: 22, expectedId: '7', payload: { version: 1, csrf_token: 'fixture-token' } }]);
  expect(mock.storageFetches).toEqual([]);
});

test('appointment deep link survives login and a stale confirmation shows the new version', async ({ page }) => {
  const mock = await appointmentApp(page, false);
  await page.goto(appointmentPath + '?id=21');
  await page.locator('#loginForm [name=email]').fill('synthetic@example.test');
  await page.locator('#loginForm [name=password]').fill('synthetic-password');
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page).toHaveURL(/\/app\/termine\?id=21$/);
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toBeVisible();
  mock.beforeWrite = record => {
    record.title = 'Geänderter Einsatz';
    record.version = 2;
    record.acknowledged_at_utc = '2026-11-25 13:00:00';
  };
  await page.getByRole('button', { name: 'Termin zur Kenntnis genommen' }).click();
  await expect(page.getByText(/Der Termin wurde geändert/)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Geänderter Einsatz' })).toBeVisible();
  await expect(page.getByText('Der aktuelle Terminstand ist noch nicht bestätigt.')).toBeVisible();
  await page.getByRole('button', { name: 'Termin zur Kenntnis genommen' }).click();
  await expect(page.getByText('Bestätigt', { exact: true })).toBeVisible();
  expect(mock.writes.map(write => write.payload.version)).toEqual([1, 2]);
});

test('foreign and malformed deep links stay unavailable', async ({ page }) => {
  await appointmentApp(page);
  await page.goto(appointmentPath + '?id=31');
  await expect(page.getByRole('alert')).toHaveText('Dieser Terminbereich ist nicht verfügbar.');
  await expect(page.getByText('Termin des neuen Kontos', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Termin zur Kenntnis genommen' })).toHaveCount(0);
  await page.goto(appointmentPath + '?id=21&id=31');
  await expect(page.getByRole('alert')).toHaveText('Dieser Termin ist nicht verfügbar.');
  await expect(page.locator('[data-app-appointment-detail]')).toHaveCount(0);
});

test('appointments vanish offline and a previous account response cannot reappear', async ({ page, context }) => {
  const mock = await appointmentApp(page);
  await page.goto(appointmentPath);
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toBeVisible();
  const stored = await page.evaluate(async () => ({
    local: Object.entries(localStorage), session: Object.entries(sessionStorage), caches: await caches.keys(),
    indexed: await new Promise((resolve, reject) => {
      const request = indexedDB.open('zeiterfassung-app');
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        const rows = database.transaction('cache').objectStore('cache').getAll();
        rows.onerror = () => reject(rows.error);
        rows.onsuccess = () => { database.close(); resolve(rows.result); };
      };
    })
  }));
  expect(JSON.stringify(stored)).not.toContain(mock.privateTitle);
  expect(JSON.stringify(stored)).not.toContain('fixture-token');

  await context.setOffline(true);
  await expect(page.getByText(/Termine sind offline nicht verfügbar/)).toBeVisible();
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toHaveCount(0);
  await context.setOffline(false);
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toBeVisible();

  const hold = { started: deferred(), continue: deferred() };
  mock.holdDetail = hold;
  await page.getByRole('link', { name: 'Termin öffnen: ' + mock.privateTitle, exact: true }).click();
  try {
    await hold.started.promise;
    mock.userId = 8;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect(page).toHaveURL(/\/app\/login$/);
  } finally {
    hold.continue.resolve();
  }
  await page.locator('#loginForm [name=email]').fill('synthetic-other@example.test');
  await page.locator('#loginForm [name=password]').fill('synthetic-password');
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page.getByRole('alert')).toHaveText('Dieser Terminbereich ist nicht verfügbar.');
  await expect(page.getByRole('heading', { name: mock.privateTitle, exact: true })).toHaveCount(0);
});

test('time sync on reconnect does not wait for a pending appointment read', async ({ page, context }) => {
  const mock = await appointmentApp(page);
  await page.goto(appointmentPath + '?id=21');
  await expect(page.locator('[data-app-appointment-detail]')).toBeVisible();
  await context.setOffline(true);
  await expect(page.getByText(/Termine sind offline nicht verfügbar/)).toBeVisible();
  await page.evaluate(() => new Promise((resolve, reject) => {
    const request = indexedDB.open('zeiterfassung-app');
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const transaction = database.transaction('queue', 'readwrite');
      transaction.objectStore('queue').put({
        id: 'queued-appointment-regression', client_request_id: 'queued-appointment-regression', userPart: '7',
        endpoint: '/api/v1/app/timesheets/sync', status: 'pending', createdAt: Date.now(),
        payload: { action: 'check_in', work_date: '2026-11-27', start_time: '09:00', project_id: null },
      });
      transaction.oncomplete = () => { database.close(); resolve(); };
      transaction.onerror = () => { database.close(); reject(transaction.error); };
    };
  }));

  const hold = { started: deferred(), continue: deferred() };
  mock.holdDetail = hold;
  try {
    await context.setOffline(false);
    await hold.started.promise;
    await expect.poll(() => mock.timeActions.map(action => action.client_request_id)).toEqual(['queued-appointment-regression']);
    await expect(page.locator('[data-app-appointment-detail]')).toHaveCount(0);
  } finally {
    hold.continue.resolve();
  }
  await expect(page.locator('[data-app-appointment-detail]')).toBeVisible();
});

test('service worker opens internal appointment notification links without caching private responses', async () => {
  const code = execFileSync('php', ['-r',
    'require "bootstrap/autoload.php"; $a=new App\\Domain\\Auth\\AuthService(new App\\Infrastructure\\Database\\DatabaseConnection([]),new App\\Domain\\Users\\PermissionMatrix([],[]));'
    + '$c=new App\\Http\\Controllers\\AppController(new App\\Presentation\\App\\AppView("Test"),$a,"Test");'
    + '$c->serviceWorker(new App\\Http\\Request("GET","/app/sw.js",[],[],[],[],[]))->send();'
  ], { cwd: root, encoding: 'utf8' });
  const origin = 'https://timeapp.test';
  const handlers = new Map();
  const calls = [];
  const appWindow = {
    url: origin + '/app/heute',
    navigate: async target => { calls.push(['navigate', target]); return appWindow; },
    focus: async () => { calls.push(['focus']); },
  };
  const worker = {
    location: { origin },
    addEventListener: (name, handler) => handlers.set(name, handler),
    registration: { showNotification: async (title, options) => calls.push(['notify', options.data.url]) },
    clients: { matchAll: async () => [appWindow], openWindow: async target => { calls.push(['open', target]); } },
  };
  vm.runInNewContext(code, {
    self: worker, URL, Request,
    fetch: async () => { throw new Error('offline'); },
    caches: { match: async key => { calls.push(['cache', key]); return 'generic offline shell'; } },
  });
  async function dispatch(name, payload) {
    let result;
    handlers.get(name)({ ...payload, waitUntil: value => { result = value; } });
    await result;
  }
  const target = '/app/termine?id=21';
  await dispatch('push', { data: { json: () => ({ url: target }) } });
  await dispatch('notificationclick', { notification: { close() {}, data: { url: target } } });
  appWindow.url = origin + target;
  await dispatch('notificationclick', { notification: { close() {}, data: { url: target } } });
  await dispatch('notificationclick', { notification: { close() {}, data: { url: 'https://other.example/app/termine?id=21' } } });
  expect(calls).toEqual([['notify', target], ['navigate', target], ['focus'], ['navigate', target], ['focus'], ['navigate', '/app'], ['focus']]);
  calls.length = 0;
  handlers.get('fetch')({ request: new Request(origin + api + '/21'), respondWith: () => { throw new Error('private response intercepted'); } });
  let offlineResponse;
  handlers.get('fetch')({ request: new Request(origin + target), respondWith: value => { offlineResponse = value; } });
  expect(await offlineResponse).toBe('generic offline shell');
  expect(calls).toEqual([['cache', '/app']]);
});
