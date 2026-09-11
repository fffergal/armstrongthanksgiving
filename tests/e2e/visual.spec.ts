import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('entry page visual contract', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('entry-page.png', { fullPage: true });
});

test('member home visual contract', async ({ page }) => {
  await logIn(page);
  await page.goto('/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('member-home.png', { fullPage: true });
});

test('RSVP visual contract on desktop', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('rsvp-desktop.png', { fullPage: true });
});

test('invited signup and RSVP visual contract on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/rsvp/?invite=test-invite');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('rsvp-signup-mobile.png', { fullPage: true });
});

test('RSVP visual contract on iPad', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  await logIn(page);
  await page.goto('/rsvp/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('rsvp-ipad.png', { fullPage: true });
});
