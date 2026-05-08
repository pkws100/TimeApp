const path = require('path');
const { test, expect } = require('@playwright/test');

test('mobile app login screen loads', async ({ page }) => {
  await page.goto('/app/login');

  await expect(page.locator('#loginForm')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="password"]')).toBeVisible();
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
