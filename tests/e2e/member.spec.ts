import { expect, test } from '@playwright/test';
import path from 'node:path';
import { logIn } from './helpers/auth';

test('a signed-in friend can select and confirm a shared photo', async ({ page }) => {
  await logIn(page);
  await page.goto('/albums/');

  // Open the album's upload form without activating its file-picker button.
  await page.locator('.wppa-upload-cover').filter({ hasText: 'Upload photo' }).first().click();
  const photoInput = page.locator('.wppa-container input[type="file"]');
  await photoInput.setInputFiles(path.resolve('wp-content/plugins/armstrong-gathering/assets/avatars/turkey-24.png'));

  const confirmUpload = page.locator('.wppa-container input.wppa-user-submit');
  await expect(confirmUpload).toBeVisible();
  await expect(confirmUpload).toHaveValue('Share photo');
  await expect(page.getByRole('status')).toContainText('1 photo selected: turkey-24.png');
  if (process.env.ALBUM_UPLOAD_SCREENSHOT) {
    await page.screenshot({ path: process.env.ALBUM_UPLOAD_SCREENSHOT, fullPage: true });
  }

  await confirmUpload.click();
  await expect(page.locator('.wppa-container .wppa-percent')).toHaveText('Done!');
  await expect(page.locator('.wppa-container .wppa-message')).not.toContainText('Upload failed');
  await expect(page.locator('.wppa-container .wppa-message')).toContainText('1 photo successfully uploaded');
  await expect(page.getByRole('status')).toHaveText('Photo uploaded successfully. Refresh to see it in the shared album.');
});

test('a signed-in friend can use the gathering pages', async ({ page }) => {
  await logIn(page);

  const pages = [
    ['/', 'Thanksgiving'],
    ['/rsvp/', 'Will you join us?'],
    ['/albums/', 'Shared Albums'],
    ['/forum/', 'The Gathering'],
    ['/community/topic/say-hello/', 'Say hello']
  ] as const;

  for (const [path, heading] of pages) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBeLessThan(500);
    await expect(page.getByRole('heading', { name: heading, exact: false })).toBeVisible();
  }

  await page.goto('/albums/');
  await expect(page.locator('.wppa-upload-cover, .wppa-upload-uploadbox').first()).toBeVisible();
  await expect(page.locator('.wppa-upload-cover:visible, .wppa-upload-uploadbox:visible')).toHaveCount(1);

  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible();
  await expect(page.locator('.at-date-card')).toContainText('12 December 2026');
  await expect(page.locator('.at-date-card')).toContainText('6:42 pm');
  await expect(page.locator('.at-date-card .at-event-address')).toHaveCount(0);
  await expect(page.locator('.at-event-address')).toContainText('123 Example Lane, Testville');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await expect(page.locator('.at-event-address')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  await page.goto('/forum/');
  expect(await page.locator('img.avatar[src*="turkey-"]').count()).toBeGreaterThan(0);

  await page.goto('/randomblah-signed-in-404');
  await expect(page.locator('body')).toHaveClass(/error404/);
  await expect(page.getByRole('heading', { name: 'That page wandered off.' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible();
});
