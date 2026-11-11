const { defineConfig } = require('@playwright/test');
const base = require('./playwright.config');

// Keep the complete Chromium suite; this adds targeted iOS-engine coverage.
module.exports = defineConfig({
  ...base,
  testMatch: '**/project-material-drafts.spec.js',
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
