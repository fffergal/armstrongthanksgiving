import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

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
