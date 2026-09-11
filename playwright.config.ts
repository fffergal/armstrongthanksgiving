import 'dotenv/config';
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL ?? 'http://localhost:8888';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: 'test-results',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : 1,
  reporter: [['html', { open: 'never' }], ['list']],
  expect: { timeout: 10_000, toHaveScreenshot: { animations: 'disabled' } },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
    launchOptions: {
      // The login page is intentionally exercised with known fixture
      // credentials. Prevent Chromium's password manager from carrying a
      // saved value between projects and overwriting the test fields.
      args: ['--disable-save-password-bubble', '--disable-features=PasswordManagerOnboarding,AutofillServerCommunication']
    }
  },
  projects: [
    { name: 'desktop-chromium', testMatch: /smoke\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-safari', testMatch: /smoke\.spec\.ts/, use: { ...devices['iPhone 13'] } },
    { name: 'login', testMatch: /login\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'accessibility', testMatch: /a11y\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'visual', testMatch: /visual\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'member', testMatch: /member\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'rsvp', testMatch: /rsvp\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'rsvp-mobile', testMatch: /rsvp\.spec\.ts/, use: { ...devices['iPhone 13'] } }
  ]
});
