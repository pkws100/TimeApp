const { test, expect, request } = require('@playwright/test');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = '/admin/personnel-documents';
const fixture = process.env.UI_PERSONNEL_FIXTURES
    ? JSON.parse(fs.readFileSync(process.env.UI_PERSONNEL_FIXTURES, 'utf8')) : null;

function key() {
    return randomBytes(24).toString('hex');
}

function account(record) {
    return { email: record.email, password: record.password, next: root };
}

function download(document) {
    return `${root}/${document}/download`;
}

async function signIn(api, credentials) {
    const response = await api.post('/admin/login', { form: account(credentials), maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toBe(root);
}

async function csrf(api, url = root) {
    const response = await api.get(url);
    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('no-store');
    const token = (await response.text()).match(/name="csrf_token"\s+value="([0-9a-f]{64})"/);
    expect(token).not.toBeNull();
    return token[1];
}

function scratchSql(sql) {
    if (!fixture || !/^\/var\/tmp\/timeapp-personnel-http-[0-9a-f]{12}$/.test(fixture.scratchRoot)
        || !/^timeapp_personnel_http_[0-9a-f]{12}$/.test(fixture.database)) {
        throw new Error('Expected a disposable personnel fixture database.');
    }
    return execFileSync('mariadb', ['--no-defaults', `--socket=${fixture.scratchRoot}/mariadb.sock`, '--user=root',
        '--batch', '--skip-column-names', fixture.database, '-e', sql], { encoding: 'utf8' }).trim();
}

function numeric(value) {
    const id = Number(value);
    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid synthetic id.');
    return id;
}

function png(shade = 40) {
    return execFileSync('php', ['-r', 'require "bootstrap/autoload.php"; echo Tests\\Support\\PersonnelFileTestFixture::png((int)$argv[1]);', String(shade)]);
}

async function upload(api, document, token, bytes, id, predecessor, mimeType = 'image/png', name = 'synthetic.png', revision = predecessor + 1) {
    return api.post(`${root}/${document}/upload`, {
        headers: { Accept: 'application/json', 'X-CSRF-Token': token },
        multipart: { csrf_token: token, request_id: id, expected_predecessor: String(predecessor), expected_revision: String(revision),
            file: { name, mimeType, buffer: bytes } },
        maxRedirects: 0,
    });
}

test.beforeEach(() => {
    expect(fixture, 'Run through tests/Support/run-personnel-admin-ui.sh').not.toBeNull();
});

test('disabled mode, incorrect host or proxy headers cannot expose the module', async ({ request: api, baseURL }) => {
    const anonymous = await api.get(root, { maxRedirects: 0 });
    expect(anonymous.status()).toBe(302);
    expect(anonymous.headers().location).toBe('/admin/login?next=%2Fadmin%2Fpersonnel-documents');
    expect(anonymous.headers()['cache-control']).toContain('no-store');
    expect(process.env.UI_PERSONNEL_DISABLED_BASE_URL).toBeTruthy();
    const disabled = await request.newContext({ baseURL: process.env.UI_PERSONNEL_DISABLED_BASE_URL });
    try {
        const response = await disabled.get(`${root}?mode=synthetic`, { headers: { 'X-Personnel-Mode': 'synthetic' } });
        expect(response.status()).toBe(404);
    } finally {
        await disabled.dispose();
    }
    await signIn(api, fixture.admin);
    expect((await api.get(root, { headers: { 'X-Forwarded-For': '127.0.0.1' } })).status()).toBe(404);
    expect((await api.get(root, { headers: { Host: `localhost:${new URL(baseURL).port}` } })).status()).toBe(404);
    expect((await api.get('/api/v1/personnel/documents')).status()).toBe(404);
    expect((await api.get(`/app/personnel-documents/${fixture.document}`)).status()).toBe(404);
    expect((await api.post('/admin/settings/database', { form: { database: 'should-not-change' } })).status()).toBe(404);
    expect((await api.post('/api/v1/settings/database', { data: { database: 'should-not-change' } })).status()).toBe(404);
});

test('viewer is scoped, foreign and missing links are indistinguishable, and sensitive responses do not cache', async ({ request: api }) => {
    await signIn(api, fixture.viewer);
    const index = await api.get(root);
    const body = await index.text();
    expect(body).toContain(fixture.employee.name);
    expect(body).not.toContain(fixture.foreign.name);
    expect(body).not.toContain(fixture.foreign.number);
    expect(index.headers()['referrer-policy']).toBe('strict-origin');
    expect(index.headers()['x-content-type-options']).toBe('nosniff');
    const token = await csrf(api, `${root}/${fixture.document}`);
    const denied = [];
    for (const url of [`${root}/users/${fixture.foreign.id}`, `${root}/users/999999998`, `${root}/${fixture.foreignDocument}`, `${root}/999999998`]) {
        const response = await api.get(url);
        expect(response.status()).toBe(404);
        denied.push(await response.text());
    }
    expect(new Set(denied).size).toBe(1);
    const render = await api.get(`${root}/${fixture.document}`);
    const detail = await render.text();
    expect(detail).not.toContain('storage_key');
    expect(detail).not.toContain(fixture.scratchRoot);
    expect(detail).not.toContain('name="expected_predecessor"');
    for (const version of [fixture.foreignVersion, 999999998]) {
        const response = await api.post(download(fixture.document), { form: { csrf_token: token, version_id: version } });
        expect(response.status()).toBe(404);
        expect(await response.text()).toBe(denied[0]);
    }
    expect((await api.get(download(fixture.document))).status()).toBe(404);
    expect((await upload(api, fixture.document, token, png(), key(), 1)).status()).toBe(403);
    const allowed = await api.post(download(fixture.document), { form: { csrf_token: token, version_id: fixture.version } });
    expect(allowed.ok()).toBe(true);
    expect(allowed.headers()['content-disposition']).toMatch(/^attachment; filename="personalunterlage\.(pdf|jpg|png)"$/);
    expect(allowed.headers()['content-type']).toBe('application/octet-stream');
    expect(allowed.headers()['cache-control']).toContain('no-store');
    expect(allowed.headers()['x-content-type-options']).toBe('nosniff');

    scratchSql(`CREATE TRIGGER deny_fixture_access_audit BEFORE INSERT ON employee_document_access_log FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private fixture failure marker'`);
    try {
        const refused = await api.post(download(fixture.document), { form: { csrf_token: token, version_id: fixture.version } });
        expect(refused.status()).toBe(503);
        expect(refused.headers()['content-disposition']).toBeUndefined();
        expect(await refused.text()).not.toContain('private fixture failure marker');
    } finally {
        scratchSql('DROP TRIGGER deny_fixture_access_audit');
    }
});

test('explicit personnel permission, session-context CSRF and supported content type are mandatory', async ({ request: api, baseURL }) => {
    await signIn(api, fixture.outsider);
    expect((await api.get(root)).status()).toBe(403);
    await signIn(api, fixture.viewer);
    const staleToken = await csrf(api);
    await signIn(api, fixture.manager);
    const token = await csrf(api, `${root}/${fixture.document}`);
    expect(token).not.toBe(staleToken);
    const fields = { csrf_token: token, version_id: fixture.version };
    expect((await api.post(download(fixture.document), { form: { version_id: fixture.version } })).status()).toBe(403);
    expect((await api.post(download(fixture.document), { form: { ...fields, csrf_token: staleToken } })).status()).toBe(403);
    expect((await api.post(download(fixture.document), {
        form: fields, headers: { Origin: 'https://outside.example.test', 'Sec-Fetch-Site': 'cross-site' },
    })).status()).toBe(403);
    expect((await api.post(download(fixture.document), { data: fields, headers: { Origin: baseURL } })).status()).toBe(415);
    const createUrl = `${root}/users/${fixture.employee.id}/documents`;
    const values = { csrf_token: token, request_id: key(), document_type_id: fixture.type };
    expect((await api.post(createUrl, { form: { ...values, actor_id: fixture.admin.id }, maxRedirects: 0 })).status()).toBe(422);
    expect((await api.post(createUrl, { form: { ...values, document_type_id: 999999998 }, maxRedirects: 0 })).status()).toBe(422);
    const signOut = await api.post('/admin/logout', { maxRedirects: 0 });
    expect(signOut.status()).toBe(302);
    const expired = await upload(api, fixture.document, token, png(), key(), 1);
    expect(expired.status()).toBe(401);
});

test('scope and role revocation take effect during the active session and denials record no target', async ({ request: api }) => {
    await signIn(api, fixture.viewer);
    const token = await csrf(api, `${root}/${fixture.document}`);
    const viewer = numeric(fixture.viewer.id);
    const employee = numeric(fixture.employee.id);
    const actor = numeric(fixture.admin.id);
    const role = numeric(scratchSql(`SELECT role_id FROM user_roles WHERE user_id=${viewer} LIMIT 1`));
    const previousDenials = Number(scratchSql(`SELECT COUNT(*) FROM employee_document_access_log WHERE actor_user_id=${viewer} AND outcome='denied'`));
    const scope = (granted) => scratchSql(`INSERT INTO employee_personnel_scope_events
        (employee_user_id,grantee_user_id,changed_by_user_id,is_granted,reason_code)
        VALUES (${employee},${viewer},${actor},${granted},'fixture_test')`);
    try {
        scope(0);
        expect((await api.post(download(fixture.document), { form: { csrf_token: token, version_id: fixture.version } })).status()).toBe(404);
        expect((await api.get(`${root}/users/${employee}/audit`)).status()).toBe(404);
        scope(1);
        scratchSql(`DELETE FROM user_roles WHERE user_id=${viewer} AND role_id=${role}`);
        expect((await api.get(root)).status()).toBe(403);
    } finally {
        scope(1);
        scratchSql(`INSERT IGNORE INTO user_roles (user_id,role_id) VALUES (${viewer},${role})`);
    }
    expect(Number(scratchSql(`SELECT COUNT(*) FROM employee_document_access_log WHERE actor_user_id=${viewer} AND outcome='denied'`))).toBeGreaterThanOrEqual(previousDenials + 2);
    expect(scratchSql(`SELECT COUNT(*) FROM employee_document_access_log WHERE actor_user_id=${viewer} AND outcome='denied'
        AND (employee_user_id IS NOT NULL OR document_id IS NOT NULL OR document_version_id IS NOT NULL)`)).toBe('0');
});

test('upload enforces actual content, readiness and byte limits and arbitrates competing writes', async ({ request: api, baseURL }) => {
    await signIn(api, fixture.manager);
    const token = await csrf(api, `${root}/users/${fixture.employee.id}`);
    const created = await api.post(`${root}/users/${fixture.employee.id}/documents`, {
        form: { csrf_token: token, request_id: key(), document_type_id: fixture.type }, maxRedirects: 0,
    });
    expect(created.status()).toBe(303);
    const document = numeric(created.headers().location.split('/').at(-1));
    const first = png();
    expect((await upload(api, document, token, first, key(), 0, 'image/jpeg', 'synthetic.jpg')).status()).toBe(422);
    const unlisted = await upload(api, document, token, png(250), key(), 0);
    expect(unlisted.status()).toBe(422);
    expect((await unlisted.json()).message).toContain('vorbereitete synthetische Dateien');
    expect((await upload(api, document, token, first, key(), 0, 'image/png', 'synthetic.png', 2)).status()).toBe(409);
    expect((await upload(api, document, token, Buffer.alloc(16 * 1024 * 1024 + 1), key(), 0)).status()).toBe(413);

    const fileKey = path.join(fixture.scratchRoot, 'file-keys.json');
    const hiddenFileKey = `${fileKey}.test-offline`;
    fs.renameSync(fileKey, hiddenFileKey);
    try {
        expect((await upload(api, document, token, first, key(), 0)).status()).toBe(503);
    } finally {
        fs.renameSync(hiddenFileKey, fileKey);
    }
    const scanner = path.join(fixture.scratchRoot, 'scanner/clamd.sock');
    const hiddenScanner = `${scanner}.test-offline`;
    fs.renameSync(scanner, hiddenScanner);
    try {
        expect((await upload(api, document, token, first, key(), 0)).status()).toBe(503);
    } finally {
        fs.renameSync(hiddenScanner, scanner);
    }
    expect(scratchSql(`SELECT COUNT(*) FROM employee_document_versions WHERE document_id=${document}`)).toBe('0');

    const other = await request.newContext({ baseURL });
    try {
        await signIn(other, fixture.admin);
        const otherToken = await csrf(other, `${root}/${document}`);
        const attempts = [{ api, token, id: key(), bytes: first }, { api: other, token: otherToken, id: key(), bytes: png(180) }];
        const responses = await Promise.all(attempts.map((a) => upload(a.api, document, a.token, a.bytes, a.id, 0)));
        expect(responses.map((r) => r.status()).sort()).toEqual([200, 409]);
        const winner = attempts[responses.findIndex((r) => r.status() === 200)];
        expect((await upload(winner.api, document, winner.token, winner.bytes, winner.id, 0)).status()).toBe(200);
        expect((await upload(winner.api, document, winner.token, png(220), winner.id, 0)).status()).toBe(409);
        expect(scratchSql(`SELECT COUNT(*) FROM employee_document_versions WHERE document_id=${document}`)).toBe('1');
        const version = numeric(scratchSql(`SELECT id FROM employee_document_versions WHERE document_id=${document}`));
        const file = await api.post(download(document), { form: { csrf_token: token, version_id: version } });
        expect(file.ok()).toBe(true);
        expect(await file.body()).toEqual(winner.bytes);
    } finally {
        await other.dispose();
    }
});
