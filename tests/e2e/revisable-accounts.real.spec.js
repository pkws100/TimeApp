const { test, expect } = require('@playwright/test');

test.describe('real revisable account workflow', () => {
  test.describe.configure({ mode: 'serial', timeout: 60000 });
  test.skip(
    process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1',
    'requires the isolated MariaDB UI runner'
  );

  test('admin finalizes, corrects, reverses and reopens an account', async ({ page }) => {
    const accountYear = Number(process.env.UI_TEST_ACCOUNT_YEAR);
    const accountDate = (monthDay) => accountYear + '-' + monthDay;
    expect(accountYear).toBeGreaterThanOrEqual(2000);
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.goto('/admin/time-accounts');

    const cutoverForm = page.locator('form[action="/admin/time-accounts/cutovers/preview"]');
    const cutoverUserSelect = cutoverForm.locator('select[name="user_id"]');
    const employeeId = await cutoverUserSelect.locator('option', { hasText: 'UI Mitarbeiter' }).getAttribute('value');
    await cutoverUserSelect.selectOption(employeeId);
    await cutoverForm.locator('input[name="effective_from"]').fill(accountDate('01-01'));
    await cutoverForm.locator('input[name="opening_time_balance"]').fill('0:00');
    await cutoverForm.locator('input[name="leave_year"]').fill(String(accountYear));
    await cutoverForm.locator('input[name="annual_leave_entitlement_days"]').fill('30');
    await cutoverForm.locator('input[name="leave_carryover_days"]').fill('0');
    await cutoverForm.locator('input[name="opening_remaining_leave_days"]').fill('30');
    await cutoverForm.getByRole('button', { name: 'Vorschau pruefen' }).click();
    await expect(page.getByRole('heading', { name: 'Stichtag-Vorschau' })).toBeVisible();
    await page.getByRole('button', { name: 'Stichtag verbindlich finalisieren' }).click();
    await expect(page.getByText('Stichtag wurde finalisiert.')).toBeVisible();

    await page.goto('/admin/users/' + employeeId + '/edit');
    await page.locator('input[name="phone"]').fill('+49 123 456');
    await page.locator('input[name="target_hours_week"]').fill('0');
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/\/admin\/users\/\d+\/edit\?notice=updated$/);
    await expect(page.locator('input[name="phone"]')).toHaveValue('+49 123 456');
    await page.goto('/admin/time-accounts');

    const protocolLink = page.getByRole('link', { name: 'Protokoll' }).first();
    const protocolResponse = await page.context().request.get(await protocolLink.getAttribute('href'));
    expect(protocolResponse.ok()).toBeTruthy();
    expect(protocolResponse.headers()['content-type']).toContain('application/pdf');
    expect(protocolResponse.headers()['x-time-account-cutover-status']).toBe('final');

    const vacationYear = Number(process.env.UI_TEST_VACATION_YEAR);
    const vacationDate = process.env.UI_TEST_VACATION_DATE;
    expect(vacationYear).toBeGreaterThanOrEqual(accountYear);
    expect(vacationDate).toBeTruthy();
    await page.goto('/admin/vacation-requests?year=' + vacationYear + '&status=pending&user_id=' + employeeId);
    const vacationAccountRow = page.locator('.vacation-account-desktop tbody tr').filter({ hasText: 'UI Mitarbeiter' });
    await expect(page.getByRole('heading', { name: 'Urlaubskonten und Urlaubsantraege' })).toBeVisible();
    await expect(vacationAccountRow.locator('td').nth(6)).toContainText('30,00 Tage');
    await expect(vacationAccountRow.locator('td').nth(7)).toContainText('29,00 Tage');
    await expect(page.locator('tr').filter({ hasText: vacationDate }).getByRole('button', { name: 'Genehmigen' })).toBeVisible();
    await page.locator('tr').filter({ hasText: vacationDate }).getByRole('button', { name: 'Genehmigen' }).click();
    await expect(page.getByText('Urlaubsantrag genehmigt.')).toBeVisible();
    await expect(vacationAccountRow.locator('td').nth(4)).toContainText('1,00 Tage');
    await expect(vacationAccountRow.locator('td').nth(5)).toContainText('0,00 Tage');
    await expect(vacationAccountRow.locator('td').nth(6)).toContainText('29,00 Tage');
    await expect(vacationAccountRow.locator('td').nth(7)).toContainText('29,00 Tage');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => localStorage.setItem('app.theme', 'dark'));
    await page.reload();
    const vacationAccountCard = page.locator('.vacation-account-card').filter({ hasText: 'UI Mitarbeiter' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(vacationAccountCard).toBeVisible();
    await expect(vacationAccountCard.locator('.is-emphasized').filter({ hasText: 'Resturlaub' })).toContainText('29,00 Tage');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await page.evaluate(() => localStorage.setItem('app.theme', 'light'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/time-accounts');

    const timeForm = page.locator('form[action="/admin/time-accounts/entries/time"]');
    await timeForm.locator('select[name="user_id"]').selectOption(employeeId);
    await timeForm.locator('input[name="effective_date"]').fill(accountDate('02-02'));
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
    await vacationForm.locator('input[name="leave_year"]').fill(String(accountYear));
    await vacationForm.locator('input[name="effective_date"]').fill(accountDate('02-03'));
    await vacationForm.locator('input[name="days"]').fill('1');
    await vacationForm.locator('textarea[name="reason"]').fill('Playwright Urlaubskorrektur');
    await vacationForm.getByRole('button', { name: 'Urlaubskonto buchen' }).click();
    await expect(page.getByText('Urlaubskonto-Korrektur wurde gebucht.')).toBeVisible();

    const vacationReversalForm = page.locator('form[action^="/admin/time-accounts/entries/vacation/"][action$="/reverse"]').first();
    await vacationReversalForm.locator('xpath=ancestor::details').locator('summary').click();
    await vacationReversalForm.locator('input[name="reason"]').fill('Playwright Urlaubsgegenbuchung');
    await vacationReversalForm.getByRole('button', { name: 'Ausgleichen' }).click();
    await expect(page.getByText('Urlaubskonto-Buchung wurde ausgeglichen.')).toBeVisible();

    await page.goto('/admin/time-accounts/users/' + employeeId + '/entries?view=html&year=' + accountYear + '&limit=50&page=1');
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
    await cutoverForm.locator('input[name="effective_from"]').fill(accountDate('01-01'));
    await cutoverForm.locator('input[name="opening_time_balance"]').fill('+15:00');
    await cutoverForm.locator('input[name="leave_year"]').fill(String(accountYear));
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

    await page.getByRole('link', { name: 'Stichtagshistorie' }).first().click();
    await expect(page.getByRole('heading', { name: 'Stichtagshistorie' })).toBeVisible();
    const historyRows = page.locator('tbody tr');
    await expect(historyRows).toHaveCount(2);
    const finalGeneration = historyRows.filter({ hasText: 'Final' });
    const reversedGeneration = historyRows.filter({ hasText: 'Revidiert' });
    await expect(finalGeneration).toHaveCount(1);
    await expect(reversedGeneration).toHaveCount(1);
    const reversedProtocolResponse = await page.context().request.get(
      await reversedGeneration.getByRole('link', { name: 'Protokoll' }).getAttribute('href')
    );
    expect(reversedProtocolResponse.ok()).toBeTruthy();
    expect(reversedProtocolResponse.headers()['content-type']).toContain('application/pdf');
    expect(reversedProtocolResponse.headers()['x-time-account-cutover-status']).toBe('reversed');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => localStorage.setItem('app.theme', 'light'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await page.evaluate(() => localStorage.setItem('app.theme', 'dark'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await reversedGeneration.getByRole('link', { name: 'Journal' }).click();
    await expect(page.getByText('Diese historische Stichtagsgeneration ist revisionssicher und nur lesbar.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ausgleichen' })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await page.setViewportSize({ width: 1440, height: 900 });

    const paginationUserId = process.env.UI_TEST_PAGINATION_USER_ID;
    await page.goto('/admin/time-accounts/users/' + paginationUserId + '/entries?view=html&year=' + accountYear + '&limit=50&page=1');
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

    const closureYear = process.env.UI_TEST_CLOSURE_YEAR;
    const closureDateFrom = process.env.UI_TEST_CLOSURE_DATE_FROM;
    const closureDateTo = process.env.UI_TEST_CLOSURE_DATE_TO;
    expect(closureYear).toBeTruthy();
    expect(closureDateFrom).toBeTruthy();
    expect(closureDateTo).toBeTruthy();
    await page.goto('/admin/settings/calendar?year=' + closureYear);
    const closureForm = page.locator('form[action="/admin/settings/calendar/closures"]');
    await closureForm.locator('input[name="title"]').fill('Playwright Jahreswechsel');
    await closureForm.locator('input[name="date_from"]').fill(closureDateFrom);
    await closureForm.locator('input[name="date_to"]').fill(closureDateTo);
    await closureForm.getByRole('button', { name: 'Betriebsurlaub anlegen' }).click();
    await expect(page.getByText('Playwright Jahreswechsel')).toBeVisible();
    await page.goto('/admin/settings/calendar?year=' + (Number(closureYear) + 1));
    await expect(page.getByText('Playwright Jahreswechsel')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => localStorage.setItem('app.theme', 'dark'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
  });

  test('employee sees the own active account on mobile', async ({ browser }) => {
    const expectedRestVacation = Number(process.env.UI_TEST_VACATION_YEAR) === Number(process.env.UI_TEST_ACCOUNT_YEAR)
      ? '29,00 Tage'
      : '30,00 Tage';
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light' });
    const page = await context.newPage();
    await page.goto('/app/login');
    await page.evaluate(() => localStorage.setItem('app.theme', 'dark'));
    await page.reload();
    await page.locator('#loginForm input[name="email"]').fill(process.env.UI_TEST_EMPLOYEE_EMAIL);
    await page.locator('#loginForm input[name="password"]').fill(process.env.UI_TEST_EMPLOYEE_PASSWORD);
    await page.locator('#loginForm button[type="submit"]').click();
    await page.waitForURL('**/app/heute');
    const summaryResponsePromise = page.waitForResponse((response) => response.url().includes('/api/v1/app/time-account/summary'));
    await page.goto('/app/urlaub');
    const summaryResponse = await summaryResponsePromise;
    const summaryPayload = await summaryResponse.json();
    const employeeBalance = summaryPayload.data.employee_balance;
    const expectedBalanceTitle = {
      positive: 'Plusstunden',
      negative: 'Fehlstunden laut aktuellem Buchungsstand',
      balanced: 'Zeitkonto ausgeglichen',
      not_configured: 'Zeitkonto noch nicht eingerichtet',
      not_active: summaryPayload.data.cutover_date
        ? 'Zeitkonto ab ' + summaryPayload.data.cutover_date.split('-').reverse().join('.') + ' aktiv'
        : 'Zeitkonto noch nicht aktiv'
    }[employeeBalance.status];
    await expect(page.locator('#employeeBalanceTitle')).toHaveText(expectedBalanceTitle);
    if (employeeBalance.label) {
      await expect(page.locator('.app-account-balance-value')).toHaveText(employeeBalance.label);
    } else {
      await expect(page.locator('.app-account-balance-value')).toHaveCount(0);
    }
    expect(employeeBalance.current_day_included).toBe(false);
    expect(employeeBalance.calculation_basis).toBe('completed_calendar_days');
    await expect(page.getByText(/der heutige Tag ist noch nicht enthalten/)).toBeVisible();
    await expect(page.locator('.app-info-row').filter({ hasText: 'Resturlaub' }).getByText(expectedRestVacation, { exact: true })).toBeVisible();
    await page.locator('.app-account-details summary').click();
    await expect(page.getByText('Bezahlte Abwesenheiten', { exact: true })).toBeVisible();
    await expect(page.getByText(/individuellen Normarbeitszeit/)).toBeVisible();
    await page.route('**/api/v1/app/time-account/entries?limit=10', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          data: {
            cutover_id: 999999,
            time_entries: [{ id: 1, description: 'Fremde Stichtagsgeneration' }],
            vacation_entries: []
          }
        })
      });
    });
    await page.reload();
    await expect(page.locator('.app-info-row').filter({ hasText: 'Resturlaub' }).getByText(expectedRestVacation, { exact: true })).toBeVisible();
    await expect(page.getByText('Fremde Stichtagsgeneration')).toHaveCount(0);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.evaluate(() => localStorage.setItem('app.theme', 'light'));
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('.app-info-row').filter({ hasText: 'Resturlaub' }).getByText(expectedRestVacation, { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    await context.close();
  });

  test('admin creates, corrects and validates an absence period atomically', async ({ page }) => {
    const employeeId = process.env.UI_TEST_EMPLOYEE_ID;
    const dateFrom = process.env.UI_TEST_ABSENCE_DATE_FROM;
    const dateShortTo = process.env.UI_TEST_ABSENCE_DATE_SHORT_TO;
    const dateTo = process.env.UI_TEST_ABSENCE_DATE_TO;
    const dateExtendedTo = process.env.UI_TEST_ABSENCE_DATE_EXTENDED_TO;
    const conflictDate = process.env.UI_TEST_ABSENCE_CONFLICT_DATE;
    const year = dateFrom.slice(0, 4);

    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.goto('/admin/vacation-requests?year=' + year + '&user_id=' + employeeId);
    const accountRow = page.locator('section.vacation-account-overview .vacation-account-desktop tbody tr')
      .filter({ hasText: 'UI Mitarbeiter' });
    const parseDays = (value) => Number(String(value).replace(/[^\d,-]/g, '').replace('.', '').replace(',', '.'));
    const remainingBefore = parseDays(await accountRow.locator('td').nth(6).innerText());

    await page.getByRole('button', { name: 'Urlaub fuer Mitarbeiter buchen' }).click();
    const modal = page.locator('[data-absence-period-modal]');
    await expect(modal).toBeVisible();
    await modal.locator('select[name="user_id"]').selectOption(employeeId);
    await modal.locator('input[name="date_from"]').fill(dateFrom);
    await modal.locator('input[name="date_to"]').fill(dateTo);
    await modal.locator('textarea[name="note"]').fill('Playwright Von-bis-Urlaub');
    await modal.locator('textarea[name="change_reason"]').fill('Playwright Neuanlage');
    await modal.getByRole('button', { name: 'Zeitraum pruefen' }).click();
    await expect(modal.locator('[data-absence-period-preview]')).toContainText('Buchungstage');
    await expect(modal.locator('[data-absence-period-preview]')).not.toContainText('Blockiert');
    await modal.getByRole('button', { name: 'Verbindlich speichern' }).click();

    const directSection = page.locator('section').filter({
      has: page.getByRole('heading', { name: 'Direkt gebuchte Urlaubszeitraeume' })
    });
    const directRow = directSection.locator('tbody tr').filter({ hasText: 'UI Mitarbeiter' });
    await expect(directRow).toContainText(dateFrom + ' bis ' + dateTo);
    const remainingAfter = parseDays(await accountRow.locator('td').nth(6).innerText());
    expect(remainingAfter).toBeLessThan(remainingBefore);

    await directSection.locator('[data-absence-period-edit]:visible').click();
    await expect(modal).toBeVisible();
    await expect(modal.locator('select[name="user_id"]')).toBeDisabled();
    await modal.locator('input[name="date_to"]').fill(dateShortTo);
    await modal.locator('textarea[name="change_reason"]').fill('Playwright Verkuerzung');
    await modal.getByRole('button', { name: 'Zeitraum pruefen' }).click();
    await expect(modal.locator('[data-absence-period-preview]')).toContainText('entfaellt');
    await modal.getByRole('button', { name: 'Verbindlich speichern' }).click();
    await expect(directSection.locator('tbody tr').filter({ hasText: 'UI Mitarbeiter' }))
      .toContainText(dateFrom + ' bis ' + dateShortTo);

    await directSection.locator('[data-absence-period-edit]:visible').click();
    await expect(modal).toBeVisible();
    await modal.locator('input[name="date_to"]').fill(dateExtendedTo);
    await modal.locator('textarea[name="change_reason"]').fill('Playwright Verlaengerung');
    await modal.getByRole('button', { name: 'Zeitraum pruefen' }).click();
    await expect(modal.locator('[data-absence-period-preview]')).toContainText('neu');
    await modal.getByRole('button', { name: 'Verbindlich speichern' }).click();
    await expect(directSection.locator('tbody tr').filter({ hasText: 'UI Mitarbeiter' }))
      .toContainText(dateFrom + ' bis ' + dateExtendedTo);

    await page.goto('/admin/calendar?month=' + dateFrom.slice(0, 7) + '&date=' + dateFrom);
    await expect(page.locator('.calendar-bookings-card')).toContainText('Urlaub');
    const periodButton = page.getByRole('button', { name: 'Gesamten Zeitraum bearbeiten' });
    await expect(periodButton).toBeVisible();
    await periodButton.click();
    await expect(page).toHaveURL(/absence_period_id=\d+/);
    await page.goBack();
    await expect(page).not.toHaveURL(/absence_period_id=/);
    await expect(modal).toBeHidden();
    await page.goForward();
    await expect(page).toHaveURL(/absence_period_id=\d+/);
    await expect(modal).toBeVisible();
    await page.reload();
    await expect(modal).toBeVisible();
    await modal.getByRole('button', { name: 'Schliessen' }).click();
    await expect(page).not.toHaveURL(/absence_period_id=/);

    await page.goto('/admin/calendar?month=' + conflictDate.slice(0, 7) + '&date=' + conflictDate);
    await page.getByRole('button', { name: 'Abwesenheit nacherfassen' }).click();
    await modal.locator('select[name="user_id"]').selectOption(employeeId);
    await modal.locator('input[name="date_from"]').fill(conflictDate);
    await modal.locator('input[name="date_to"]').fill(conflictDate);
    await modal.locator('textarea[name="change_reason"]').fill('Playwright Konfliktpruefung');
    await modal.getByRole('button', { name: 'Zeitraum pruefen' }).click();
    await expect(modal.locator('[data-absence-period-preview]')).toContainText('Blockiert');
    await expect(modal.getByRole('button', { name: 'Verbindlich speichern' })).toBeDisabled();

    await modal.locator('input[name="date_from"]').fill(dateFrom);
    await modal.locator('input[name="date_to"]').fill('2070-12-12');
    await modal.getByRole('button', { name: 'Zeitraum pruefen' }).click();
    await expect(modal.locator('[data-absence-period-error]')).toContainText('366');
    await expect(modal.locator('[data-absence-period-error]')).toBeFocused();
    await expect(modal.getByRole('button', { name: 'Verbindlich speichern' })).toBeDisabled();

    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    const modalBounds = await modal.locator('.absence-period-modal__dialog').boundingBox();
    expect(modalBounds.x).toBe(0);
    expect(modalBounds.y).toBe(0);
    expect(Math.round(modalBounds.width)).toBe(390);
    expect(Math.round(modalBounds.height)).toBe(844);
    const lastFocusable = modal.getByRole('button', { name: 'Zeitraum pruefen' });
    await lastFocusable.focus();
    await page.keyboard.press('Tab');
    await expect(modal.getByRole('button', { name: 'Schliessen' })).toBeFocused();
  });
});
