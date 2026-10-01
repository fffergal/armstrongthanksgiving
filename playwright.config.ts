import 'dotenv/config';
import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const runtimeFile = path.resolve('.worktree/runtime.json');
let runtime: { url?: string } = {};
try {
  runtime = JSON.parse(fs.readFileSync(runtimeFile, 'utf8')) as { url?: string };
} catch {
  // The environment has not started yet; retain the default URL for diagnostics.
}
const configuredBaseURL = process.env.BASE_URL?.trim();
const baseURL = process.env.USE_WORKTREE_RUNTIME === '0'
  ? configuredBaseURL || 'http://localhost:8888'
  : (runtime.url || (configuredBaseURL && configuredBaseURL !== 'http://localhost:8888' ? configuredBaseURL : 'http://localhost:8888'));
const chromiumLaunchOptions = {
  launchOptions: {
    // The login page is intentionally exercised with known fixture
    // credentials. Prevent Chromium's password manager from carrying a
    // saved value between projects and overwriting the test fields.
    args: ['--disable-save-password-bubble', '--disable-features=PasswordManagerOnboarding,AutofillServerCommunication']
  }
};

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
    navigationTimeout: 30_000
  },
  projects: [
    { name: 'desktop-chromium', testMatch: /smoke\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'mobile-safari', testMatch: /smoke\.spec\.ts/, use: { ...devices['iPhone 13'] } },
    { name: 'login', testMatch: /login\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'accessibility', testMatch: /a11y\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'visual', testMatch: /visual\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'member', testMatch: /member\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'rsvp', testMatch: /rsvp\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...chromiumLaunchOptions } },
    { name: 'rsvp-mobile', testMatch: /rsvp\.spec\.ts/, use: { ...devices['iPhone 13'] } }
  ]
});
