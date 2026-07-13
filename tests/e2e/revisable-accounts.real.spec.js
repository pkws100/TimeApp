const { test, expect } = require('@playwright/test');

test.describe('real revisable account workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 60000 });
  test.skip(
    process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1',
    'requires the isolated MariaDB UI runner'
  );

  test('admin finalizes, corrects, reverses and reopens an account', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.goto('/admin/time-accounts');

    const cutoverForm = page.locator('form[action="/admin/time-accounts/cutovers/preview"]');
    const cutoverUserSelect = cutoverForm.locator('select[name="user_id"]');
    const employeeId = await cutoverUserSelect.locator('option', { hasText: 'UI Mitarbeiter' }).getAttribute('value');
    await cutoverUserSelect.selectOption(employeeId);
    await cutoverForm.locator('input[name="effective_from"]').fill('2026-01-01');
    await cutoverForm.locator('input[name="opening_time_balance"]').fill('0:00');
    await cutoverForm.locator('input[name="leave_year"]').fill('2026');
    await cutoverForm.locator('input[name="annual_leave_entitlement_days"]').fill('30');
    await cutoverForm.locator('input[name="leave_carryover_days"]').fill('0');
    await cutoverForm.locator('input[name="opening_remaining_leave_days"]').fill('30');
    await cutoverForm.getByRole('button', { name: 'Vorschau pruefen' }).click();
    await expect(page.getByRole('heading', { name: 'Stichtag-Vorschau' })).toBeVisible();
    await page.getByRole('button', { name: 'Stichtag verbindlich finalisieren' }).click();
    await expect(page.getByText('Stichtag wurde finalisiert.')).toBeVisible();

    const protocolLink = page.getByRole('link', { name: 'Protokoll' }).first();
    const protocolResponse = await page.context().request.get(await protocolLink.getAttribute('href'));
    expect(protocolResponse.ok()).toBeTruthy();
    expect(protocolResponse.headers()['content-type']).toContain('application/pdf');

    const timeForm = page.locator('form[action="/admin/time-accounts/entries/time"]');
    await timeForm.locator('select[name="user_id"]').selectOption(employeeId);
    await timeForm.locator('input[name="effective_date"]').fill('2026-02-02');
    await timeForm.locator('input[name="minutes"]').fill('+02:00');
    await timeForm.locator('textarea[name="reason"]').fill('Playwright Zeitkorrektur');
    await timeForm.getByRole('button', { name: 'Zeitkonto buchen' }).click();
    await expect(page.getByText('Zeitkonto-Korrektur wurde gebucht.')).toBeVisible();

    const reversalForm = page.locator('form[action^="/admin/time-accounts/entries/time/"][action$="/reverse"]').first();
    await reversalForm.locator('xpath=ancestor::details').locator('summary').click();
    await reversalForm.locator('input[name="reason"]').fill('Playwright Gegenbuchung');
    await reversalForm.getByRole('button', { name: 'Ausgleichen' }).click();
    await expect(page.getByText('Zeitkonto-Buchung wurde ausgeglichen.')).toBeVisible();

    const vacationForm = page.locator('form[action="/admin/time-accounts/entries/vacation"]');
    await vacationForm.locator('select[name="user_id"]').selectOption(employeeId);
    await vacationForm.locator('input[name="leave_year"]').fill('2026');
    await vacationForm.locator('input[name="effective_date"]').fill('2026-02-03');
    await vacationForm.locator('input[name="days"]').fill('1');
    await vacationForm.locator('textarea[name="reason"]').fill('Playwright Urlaubskorrektur');
    await vacationForm.getByRole('button', { name: 'Urlaubskonto buchen' }).click();
    await expect(page.getByText('Urlaubskonto-Korrektur wurde gebucht.')).toBeVisible();

    const vacationReversalForm = page.locator('form[action^="/admin/time-accounts/entries/vacation/"][action$="/reverse"]').first();
    await vacationReversalForm.locator('xpath=ancestor::details').locator('summary').click();
    await vacationReversalForm.locator('input[name="reason"]').fill('Playwright Urlaubsgegenbuchung');
    await vacationReversalForm.getByRole('button', { name: 'Ausgleichen' }).click();
    await expect(page.getByText('Urlaubskonto-Buchung wurde ausgeglichen.')).toBeVisible();

    await page.goto('/admin/time-accounts/users/' + employeeId + '/entries?view=html&year=2026&limit=50&page=1');
    const balancedTimeRow = page.locator('tr').filter({ hasText: 'Playwright Zeitkorrektur' });
    const balancedVacationRow = page.locator('tr').filter({ hasText: 'Playwright Urlaubskorrektur' });
    await expect(balancedTimeRow.getByRole('button', { name: 'Ausgleichen' })).toHaveCount(0);
    await expect(balancedVacationRow.getByRole('button', { name: 'Ausgleichen' })).toHaveCount(0);
    await page.goto('/admin/time-accounts');

    const reverseCutover = page.locator('form[action^="/admin/time-accounts/cutovers/"][action$="/reverse"]').first();
    await reverseCutover.locator('input[name="reason"]').fill('Playwright Neuanlage');
    await reverseCutover.getByRole('button', { name: 'Revidieren' }).click();
    await expect(page.getByText('Stichtag wurde revidiert.')).toBeVisible();

    await cutoverUserSelect.selectOption(employeeId);
    await cutoverForm.locator('input[name="effective_from"]').fill('2026-03-01');
    await cutoverForm.locator('input[name="opening_time_balance"]').fill('+15:00');
    await cutoverForm.locator('input[name="leave_year"]').fill('2026');
    await cutoverForm.locator('input[name="annual_leave_entitlement_days"]').fill('30');
    await cutoverForm.locator('input[name="leave_carryover_days"]').fill('0');
    await cutoverForm.locator('input[name="opening_remaining_leave_days"]').fill('30');
    await cutoverForm.getByRole('button', { name: 'Vorschau pruefen' }).click();
    await page.getByRole('button', { name: 'Stichtag verbindlich finalisieren' }).click();
    await page.locator('details').filter({ hasText: 'opening_balance +15:00' }).locator('summary').click();
    await expect(page.getByText('+15:00').first()).toBeVisible();
    await page.getByRole('link', { name: 'Detailhistorie' }).click();
    await expect(page.getByRole('heading', { name: 'Detailhistorie' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Zeitkonto-Journal' })).toBeVisible();
    await page.getByRole('link', { name: 'Zur Zeitkonto-Uebersicht' }).click();

    const paginationUserId = process.env.UI_TEST_PAGINATION_USER_ID;
    await page.goto('/admin/time-accounts/users/' + paginationUserId + '/entries?view=html&year=2026&limit=50&page=1');
    const timeJournal = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Zeitkonto-Journal' }) });
    await expect(timeJournal.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('link', { name: 'Weiter' }).first().click();
    await expect(page.getByText('Seite 2').first()).toBeVisible();
    await expect(timeJournal.locator('tbody tr')).toHaveCount(50);
    await page.getByRole('link', { name: 'Weiter' }).first().click();
    await expect(page.getByText('Seite 3').first()).toBeVisible();
    await expect(timeJournal.locator('tbody tr')).toHaveCount(5);

    const restoreBookingId = process.env.UI_TEST_RESTORE_BOOKING_ID;
    await page.goto('/admin/bookings?scope=archived&modal=edit&booking_id=' + restoreBookingId);
    const restoreForm = page.locator('form[action="/admin/bookings/' + restoreBookingId + '/restore"]');
    await restoreForm.locator('textarea[name="change_reason"]').fill('Playwright Restore-Konflikt');
    await restoreForm.getByRole('button', { name: 'Wiederherstellen' }).click();
    await expect(page.getByText('Die Buchung konnte nicht wiederhergestellt werden. Bitte Begruendung und Datensatz pruefen.')).toBeVisible();

    await page.goto('/admin/settings/calendar?year=2026');
    const closureForm = page.locator('form[action="/admin/settings/calendar/closures"]');
    await closureForm.locator('input[name="title"]').fill('Playwright Jahreswechsel');
    await closureForm.locator('input[name="date_from"]').fill('2026-12-29');
    await closureForm.locator('input[name="date_to"]').fill('2027-01-03');
    await closureForm.getByRole('button', { name: 'Betriebsurlaub anlegen' }).click();
    await expect(page.getByText('Playwright Jahreswechsel')).toBeVisible();
    await page.goto('/admin/settings/calendar?year=2027');
    await expect(page.getByText('Playwright Jahreswechsel')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => localStorage.setItem('app.theme', 'dark'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
  });

  test('employee sees the own active account on mobile', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light' });
    const page = await context.newPage();
    await page.addInitScript(() => localStorage.setItem('app.theme', 'dark'));
    await page.goto('/app/login');
    await page.locator('#loginForm input[name="email"]').fill(process.env.UI_TEST_EMPLOYEE_EMAIL);
    await page.locator('#loginForm input[name="password"]').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
    await page.locator('#loginForm button[type="submit"]').click();
    await page.waitForURL('**/app/heute');
    await page.goto('/app/urlaub');
    await expect(page.getByText('+15:00').first()).toBeVisible();
    await expect(page.locator('.app-info-row').filter({ hasText: 'Resturlaub' }).getByText('30,00 Tage', { exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await context.close();
  });
});
