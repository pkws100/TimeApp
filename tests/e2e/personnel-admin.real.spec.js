const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const routes = '/admin/personnel-documents';
const fixturesPath = process.env.UI_PERSONNEL_FIXTURES;

test.describe('Digitale Personalakte im isolierten Testbetrieb', () => {
  test.skip(!fixturesPath, 'requires tests/Support/run-personnel-admin-ui.sh');
  const fixture = () => JSON.parse(fs.readFileSync(fixturesPath, 'utf8'));

  const login = async (page, account) => {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(account.email);
    await page.getByLabel('Passwort').fill(account.password);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page).not.toHaveURL(/\/admin\/login/);
  };

  const openAction = async (form) => {
    await form.locator('xpath=..').locator('summary').click();
    await expect(form).toBeVisible();
  };

  test('beschränkte Suche, Beschäftigungshistorie und archivierte Akten in beiden Farbschemata', async ({ page }) => {
    const { manager, employee, foreign, archived } = fixture();
    await login(page, manager);
    await page.goto(routes);
    const heading = page.getByRole('heading', { name: 'Digitale Personalakte', exact: true });
    await expect(heading).toBeVisible();
    await expect(page.getByRole('link', { name: 'Digitale Personalakte BETA', exact: true })).toBeVisible();
    const header = page.locator('.personnel-header');
    await expect(header.getByText('BETA', { exact: true })).toHaveCount(0);
    await expect(header.getByText('Synthetischer Testbetrieb', { exact: true })).toBeVisible();
    await expect(page.getByText(employee.name, { exact: true })).toBeVisible();
    await expect(page.getByText(foreign.name, { exact: true })).toHaveCount(0);
    const search = page.locator('form[action$="/search"]');
    await search.getByLabel('Name oder Personalnummer').fill(employee.number);
    await search.getByRole('button', { name: 'Suchen', exact: true }).click();
    await expect(page.getByText(employee.name, { exact: true })).toBeVisible();
    expect(page.url()).not.toContain(employee.number);

    await page.locator(`a[href="${routes}/users/${employee.id}"]`).click();
    await expect(page.getByRole('heading', { name: employee.name, exact: true })).toBeVisible();
    const employment = page.locator('#beschaeftigung');
    await expect(employment.getByText(/Minijob|Geringfügig/).first()).toBeVisible();
    await expect(employment.getByText(/Regulär/).first()).toBeVisible();
    await employment.getByLabel('Stichtag').fill('2026-06-01');
    await employment.getByRole('button', { name: 'Stichtag anzeigen' }).click();
    const early = employment.locator('summary').filter({ hasText: /Minijob|Geringfügig/ }).filter({ hasText: /wirksam/i });
    await expect(early).toBeVisible();
    await expect(employment.locator('summary').filter({ hasText: /Regulär/ }).filter({ hasText: /wirksam/i })).toHaveCount(0);

    for (const theme of ['dark', 'light']) {
      await page.evaluate(value => localStorage.setItem('app.theme', value), theme);
      await page.goto(`${routes}/users/${employee.id}`);
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
      for (const selector of ['.personnel-header', '#dokumente', '.content']) {
        expect(await page.locator(selector).evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
      }
      const links = page.locator('#dokumente').getByRole('link', { name: 'Dokument öffnen', exact: true });
      expect(await links.count()).toBeGreaterThanOrEqual(25);
      await links.last().scrollIntoViewIfNeeded();
      await expect(links.last()).toBeInViewport();
    }

    await page.goto(routes);
    await search.getByLabel('Beschäftigte').selectOption('archived');
    await search.getByRole('button', { name: 'Suchen', exact: true }).click();
    await page.locator(`a[href="${routes}/users/${archived.id}"]`).click();
    await expect(page.getByRole('heading', { name: archived.name, exact: true })).toBeVisible();
    await expect(page.locator('#neues-dokument')).toHaveCount(0);
  });

  test('Metadaten, drei Dateiformate, Netzretry, Nachweis, Sperre und Archivierung', async ({ page }) => {
    const { employee, manager, type, requirement, scratchRoot } = fixture();
    await login(page, manager);
    await page.goto(`${routes}/users/${employee.id}`);
    const create = page.locator('#neues-dokument form');
    await create.getByLabel('Dokumenttyp').selectOption(String(type));
    await create.getByLabel('Gesetzliche Regelrevision').selectOption(String(requirement));
    await create.getByLabel('Zeitraum ab').fill('2027-02-01');
    await create.getByLabel('Zeitraum bis').fill('2027-01-31');
    await create.getByRole('button', { name: 'Dokument anlegen', exact: true }).click();
    await expect(page.locator('[data-personnel-error]')).toBeVisible();
    await expect(create.getByLabel('Dokumenttyp')).toHaveValue(String(type));
    await expect(create.getByLabel('Gesetzliche Regelrevision')).toHaveValue(String(requirement));
    await expect(create.getByLabel('Zeitraum ab')).toHaveValue('2027-02-01');
    await create.getByLabel('Zeitraum ab').fill('2027-01-01');
    await create.getByRole('button', { name: 'Dokument anlegen', exact: true }).click();
    await expect(page.getByText('Dokumentmetadaten angelegt.', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/personnel-documents\/\d+$/);
    const documentPage = page.url();
    const upload = page.locator('[data-personnel-upload]');
    const input = upload.getByLabel('Lokale Datei');
    const uploadButton = upload.getByRole('button', { name: 'Datei hochladen', exact: true });
    const status = upload.locator('[data-personnel-upload-status]');
    const versions = page.locator('#versionen .personnel-version');

    const oversized = path.join(scratchRoot, 'oversize.png');
    fs.closeSync(fs.openSync(oversized, 'w', 0o600));
    fs.truncateSync(oversized, 16 * 1024 * 1024 + 1);
    await input.setInputFiles(oversized);
    await expect(status).toContainText('höchstens 16 MiB');
    await expect(versions).toHaveCount(0);
    const review = page.locator('form[action$="/review"]');
    await openAction(review);
    const reviewReason = review.locator('select[name="reason"]');
    await reviewReason.selectOption('insufficient_evidence');
    await input.setInputFiles({ name: 'invalid.png', mimeType: 'image/png', buffer: Buffer.from('synthetic invalid file') });
    const badUpload = page.waitForResponse(response => response.url().endsWith('/upload') && response.request().method() === 'POST');
    await uploadButton.click();
    expect((await badUpload).status()).toBe(422);
    await expect(status).toBeVisible();
    await expect.poll(() => input.evaluate(element => element.files[0]?.name)).toBe('invalid.png');
    await expect(reviewReason).toHaveValue('insufficient_evidence');
    await expect(versions).toHaveCount(0);
    await reviewReason.selectOption('');

    const first = path.join(scratchRoot, 'synthetic.png');
    await input.setInputFiles(first);
    await expect(upload.locator('[data-personnel-file-description]')).toContainText('PNG');
    const requestId = await upload.locator('input[name="request_id"]').inputValue();
    const csrf = await upload.locator('input[name="csrf_token"]').inputValue();
    const predecessor = await upload.locator('input[name="expected_predecessor"]').inputValue();
    const revision = await upload.locator('input[name="expected_revision"]').inputValue();
    let allowTransfer;
    const deferredTransfer = new Promise(resolve => { allowTransfer = resolve; });
    let intercepted = false;
    const loseResponse = async route => {
      if (intercepted) {
        await route.continue();
        return;
      }
      intercepted = true;
      try {
        await deferredTransfer;
        // Recreate this one request with the same cookies, command ID and bytes. WebKit's
        // interception API does not reliably preserve binary multipart bodies in route.fetch().
        const written = await page.request.post(route.request().url(), {
          headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf, 'Origin': new URL(page.url()).origin },
          multipart: { csrf_token: csrf, request_id: requestId, expected_predecessor: predecessor, expected_revision: revision,
            file: { name: 'synthetic.png', mimeType: 'image/png', buffer: fs.readFileSync(first) } }
        });
        expect(written.ok()).toBeTruthy();
      } finally {
        await route.abort('failed');
      }
    };
    await page.route('**/upload', loseResponse);
    await uploadButton.click();
    try {
      await expect(upload.locator('[data-personnel-upload-progress]')).toBeVisible();
      await expect(uploadButton).toBeDisabled();
    } finally {
      allowTransfer();
    }
    await expect(status).toContainText(/Wiederhol|Netzwerk|Verbindung/i);
    await expect(upload.locator('input[name="request_id"]')).toHaveValue(requestId);
    await expect.poll(() => input.evaluate(element => element.files[0]?.name)).toBe('synthetic.png');
    await page.unroute('**/upload', loseResponse);
    await upload.getByRole('button', { name: 'Denselben Upload wiederholen' }).click();
    await expect(versions).toHaveCount(1);

    const examples = ['synthetic.png', 'synthetic.jpg', 'synthetic.pdf'];
    for (let index = 1; index < examples.length; index++) {
      await input.setInputFiles(path.join(scratchRoot, examples[index]));
      await uploadButton.click();
      await expect(versions).toHaveCount(index + 1);
    }
    await expect(page.getByRole('heading', { name: 'Version 1', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Version 3', exact: true })).toBeVisible();
    for (const [index, filename] of examples.entries()) {
      const version = versions.filter({ has: page.getByRole('heading', { name: `Version ${index + 1}`, exact: true }) });
      const downloadPending = page.waitForEvent('download');
      await version.getByRole('button', { name: 'Version herunterladen' }).click();
      const download = await downloadPending;
      expect(download.suggestedFilename()).toBe(`personalunterlage${path.extname(filename)}`);
      expect(fs.readFileSync(await download.path())).toEqual(fs.readFileSync(path.join(scratchRoot, filename)));
      await download.delete();
    }

    await expect(page.locator('#nachweise')).toContainText('kein fachlicher Nachweis');
    const evidence = page.locator('form[action$="/evidence"]');
    await openAction(evidence);
    await evidence.getByLabel('Dokumentversion').selectOption({ label: 'Version 3' });
    await evidence.getByLabel('Prüfergebnis').selectOption('confirmed_internal');
    await evidence.getByLabel('Art des Originals').selectOption('paper');
    await evidence.locator('select[name="paper_status"]').selectOption('retained');
    await evidence.getByLabel('Prüfbegründung').selectOption('visual_checks_confirmed');
    await evidence.getByLabel('Inhalt vollständig lesbar').check();
    await evidence.getByLabel('Inhalt dem Eintrag eindeutig zugeordnet').check();
    await evidence.getByRole('button', { name: 'Prüfstand speichern' }).click();
    await expect(page.getByText('Nachweis für die gewählte Version erfasst.', { exact: true })).toBeVisible();
    await expect(page.locator('#nachweise')).toContainText('Signatur ungeklärt');
    await expect(page.locator('#nachweise')).toContainText('Version 3');

    await openAction(review);
    await review.locator('select[name="reason"]').selectOption('insufficient_evidence');
    await review.getByRole('button', { name: 'Dokument beanstanden' }).click();
    await expect(page.getByText('Fachlicher Status gespeichert.', { exact: true })).toBeVisible();
    await expect(upload).toHaveCount(0);

    const hold = page.locator('form[action$="/hold"]');
    await openAction(hold);
    await hold.locator('select[name="reason"]').selectOption('legal_hold');
    await hold.getByRole('button', { name: 'Sperre setzen' }).click();
    await expect(page.getByText('Aufbewahrungssperre gesetzt.', { exact: true })).toBeVisible();
    const archive = page.locator('form[action$="/archive"]');
    await expect(archive).toHaveCount(0);
    await openAction(hold);
    await hold.locator('select[name="reason"]').selectOption('hold_resolved');
    await hold.getByRole('checkbox').check();
    await hold.getByRole('button', { name: 'Sperre aufheben' }).click();
    await expect(page.getByText('Aufbewahrungssperre aufgehoben.', { exact: true })).toBeVisible();
    await openAction(archive);
    await archive.locator('select[name="reason"]').selectOption('duplicate');
    await archive.getByRole('checkbox').check();
    await archive.getByRole('button', { name: 'Dokument archivieren' }).click();
    await expect(page.getByText('Dokument archiviert.', { exact: true })).toBeVisible();
    await expect(versions).toHaveCount(3);
    await expect(page.locator('#nachweise')).toContainText('Signatur ungeklärt');
    await expect(page.locator('#audit')).toContainText(/Archiviert|archiviert/);
    await page.locator(`a[href="${routes}/users/${employee.id}"]`).last().click();
    await expect(page.getByRole('heading', { name: employee.name, exact: true })).toBeVisible();
    await page.goBack();
    await expect(page).toHaveURL(documentPage);
    await expect(versions).toHaveCount(3);
    const persisted = await page.evaluate(async () => ({
      local: [...Object.entries(localStorage), ...Object.entries(sessionStorage)],
      caches: typeof caches === 'undefined' ? [] : await caches.keys(),
      indexed: typeof indexedDB.databases === 'function' ? (await indexedDB.databases()).map(database => database.name) : []
    }));
    expect(JSON.stringify(persisted)).not.toMatch(/personalakte|personnel|synthetic\.(png|pdf|jpg)|PC-101/i);
  });

  test('eine falsche Zuordnung archiviert das bisherige Dokument und legt ein neues an', async ({ page }) => {
    const { admin, employee, foreign, type } = fixture();
    await login(page, admin);
    await page.goto(`${routes}/users/${employee.id}`);
    const create = page.locator('#neues-dokument form');
    await create.getByLabel('Dokumenttyp').selectOption(String(type));
    await create.getByRole('button', { name: 'Dokument anlegen', exact: true }).click();
    await expect(page.getByText('Dokumentmetadaten angelegt.', { exact: true })).toBeVisible();
    const oldDocument = page.url();

    await page.getByRole('link', { name: 'Zuordnung korrigieren' }).click();
    const choose = page.locator('form[action$="/correction-target"]').filter({ has: page.locator('select[name="replacement_employee_id"]') });
    await choose.getByLabel('Name oder Personalnummer').fill(foreign.number);
    await choose.getByRole('button', { name: 'Mitarbeiter suchen' }).click();
    await choose.getByLabel('Mitarbeiter für die neue Zuordnung').selectOption(String(foreign.id));
    await choose.getByRole('button', { name: 'Mitarbeiter übernehmen' }).click();
    const correct = page.locator('form[action$="/correct"]');
    await expect(correct.getByLabel('Dokumenttyp')).toHaveValue(String(type));
    await correct.getByRole('checkbox').check();
    await correct.getByRole('button', { name: 'Korrigierte Zuordnung speichern' }).click();
    await expect(page.getByText(/Die neue Zuordnung wurde angelegt/)).toBeVisible();
    await expect(page).not.toHaveURL(oldDocument);
    await expect(page.getByRole('link', { name: foreign.name, exact: true })).toBeVisible();
    await page.goto(oldDocument);
    await expect(page.locator('.personnel-summary')).toContainText(/Archiviert/i);
    await expect(page.locator('#audit')).toContainText(/Zuordnung korrigiert/i);
  });
});
