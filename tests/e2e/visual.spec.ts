import { expect, test } from '@playwright/test';

test('entry page visual contract', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot('entry-page.png', { fullPage: true });
});

