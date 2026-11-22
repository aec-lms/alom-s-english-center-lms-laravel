const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.js',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    launchOptions: {
      args: ['--host-resolver-rules=MAP lms.e2e.test 127.0.0.1,MAP player.e2e.test 127.0.0.1,MAP content.e2e.test 127.0.0.1'],
    },
    baseURL: process.env.APP_URL || 'http://127.0.0.1:8000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    cwd: process.env.GITHUB_WORKSPACE ? `${process.env.GITHUB_WORKSPACE}/laravel` : process.cwd(),
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    url: 'http://127.0.0.1:8000/login',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
});
