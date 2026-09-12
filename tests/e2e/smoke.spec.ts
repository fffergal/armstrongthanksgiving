import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('public entry point renders without browser errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', error => errors.push(error.message));

  const response = await page.goto('/');
  expect(response?.status()).toBeLessThan(500);
  await expect(page.locator('body')).toBeVisible();
  expect(errors).toEqual([]);
});

test('hero keeps long words intact at tablet width', async ({ page }) => {
  await page.setViewportSize({ width: 1194, height: 834 });
  await logIn(page);
  await page.goto('/');
  const word = page.locator('.at-hero h1 > span');
  await expect(word).toHaveCSS('white-space', 'nowrap');
  expect(await word.evaluate(element => element.getClientRects().length)).toBe(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
});

test('front page keeps the navigation and content focused', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Memories' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Food', exact: true })).toHaveCount(0);
  await expect(page.locator('.at-wordmark')).toBeHidden();
  await expect(page.locator('body')).not.toContainText('armstrongthanksgiving.com');
  await expect(page.locator('.at-hero')).not.toContainText('Priestfield');
  await expect(page.getByRole('link', { name: 'Open the forum' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'RSVP' }).first()).toBeVisible();
  await expect(page.locator('.at-cards article').nth(0)).toContainText('Photos');
  await expect(page.locator('.at-cards article').nth(1)).toContainText('Forum');
  await expect(page.locator('.at-cards article').nth(2)).toContainText('RSVP');
  await expect(page.locator('.at-hero h1')).toHaveCSS('font-family', /Georgia/i);
  await expect(page.getByRole('link', { name: 'Sign out' })).toHaveCount(0);
});

test('front page has no horizontal overflow on phone and tablet', async ({ page }) => {
  await logIn(page);
  for (const viewport of [{ width: 375, height: 812 }, { width: 768, height: 1024 }]) {
    await page.setViewportSize(viewport);
    await page.goto('/');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), viewport.width.toString()).toBeLessThanOrEqual(0);
  }
});

test('private routes do not disclose content anonymously', async ({ request }) => {
  for (const path of ['/forum/', '/albums/', '/community/topic/say-hello/', '/wp-json/wp/v2/posts']) {
    const response = await request.get(path, { maxRedirects: 0 });
    expect([301, 302, 303, 307, 308, 401, 403, 404]).toContain(response.status());
  }
});

test('the removed food route points visitors to the RSVP', async ({ request }) => {
  const response = await request.get('/food/', { maxRedirects: 0 });
  expect(response.status()).toBe(301);
  expect(response.headers().location).toMatch(/\/rsvp\/$/);
});

test('unknown public routes explain what to do next', async ({ page, request }) => {
  const response = await request.get('/randomblah-404', { maxRedirects: 0 });
  expect(response.status()).toBe(404);
  await page.goto('/randomblah-404');
  await expect(page.locator('body')).toHaveClass(/error404/);
  await expect(page.getByRole('heading', { name: 'That page wandered off.' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Go to home page' })).toHaveAttribute('href', /\/$/);
});
