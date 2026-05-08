const { defineConfig } = require('@playwright/test');

const chromiumExecutablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '/usr/bin/chromium';

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  expect: {
    timeout: 5000
  },
  reporter: [['list']],
  use: {
    baseURL: process.env.UI_BASE_URL || 'http://127.0.0.1',
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
          executablePath: chromiumExecutablePath,
          args: ['--no-sandbox', '--disable-dev-shm-usage']
        }
      }
    }
  ]
});
