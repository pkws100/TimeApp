const { defineConfig } = require('@playwright/test');
const base = require('./playwright.config');

// Keep the complete Chromium suite; this adds targeted iOS-engine coverage.
module.exports = defineConfig({
  ...base,
  testMatch: process.env.REAL_ACCOUNT_UI === '1' && process.env.UI_ISOLATED_ACCOUNT_RUNNER === '1'
    ? '**/*.real.spec.js'
    : ['**/project-material-drafts.spec.js', '**/pause-controls.spec.js', '**/app-appointments.spec.js'],
  projects: [
    {
      name: 'mobile-webkit',
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
