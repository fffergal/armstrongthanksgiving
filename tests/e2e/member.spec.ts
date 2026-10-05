import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { logIn } from './helpers/auth';

function isWorktreeWordPressTarget(baseURL: string | undefined) {
  if (!baseURL) return false;

  try {
    const runtime = JSON.parse(readFileSync(path.resolve('.worktree/runtime.json'), 'utf8'));
    return new URL(baseURL).origin === new URL(runtime.url).origin;
  } catch {
    return false;
  }
}

test('a signed-in friend can select and confirm a shared photo', async ({ page }, testInfo) => {
  test.skip(
    !isWorktreeWordPressTarget(testInfo.project.use.baseURL),
    'Photo uploads change album contents, so this test only runs against this worktree’s disposable WordPress site.'
  );

  await logIn(page);
  await page.goto('/albums/');

  const albumPhotoCount = page.getByText(/View\s+\d+\s+photos?/i).first();
  const albumLabel = await albumPhotoCount.count() ? await albumPhotoCount.textContent() : null;
  const photosBefore = Number(albumLabel?.match(/\d+/)?.[0] ?? 0);

  // Open the album's upload form without activating its file-picker button.
  await page.locator('.wppa-upload-cover').filter({ hasText: 'Upload photo' }).first().click();
  const photoInput = page.locator('.wppa-container input[type="file"]');
  await photoInput.setInputFiles(path.resolve('wp-content/plugins/armstrong-gathering/assets/avatars/turkey-24.png'));

  const confirmUpload = page.locator('.wppa-container input.wppa-user-submit');
  const choosePhotos = page.locator('.wppa-container input[id$="-display"]');
  await expect(confirmUpload).toBeVisible();
  await expect(confirmUpload).toHaveValue('Share photo');
  await expect(choosePhotos).toHaveValue('Choose photos');
  await expect(page.getByRole('status')).toContainText('1 photo selected: turkey-24.png');

  const progress = page.locator('.wppa-container .wppa-percent');
  const originalViewport = page.viewportSize();
  const screenshots = [
    ['desktop', process.env.ALBUM_UPLOAD_SCREENSHOT_DESKTOP, { width: 1280, height: 900 }],
    ['tablet', process.env.ALBUM_UPLOAD_SCREENSHOT_TABLET, { width: 768, height: 1024 }],
    ['mobile', process.env.ALBUM_UPLOAD_SCREENSHOT_MOBILE, { width: 390, height: 844 }]
  ] as const;
  for (const [, screenshotPath, viewport] of screenshots) {
    if (!screenshotPath) continue;
    await page.setViewportSize(viewport);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    await page.screenshot({ path: screenshotPath, fullPage: true });
  }
  if (originalViewport) await page.setViewportSize(originalViewport);

  // WPPA briefly says "Done!" before its response callback confirms success.
  // That progress label alone must never announce a completed upload.
  await progress.evaluate(element => { element.textContent = 'Done!'; });
  await expect(page.getByRole('status')).toContainText('1 photo selected: turkey-24.png');
  await progress.evaluate(element => { element.textContent = ''; });

  await confirmUpload.click();
  await expect(progress).toHaveText('Done!');
  await expect(page.locator('.wppa-container .wppa-message')).not.toContainText('Upload failed');
  await expect(page.locator('.wppa-container .wppa-message')).toContainText('1 photo successfully uploaded');
  await expect(page.getByRole('status')).toHaveText('Photo uploaded successfully. Refresh to see it in the shared album.');

  const uploadMessage = page.locator('.wppa-container .wppa-message');
  await uploadMessage.evaluate(element => {
    element.textContent = 'Upload failed.';
  });
  await expect(page.getByRole('status')).toHaveText('The photo could not be uploaded. Please try again.');

  await uploadMessage.evaluate(element => {
    element.textContent = '1 photo successfully uploaded. 1 upload failed.';
  });
  await expect(page.getByRole('status')).toHaveText('Some photos uploaded, but others could not be shared. Please review the upload results.');

  await page.reload();
  await expect(page.getByText(new RegExp(`View\\s+${photosBefore + 1}\\s+photos?`, 'i')).first()).toBeVisible();
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
  await expect(page.getByRole('link', { name: 'Sign up without RSVP' })).toHaveAttribute('href', /\/signup\/$/);
  await expect(page.locator('#wp--skip-link--target').getByRole('link', { name: 'RSVP', exact: true })).toHaveAttribute('href', /\/rsvp\/$/);
  if (process.env.SIGNED_IN_HOME_NO_RSVP_SCREENSHOT) {
    await page.screenshot({ path: process.env.SIGNED_IN_HOME_NO_RSVP_SCREENSHOT, fullPage: true });
  }
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
