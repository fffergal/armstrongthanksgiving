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
