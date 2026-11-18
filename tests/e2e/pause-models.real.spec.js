const { test, expect } = require('@playwright/test');

test.describe('real pause model administration', () => {
  test.describe.configure({ timeout: 60000, retries: 0 });
  test.skip(process.env.REAL_ACCOUNT_UI !== '1' || process.env.UI_ISOLATED_ACCOUNT_RUNNER !== '1', 'requires the isolated MariaDB UI runner');

  test('an admin creates, previews, versions and assigns a pause model without losing invalid input', async ({ page }) => {
    const userId = process.env.UI_TEST_EMPLOYEE_ID;
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill(process.env.UI_TEST_ADMIN_EMAIL);
    await page.getByLabel('Passwort').fill(process.env.UI_TEST_ADMIN_PASSWORD);
    await Promise.all([page.waitForURL('**/admin'), page.getByRole('button', { name: 'Anmelden', exact: true }).click()]);
    await page.goto('/admin/work-time#pausenmodelle');
    const models = page.locator('#pausenmodelle');
    const template = models.locator('form[action="/admin/work-time/pause-models/templates"]');
    const name = 'Browser Pausenregel 45';
    await template.locator('[name="name"]').fill(name);
    await template.locator('[name="capture_mode"]').selectOption('manual');
    await template.locator('[name="minimum_over_six_minutes"]').fill('45');
    const plan = template.locator('[name="planned_windows"]');
    await plan.fill('12:00-12:30, 12:15-12:45');
    await plan.press('Enter');
    await expect(template.locator('[data-pause-model-output]')).toContainText('überschneiden');
    await expect(plan).toBeFocused();
    await expect(plan).toHaveValue('12:00-12:30, 12:15-12:45');
    await expect(template.locator('[name="name"]')).toHaveValue(name);
    await plan.fill('12:00-12:30');
    await template.getByRole('button', { name: 'Regelversion speichern', exact: true }).click();
    await expect(models.getByRole('status').filter({ hasText: 'Pausenregel gespeichert.' })).toBeVisible();

    const preview = models.locator('form[action="/admin/work-time/pause-models/preview"]');
    await preview.locator('[name="policy_version_id"]').selectOption({ label: name + ' · Version 1' });
    await preview.getByRole('button', { name: 'Beispiel prüfen', exact: true }).click();
    await expect(preview.locator('[data-pause-model-output]')).toContainText('Tatsächliche Arbeit 390 Min.');
    await expect(preview.locator('[data-pause-model-output]')).toContainText('Dokumentierte Pause 0 Min.');
    await expect(preview.locator('[data-pause-model-output]')).toContainText('Pflichtpause 45 Min.');

    await template.locator('[name="template_id"]').selectOption({ label: name });
    await expect(template.locator('[name="planned_windows"]')).toHaveValue('12:00-12:30');
    await template.locator('[name="minimum_over_nine_minutes"]').fill('60');
    await template.getByRole('button', { name: 'Regelversion speichern', exact: true }).click();

    const assignment = models.locator('form[action="/admin/work-time/pause-models/assignments"]');
    await expect(assignment.locator('option').filter({ hasText: name + ' · Version 2' })).toHaveCount(1);
    await assignment.locator('[name="user_id"]').selectOption(userId);
    await assignment.locator('[name="policy_version_id"]').selectOption({ label: name + ' · Version 2' });
    await assignment.getByText('Individuelle Regeln', { exact: true }).click();
    await assignment.locator('[name="override_additional_minutes"]').fill('15');
    await assignment.locator('[name="reason"]').fill('Erprobung einer individuellen betrieblichen Pause');
    await assignment.getByRole('button', { name: 'Zuordnung speichern', exact: true }).click();
    await expect(page).toHaveURL(new RegExp('user_id=' + userId + '#pausenmodelle$'));
    const assigned = models.getByRole('row').filter({ hasText: 'UI Mitarbeiter' }).filter({ hasText: 'Geplant' });
    await expect(assigned).toContainText(name + ' · Version 2');
    await expect(assigned).toContainText('Zusätzlich 15 Min. je Arbeitstag');
    await expect(models.getByRole('row').filter({ hasText: 'Mitarbeiterzuordnung angelegt' })).toContainText('Erprobung einer individuellen betrieblichen Pause');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForLoadState('load');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

    await page.goto('/admin/users/' + userId + '/edit');
    await expect(page.getByRole('link', { name: 'Pausenregeln des Mitarbeiters', exact: true })).toHaveAttribute('href', '/admin/work-time?user_id=' + userId + '#pausenmodelle');
  });
});
