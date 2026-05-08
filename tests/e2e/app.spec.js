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
