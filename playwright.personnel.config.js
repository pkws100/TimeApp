const { defineConfig } = require('@playwright/test');
const base = require('./playwright.config');

module.exports = defineConfig({
  ...base,
  testIgnore: [],
  testMatch: '**/personnel-admin*.real.spec.js',
  timeout: 60000,
  retries: 0,
  workers: 1,
  use: {
    ...base.use,
    acceptDownloads: true,
    trace: 'retain-on-failure'
  },
  projects: [
    {
      ...base.projects[0],
      use: { ...base.projects[0].use, viewport: { width: 1440, height: 900 } }
    },
    {
      name: 'webkit',
      use: {
        browserName: 'webkit',
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 2
      }
    }
  ]
});
