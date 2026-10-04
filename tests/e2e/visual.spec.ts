import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('entry page visual contract', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
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
  await expect(page).toHaveScreenshot('rsvp-desktop.png', { fullPage: true, mask: [page.locator('.at-food-list small')] });
});

test('public signup and RSVP visual contract on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/rsvp/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('rsvp-signup-mobile.png', { fullPage: true, mask: [page.locator('.at-food-list small')] });
});

test('RSVP visual contract on iPad', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  await logIn(page);
  // The preceding public mobile test can leave a guest RSVP page in the local
  // page cache. A query string makes this signed-in screenshot load fresh HTML.
  await page.goto('/rsvp/?visual-test=ipad');
  await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('rsvp-ipad.png', { fullPage: true, mask: [page.locator('.at-food-list small')] });
});
