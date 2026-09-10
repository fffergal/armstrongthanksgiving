import { expect, test } from '@playwright/test';

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

test('private routes do not disclose content anonymously', async ({ request }) => {
  for (const path of ['/forums/', '/albums/', '/wp-json/wp/v2/posts']) {
    const response = await request.get(path, { maxRedirects: 0 });
    expect([301, 302, 303, 307, 308, 401, 403, 404]).toContain(response.status());
  }
});

