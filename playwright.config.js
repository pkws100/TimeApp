const { defineConfig } = require('@playwright/test');
const fs = require('fs');

const configuredChromium = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const chromiumExecutablePath = configuredChromium
  || (fs.existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  expect: {
    timeout: 5000
  },
  reporter: [['list']],
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.UI_BASE_URL || 'http://127.0.0.1',
    // App fixtures and server-side work dates use the company's Berlin timezone.
    timezoneId: 'Europe/Berlin',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off'
  },
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
        viewport: { width: 390, height: 844 },
        launchOptions: {
          ...(chromiumExecutablePath ? { executablePath: chromiumExecutablePath } : {}),
          args: ['--no-sandbox', '--disable-dev-shm-usage']
        }
      }
    }
  ]
});
