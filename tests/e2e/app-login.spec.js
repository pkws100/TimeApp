const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const projectRoot = path.join(__dirname, '../..');
const appShell = execFileSync('php', ['-r', 'require "bootstrap/autoload.php"; echo (new \\App\\Presentation\\App\\AppView("Login Test"))->render("/app/login", ["app_name"=>"Login Test"]);'], { cwd: projectRoot, encoding: 'utf8' });

async function mockLogin(page, accepted = false) {
  const submissions = [];
  const user = { id: 7, display_name: 'UI Login', email: 'login-regression@example.test', permissions: [], roles: [], time_tracking_required: false };
  await page.route('**/*', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const json = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    if (pathname === '/assets/js/app.js' || pathname === '/assets/css/app.css') return route.fulfill({ contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(projectRoot, 'public', pathname)) });
    if (pathname === '/app/login') return route.fulfill({ contentType: 'text/html', body: appShell });
    if (pathname === '/api/v1/auth/session') return json({ authenticated: false, user: null, bootstrap_required: false });
    if (pathname === '/api/v1/auth/login' && request.method() === 'POST') {
      submissions.push(request.postDataJSON());
      if (accepted) return json({ ok: true, message: 'Login erfolgreich.', session: { authenticated: true, user } });
      return json({ ok: false, message: 'Test: Anmeldedaten bitte pruefen.' }, 401);
    }
    if (pathname === '/api/v1/app/me/day') return json({ data: { today: '2026-11-16', user, projects: [], today_state: { status: 'not_started', is_missing: false, booking_required: false, work_entry: null, status_entry: null, current_break: null }, project_day_summaries: [], attachments: [], breaks_today: [], current_break: null, company: {}, geo_policy: { enabled: false }, personnel_events: [], personnel_labels: [] } });
    if (pathname === '/api/v1/settings/company') return json({ data: {} });
    if (pathname === '/api/v1/app/push/status') return json({ data: { enabled: false, can_subscribe: false, devices: [] } });
    return route.fulfill({ status: 204, body: '' });
  });
  return submissions;
}

test('an install prompt preserves an edited login form and submits its actual current values once', async ({ page }) => {
  const submissions = await mockLogin(page);
  await page.goto('/app/login');
  const form = page.locator('#loginForm');
  const email = form.locator('[name="email"]');
  const password = form.locator('[name="password"]');
  await email.fill('login-regression@example.test');
  await password.fill('Synthetic-Login-Only-123');
  const originalForm = await form.elementHandle();
  const originalPassword = await password.elementHandle();
  await password.focus();
  for (let event = 0; event < 2; event += 1) {
    await page.evaluate(() => window.dispatchEvent(new Event('beforeinstallprompt', { cancelable: true })));
    expect(await originalForm.evaluate((element) => element === document.getElementById('loginForm'))).toBeTruthy();
    expect(await originalPassword.evaluate((element) => element === document.querySelector('#loginForm [name="password"]'))).toBeTruthy();
    await expect(email).toHaveValue('login-regression@example.test');
    await expect(password).toHaveValue('Synthetic-Login-Only-123');
    await expect(password).toBeFocused();
  }
  const loginResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/auth/login');
  await form.getByRole('button', { name: 'Anmelden', exact: true }).click();
  expect((await loginResponse).status()).toBe(401);
  await expect(page.locator('.app-feedback-error')).toContainText('Test: Anmeldedaten bitte pruefen.');
  expect(submissions).toEqual([{ email: 'login-regression@example.test', password: 'Synthetic-Login-Only-123' }]);
  await expect(password).toHaveValue('Synthetic-Login-Only-123');
  expect(await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }).includes('Synthetic-Login-Only-123'))).toBeFalsy();
});

test('a successful login replaces the preserved password node at the authentication boundary', async ({ page }) => {
  const submissions = await mockLogin(page, true);
  await page.goto('/app/login');
  const form = page.locator('#loginForm');
  await form.locator('[name="email"]').fill('login-regression@example.test');
  await form.locator('[name="password"]').fill('Synthetic-Login-Only-123');
  const password = await form.locator('[name="password"]').elementHandle();
  await page.evaluate(() => window.dispatchEvent(new Event('beforeinstallprompt', { cancelable: true })));
  const loginResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/auth/login');
  await form.getByRole('button', { name: 'Anmelden', exact: true }).click();
  expect((await loginResponse).ok()).toBeTruthy();
  await expect(page).toHaveURL(/\/app\/heute$/);
  await expect(form).toHaveCount(0);
  expect(await password.evaluate((element) => element.isConnected)).toBeFalsy();
  expect(submissions).toEqual([{ email: 'login-regression@example.test', password: 'Synthetic-Login-Only-123' }]);
});
